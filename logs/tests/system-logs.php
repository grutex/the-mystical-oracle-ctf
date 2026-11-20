<?php
declare(strict_types=1);
// Run in a disposable Compose project. Creates/removes its own fixtures only.
require '/var/www/html/api/lib.php';
function ensure(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
final class LogClient {
    public array $cookies = [];
    public string $csrf = '';
    public function request(string $path, ?array $data = null, int $expected = 200, string $service = 'portal-web'): array {
        $headers = ['Cookie: ' . http_build_query($this->cookies, '', '; '), 'X-Real-IP: 203.0.113.42'];
        if ($data !== null) { $headers[] = 'Content-Type: application/json'; $headers[] = 'X-CSRF-Token: ' . $this->csrf; }
        $body = file_get_contents('http://' . $service . $path, false, stream_context_create(['http' => [
            'method' => $data === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers), 'content' => $data === null ? '' : json_encode($data),
            'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]]));
        preg_match('/\s(\d{3})\s/', $http_response_header[0], $status);
        ensure((int)$status[1] === $expected, "$path: HTTP {$status[1]} $body");
        foreach ($http_response_header as $header) {
            if (preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i', $header, $cookie)) $this->cookies[$cookie[1]] = $cookie[2];
            if (str_starts_with($header, 'Location: ')) $location = substr($header, 10);
        }
        $result = json_decode((string)$body, true) ?? [];
        $this->csrf = $result['csrf'] ?? $this->csrf;
        return $result + ['body' => $body, 'location' => $location ?? ''];
    }
    public function call(string $action, ?array $data = null, int $status = 200): array { return $this->request('/api/api.php?action=' . $action, $data, $status); }
}
$ids = []; $clients = []; $suffix = bin2hex(random_bytes(4));
try {
    foreach (['admin' => 'admin', 'test' => 'player', 'control' => 'player'] as $label => $role) {
        $name = 'audit_' . $label . '_' . $suffix;
        query('INSERT INTO Account (username, alias, email, password_hash, acc_role, ignore_leaderboard) VALUES (?, ?, ?, ?, ?, 1)', [$name, $name, $name . '@example.test', password_hash('Audit!Fixture492', PASSWORD_DEFAULT), $role]);
        $ids[$label] = (int)db()->lastInsertId();
        query('INSERT INTO CtfInfo (user_id) VALUES (?)', [$ids[$label]]);
        $clients[$label] = $client = new LogClient(); $client->call('bootstrap');
        $client->call('login', ['email' => $name . '@example.test', 'password' => 'Audit!Fixture492']);
    }
    $admin = $clients['admin']; $player = $clients['test']; $uid = $ids['test'];
    $anonymous = new LogClient(); $anonymous->request('/logs', null, 401);
    foreach (['logs_page', 'logs', 'log_accounts', 'accounts', 'accounts_page'] as $action) $player->call($action, null, 403);
    $player->call('ban_account', ['user_id' => $ids['control']], 403);
    $player->call('read_account_logs', ['user_id' => $uid, 'last_log_id' => 0], 403);
    ensure(str_contains($admin->request('/logs')['body'], 'Logs do Sistema'), 'Protected logs HTML absent');
    $anonymous->request('/logs.html', null, 404);
    $anonymous->request('/private/logs.html', null, 404);
    $anonymous->request('/private/logs/logs.html', null, 404);
    $anonymous->request('/logs/lib.php', null, 404);
    $anonymous->request('/logs/backend/logs.html', null, 404);
    ensure(str_contains($admin->request('/logs/logs.js')['body'], 'renderLogs'), 'Moved JavaScript missing');
    ensure(str_contains($admin->request('/logs/logs.css')['body'], '.log-entry.success'), 'Moved stylesheet missing');
    $player->call('submit', ['challenge' => 'spirit', 'flag' => 'bad-format'], 400);
    for ($i = 0; $i < 10; $i++) $player->call('submit', ['challenge' => 'spirit', 'flag' => 'ORACLE{wrong}']);
    $flag = personalFlag('spirit', $uid);
    ensure($player->call('submit', ['challenge' => 'spirit', 'flag' => $flag])['correct'], 'Correct test flag rejected');
    $result = $admin->request('/api/api.php?action=logs&kind=account&user_id=' . $uid);
    $success = array_values(array_filter($result['logs'], fn($l) => $l['event_type'] === 'flag_submit' && $l['success']));
    ensure(count($success) === 1 && $success[0]['submitted_flag'] === $flag && $success[0]['email'] !== null && $success[0]['ip_address'] === '203.0.113.42', 'Flag logging missing identity, flag or IP');
    ensure(count(array_filter($result['logs'], fn($l) => $l['event_type'] === 'flag_submit' && !$l['success'])) === 11, 'Rejected attempts missing');
    ensure(!str_contains(json_encode($result), 'password_hash'), 'Password hash leaked');
    $getAccount = function () use ($admin, $uid): array { foreach ($admin->call('log_accounts')['accounts'] as $a) if ($a['id'] === $uid) return $a; throw new RuntimeException('Missing account'); };
    $a = $getAccount(); ensure($a['unread'] > 0, 'Unread count absent');
    $admin->call('read_account_logs', ['user_id' => $uid, 'last_log_id' => $result['logs'][0]['log_id']]);
    ensure($getAccount()['unread'] === 0, 'Opening popup did not mark current logs read');
    $player->call('submit', ['challenge' => 'air', 'flag' => 'ORACLE{incorrect}']);
    ensure($getAccount()['unread'] === 1, 'Unread delta wrong');
    $warnings = $admin->request('/api/api.php?action=logs&kind=warnings')['logs'];
    ensure(count(array_filter($warnings, fn($l) => (int)$l['user_id'] === $uid && str_contains($l['warning'], 'falhas de submissão'))) === 1, 'Flag burst warning missing');
    ensure(count(array_filter($warnings, fn($l) => (int)$l['user_id'] === $uid && str_contains($l['warning'], 'administrativo'))) > 0, 'Permission warnings missing');
    // Historical pagination must not duplicate records.
    $actor = query('SELECT * FROM Account WHERE acc_id = ?', [$uid])->fetch();
    for ($i = 0; $i < 105; $i++) systemLog($actor, 'fixture', null, 'Pagination fixture');
    $page = $admin->request('/api/api.php?action=logs&kind=account&user_id=' . $uid);
    ensure(count($page['logs']) === 100 && $page['has_more'], 'Pagination limit absent');
    $older = $admin->request('/api/api.php?action=logs&kind=account&user_id=' . $uid . '&before=' . $page['logs'][99]['log_id']);
    ensure($older['logs'][0]['log_id'] < $page['logs'][99]['log_id'], 'Pagination duplicates');
    // Two portal sessions + a signed challenge ticket are all revoked by a ban.
    $second = new LogClient(); $second->call('bootstrap');
    $second->call('login', ['email' => $actor['email'], 'password' => 'Audit!Fixture492']);
    $sids = [$player->cookies['ORACLESESS'], $second->cookies['ORACLESESS']];
    $launch = $player->request('/api/api.php?action=launch&challenge=spirit', null, 302)['location'];
    $spirit = new LogClient(); $spirit->request(parse_url($launch, PHP_URL_PATH) . '?' . parse_url($launch, PHP_URL_QUERY), null, 302, 'spirit-web');
    ensure($spirit->request('/contest-status.php', null, 200, 'spirit-web')['authenticated'], 'Challenge ticket fixture invalid');
    $entry = $uid . '.' . ticketB64(hash_hmac('sha256', 'water-entry:v2:' . $uid, accountSecret(), true));
    $drop = $uid . '.' . ticketB64(hash_hmac('sha256', 'water-drop:' . $uid, accountSecret(), true));
    $depth = new LogClient();
    $depth->request('/abismo.php?selo=' . $entry, null, 303, 'water-depth-web');
    $admin->call('ban_account', ['user_id' => $ids['admin']], 403);
    $admin->call('ban_account', ['user_id' => $uid]);
    foreach ($sids as $sid) ensure(!file_exists((getenv('SESSION_PATH') ?: '/var/www/sessions') . '/sess_' . $sid), 'Ban did not delete saved session');
    ensure(!$spirit->request('/contest-status.php', null, 200, 'spirit-web')['authenticated'], 'Banned challenge still authenticated');
    $depth->request('/abismo.php?selo=' . $entry, null, 403, 'water-depth-web');
    $depth->request('/correnteza.php?drop=' . $drop, null, 403, 'water-web');
    $player->call('me', null, 401); $second->call('me', null, 401);
    $second->call('bootstrap');
    $denied = $second->call('login', ['email' => $actor['email'], 'password' => 'Audit!Fixture492'], 401);
    ensure(str_contains($denied['error'], 'banida'), 'Ban message absent');
    ensure($getAccount()['is_banned'], 'Account list missing banned state');
    ensure($clients['control']->call('me')['profile']['id'] === $ids['control'], 'Other player session affected');
    // Logging survives a personal progress reset.
    $before = (int)query('SELECT COUNT(*) FROM SystemLogs WHERE user_id = ?', [$ids['control']])->fetchColumn();
    $clients['control']->call('reset', []);
    ensure((int)query('SELECT COUNT(*) FROM SystemLogs WHERE user_id = ?', [$ids['control']])->fetchColumn() > $before, 'Reset erased history');
    $database = new PDO('sqlite:/var/www/challenge-data/water/water.sqlite');
    foreach (['water_entries', 'water_requests', 'correnteza'] as $table) $database->prepare("DELETE FROM $table WHERE acct = ?")->execute([$uid]);
    echo "System logs: permissions, test-account flags, malformed attempts, IP, warnings, pagination, unread counters, all sessions/tickets revoked and ban message verified.\n";
} finally {
    foreach ($ids as $id) {
        destroyAccountSessions($id);
        query('DELETE FROM Account WHERE acc_id = ?', [$id]);
        $directory = getenv('SESSION_PATH') ?: '/var/www/sessions';
        foreach (glob($directory . '/progress-' . $id . '.*') ?: [] as $file) unlink($file);
    }
    contestTransaction(fn($c) => ['ok' => true]);
}
