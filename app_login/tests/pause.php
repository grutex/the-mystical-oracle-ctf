<?php
// Executar somente em um banco descartável, separado do evento.
declare(strict_types=1);
if (getenv('DB_NAME') !== 'MYSTICAL_ORACLE_PAUSE_TEST') exit("Use o banco descartável MYSTICAL_ORACLE_PAUSE_TEST.\n");
require '/var/www/html/api/lib.php';
require getenv('TEST_CHALLENGE_AUTH') ?: '/workspace/infrastructure/challenge/challenge-auth.php';
$schema = str_replace('MYSTICAL_ORACLE_LOGIN_DB', 'MYSTICAL_ORACLE_PAUSE_TEST', file_get_contents('/workspace/app_login/db/init.sql'));
db()->exec($schema);
query('DELETE FROM LeaderboardSnapshots');
query('DELETE FROM Account WHERE username LIKE ?', ['pause-%']);
query('UPDATE ContestState SET started_at = NULL, paused_at = NULL, paused_seconds = 0, session_epoch = 0, ends_at = NULL, closed_at = NULL WHERE id = 1');
if (!is_dir(getenv('SESSION_PATH'))) mkdir(getenv('SESSION_PATH'), 0777, true);
foreach ([['pause-admin', 'admin', 0], ['pause-player', 'player', 0], ['pause-test', 'player', 1]] as [$user, $role, $ignore]) {
    query('INSERT INTO Account (username, alias, email, password_hash, acc_role, ignore_leaderboard) VALUES (?, ?, ?, ?, ?, ?)',
        [$user, $user, $user . '@example.test', password_hash('PauseTest123!', PASSWORD_DEFAULT), $role, $ignore]);
}
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "OK: $message\n";
}
function request(array &$client, string $action, ?array $data = null): array {
    $headers = ['Content-Type: application/json'];
    if (isset($client['cookie'])) $headers[] = 'Cookie: ORACLESESS=' . $client['cookie'];
    if (isset($client['csrf'])) $headers[] = 'X-CSRF-Token: ' . $client['csrf'];
    $context = stream_context_create(['http' => ['method' => $data === null ? 'GET' : 'POST',
        'header' => implode("\r\n", $headers), 'content' => $data === null ? '' : json_encode($data),
        'ignore_errors' => true, 'follow_location' => 0]]);
    $raw = file_get_contents('http://127.0.0.1:8099/api/api.php?action=' . $action, false, $context);
    foreach ($http_response_header as $header) {
        if (preg_match('/^Set-Cookie: ORACLESESS=([^;]+)/i', $header, $m)) $client['cookie'] = $m[1];
    }
    $result = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (isset($result['csrf'])) $client['csrf'] = $result['csrf'];
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $code);
    return ['code' => (int)$code[1], 'data' => $result];
}
function login(array &$client, string $user): array {
    request($client, 'bootstrap');
    return request($client, 'login', ['email' => $user . '@example.test', 'password' => 'PauseTest123!']);
}
$server = proc_open(['php', '-S', '127.0.0.1:8099', '-t', '/var/www/html'], [0 => ['file', '/dev/null', 'r'],
    1 => ['file', '/tmp/pause-server.log', 'a'], 2 => ['file', '/tmp/pause-server.log', 'a']], $pipes);
