<?php
declare(strict_types=1);
require '/var/www/html/api/lib.php';

final class AccountsTestClient {
    private string $cookie = '';
    private string $csrf = '';
    public function request(string $path, ?array $data = null, int $expected = 200, bool $csrf = true): array|string {
        $headers = ['Host: localhost', 'Cookie: ' . $this->cookie];
        if ($data !== null) {
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'X-CSRF-Token: ' . ($csrf ? $this->csrf : 'invalid');
        }
        $context = stream_context_create(['http' => ['method' => $data === null ? 'GET' : 'POST',
            'header' => implode("\r\n", $headers), 'content' => $data === null ? '' : json_encode($data), 'ignore_errors' => true]]);
        $body = file_get_contents('http://127.0.0.1' . $path, false, $context);
        preg_match('/\s(\d{3})\s/', $http_response_header[0], $status);
        foreach ($http_response_header as $header) {
            if (preg_match('/^Set-Cookie: (ORACLESESS=[^;]+)/i', $header, $cookie)) $this->cookie = $cookie[1];
        }
        if ((int)$status[1] !== $expected) throw new RuntimeException($path . ': ' . $body);
        $result = json_decode((string)$body, true);
        if (is_array($result)) $this->csrf = $result['csrf'] ?? $this->csrf;
        return $result ?? (string)$body;
    }
    public function call(string $action, ?array $data = null, int $expected = 200, bool $csrf = true): array {
        return $this->request('/api/api.php?action=' . $action, $data, $expected, $csrf);
    }
}

$suffix = bin2hex(random_bytes(5));
$adminName = 'acctadmin_' . $suffix;
$playerName = 'acctplayer_' . $suffix;
$password = 'Test!Accounts492';
$ids = [];
try {
    query('INSERT INTO Account (username, alias, email, password_hash, acc_role) VALUES (?, ?, ?, ?, ?)',
        [$adminName, 'Test Admin', $adminName . '@example.test', password_hash($password, PASSWORD_DEFAULT), 'admin']);
    $adminId = (int)db()->lastInsertId(); $ids[] = $adminId;
    $anonymous = new AccountsTestClient(); $anonymous->call('bootstrap');
    $anonymous->request('/accounts', null, 401);
    $anonymous->call('accounts', null, 401);
    $anonymous->call('update_accounts', ['accounts' => []], 401);
    $anonymous->call('register', ['username' => $playerName, 'email' => $playerName . '@example.test',
        'password' => $password, 'ignore_leaderboard' => true, 'acc_role' => 'admin'], 201);
    $player = query('SELECT * FROM Account WHERE username = ?', [$playerName])->fetch();
    $playerId = (int)$player['acc_id']; $ids[] = $playerId;
    if ($player['ignore_leaderboard'] || $player['acc_role'] !== 'player') throw new RuntimeException('Cadastro público não preserva jogador padrão.');
    $rows = array_filter(leaderboard(nowUtc()), fn($row) => $row['id'] === $playerId);
    if (count($rows) !== 1) throw new RuntimeException('Novo jogador não aparece no leaderboard.');
    query('UPDATE Account SET ignore_leaderboard = TRUE WHERE acc_id = ?', [$playerId]);
    $regular = new AccountsTestClient(); $regular->call('bootstrap');
    $regular->call('login', ['email' => $player['email'], 'password' => $password]);
    $regular->request('/accounts', null, 403);
    $regular->request('/accounts?action=me', null, 403);
    $regular->request('/accounts/accounts.html', null, 404);
    $regular->call('accounts', null, 403);
    $regular->call('update_accounts', ['accounts' => [['id' => $playerId, 'ignore_leaderboard' => false]]], 403);
    $admin = new AccountsTestClient(); $admin->call('bootstrap');
    $admin->call('login', ['email' => $adminName . '@example.test', 'password' => $password]);
    if (!str_contains($admin->request('/accounts'), 'Registro dos Buscadores')) throw new RuntimeException('Página admin ausente.');
    $accounts = $admin->call('accounts')['accounts'];
    foreach ($accounts as $account) if (array_key_exists('password_hash', $account)) throw new RuntimeException('Hash exposto.');
    $payload = ['accounts' => [['id' => $playerId, 'ignore_leaderboard' => false]]];
    $admin->call('update_accounts', $payload, 403, false);
    $admin->call('update_accounts', ['accounts' => [['id' => $playerId, 'ignore_leaderboard' => 'false']]], 400);
    $admin->call('update_accounts', ['accounts' => [['id' => $playerId, 'ignore_leaderboard' => false], ['id' => 2147483647, 'ignore_leaderboard' => true]]], 409);
    if (!query('SELECT ignore_leaderboard FROM Account WHERE acc_id = ?', [$playerId])->fetchColumn()) throw new RuntimeException('Lote inválido alterou uma conta.');
    $admin->call('update_accounts', $payload);
    if (query('SELECT ignore_leaderboard FROM Account WHERE acc_id = ?', [$playerId])->fetchColumn()) throw new RuntimeException('Não voltou a jogador.');
    $admin->call('update_accounts', ['accounts' => [['id' => $playerId, 'ignore_leaderboard' => true]]]);
    if (!query('SELECT ignore_leaderboard FROM Account WHERE acc_id = ?', [$playerId])->fetchColumn()) throw new RuntimeException('Não virou teste.');
    echo "Cadastro público aparece no leaderboard; página e ações restritas ao admin; CSRF, validação de lote e conversão reversível validados.\n";
} finally {
    foreach ($ids as $id) query('DELETE FROM Account WHERE acc_id = ?', [$id]);
    publishContestState(query('SELECT * FROM ContestState WHERE id = 1')->fetch());
}