try {
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', 8099);
        if ($socket) { fclose($socket); break; }
        usleep(100000);
    }
    $admin = $player = $test = [];
    check(login($admin, 'pause-admin')['code'] === 200, 'admin entra antes do início');
    check(login($player, 'pause-player')['code'] === 409, 'jogador bloqueado antes do início');
    check(login($test, 'pause-test')['code'] === 200, 'conta de teste entra antes do início');
    check(request($admin, 'start', [])['code'] === 200, 'administrador inicia o CTF');
    check(login($player, 'pause-player')['code'] === 200, 'jogador entra após o início');
    $uid = (int)query('SELECT acc_id FROM Account WHERE username = ?', ['pause-player'])->fetchColumn();
    $identity = ['uid' => $uid, 'sid' => $player['cookie']];
    check(challenge_portal_session_active($identity), 'sessão permite entrar nos desafios');
    check(request($test, 'pause', [])['code'] === 403, 'conta de teste não pode pausar');
    check(request($admin, 'pause', [])['code'] === 200, 'administrador pausa o CTF');
    check(!challenge_portal_session_active($identity), 'pausa invalida o acesso aos desafios');
    check(request($player, 'submit', ['challenge' => 'spirit', 'flag' => 'ORACLE{teste}'])['code'] === 401, 'sessão anterior não submete flags');
    check(login($player, 'pause-player')['code'] === 409, 'login de jogador bloqueado durante pausa');
    check(request($test, 'me')['code'] === 200, 'conta de teste mantém sessão');
    check(request($test, 'submit', ['challenge' => 'spirit', 'flag' => 'ORACLE{teste}'])['code'] === 200, 'conta de teste pode submeter durante pausa');
    $newTest = [];
    check(login($newTest, 'pause-test')['code'] === 200, 'conta de teste consegue novo login na pausa');
    $before = request($admin, 'status')['data']['contest']['elapsed_seconds'];
    usleep(1100000);
    check(request($admin, 'status')['data']['contest']['elapsed_seconds'] === $before, 'contador congelado durante pausa');
    check(request($admin, 'resume', [])['code'] === 200, 'administrador retoma o CTF');
    check(!challenge_portal_session_active($identity), 'sessão antiga continua inválida após retomada');
    check(login($player, 'pause-player')['code'] === 200, 'jogador pode entrar novamente após retomada');
    usleep(1100000);
    check(request($admin, 'status')['data']['contest']['elapsed_seconds'] > $before, 'contador volta a avançar');
    check(request($admin, 'status')['data']['is_admin'] === true, 'ajuda habilitada para administrador');
    check(request($test, 'status')['data']['is_admin'] === false, 'ajuda não habilitada para conta de teste');
    check(request($player, 'submit', ['challenge' => 'spirit', 'flag' => personalFlag('spirit', $uid)])['data']['correct'] === true, 'flag resolvida antes do reset');
    query('UPDATE Account SET questions_count = 7 WHERE acc_id = ?', [$uid]);
    $accountsBefore = (int)query('SELECT COUNT(*) FROM Account')->fetchColumn();
    $attemptsBefore = (int)query('SELECT COUNT(*) FROM FlagSubmissions')->fetchColumn();
    check(request($admin, 'close', [])['code'] === 200, 'primeira rodada encerrada');
    $historyBefore = (int)query('SELECT COUNT(*) FROM LeaderboardSnapshots')->fetchColumn();
    check(request($test, 'reset_contest', [])['code'] === 403, 'conta de teste não pode resetar o CTF');
    $reset = request($admin, 'reset_contest', []);
    check($reset['code'] === 200, 'admin reseta evento encerrado');
    check($reset['data']['contest']['started_at'] === null && $reset['data']['contest']['elapsed_seconds'] === 0
        && $reset['data']['contest']['closed_at'] === null && $reset['data']['contest']['ends_at'] === null,
        'estado do evento e contador zerados');
    check((int)query('SELECT COUNT(*) FROM ChallengeSolves')->fetchColumn() === 0, 'flags de todas as contas removidas');
    check((int)query('SELECT COALESCE(SUM(score), 0) FROM CtfInfo')->fetchColumn() === 0, 'pontuação zerada');
    check((int)query('SELECT COUNT(*) FROM Account')->fetchColumn() === $accountsBefore, 'contas preservadas');
    check((int)query('SELECT questions_count FROM Account WHERE acc_id = ?', [$uid])->fetchColumn() === 7, 'outros dados da conta preservados');
    check((int)query('SELECT COUNT(*) FROM FlagSubmissions')->fetchColumn() === $attemptsBefore, 'tentativas preservadas');
    check((int)query('SELECT COUNT(*) FROM LeaderboardSnapshots')->fetchColumn() === $historyBefore, 'histórico preservado');
    check(request($admin, 'snapshots')['data']['snapshots'][0]['final'] === true, 'classificação final anterior mantém identificação no histórico');
    check(request($player, 'me')['code'] === 401, 'reset invalida sessão de jogador');
    check(login($player, 'pause-player')['code'] === 409, 'jogador aguarda novo início');
    check(request($admin, 'start', [])['code'] === 200, 'iniciar disponível após reset');
    check(login($player, 'pause-player')['code'] === 200, 'jogador entra na nova rodada');
    check(request($admin, 'close', [])['code'] === 200, 'nova rodada consegue salvar classificação final');
    echo "Testes de pausa e reset concluídos.\n";
} finally {
    proc_terminate($server);
    proc_close($server);
}
