<?php
declare(strict_types=1);
require '/var/www/html/api/lib.php';

final class PasswordTestClient {
    private string $cookie = '';
    private string $csrf = '';
    public function call(string $action, ?array $data = null, int $expected = 200, bool $csrf = true): array {
        $headers = ['Host: localhost', 'Cookie: ' . $this->cookie];
        if ($data !== null) {
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'X-CSRF-Token: ' . ($csrf ? $this->csrf : 'invalid');
        }
        $context = stream_context_create(['http' => [
            'method' => $data === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers),
            'content' => $data === null ? '' : json_encode($data), 'ignore_errors' => true,
        ]]);
        $body = file_get_contents('http://127.0.0.1/api/api.php?action=' . $action, false, $context);
        preg_match('/\s(\d{3})\s/', $http_response_header[0], $status);
        foreach ($http_response_header as $header) {
            if (preg_match('/^Set-Cookie: (ORACLESESS=[^;]+)/i', $header, $cookie)) $this->cookie = $cookie[1];
        }
        $result = json_decode((string)$body, true, 512, JSON_THROW_ON_ERROR);
        if ((int)$status[1] !== $expected) throw new RuntimeException($action . ': ' . $body);
        $this->csrf = $result['csrf'] ?? $this->csrf;
        return $result;
    }
}

$username = 'pwtest_' . bin2hex(random_bytes(5));
$email = $username . '@example.test';
$old = 'Old!TestPassword827';
$new = 'New!TestPassword946';
query('INSERT INTO Account (username, alias, email, password_hash, ignore_leaderboard) VALUES (?, ?, ?, ?, TRUE)',
    [$username, 'Password Test', $email, password_hash($old, PASSWORD_DEFAULT)]);
$id = (int)db()->lastInsertId();
query('INSERT INTO CtfInfo (user_id) VALUES (?)', [$id]);
try {
    $anonymous = new PasswordTestClient();
    $anonymous->call('bootstrap');
    $payload = ['current_password' => $old, 'new_password' => $new, 'confirm_password' => $new];
    $anonymous->call('change_password', $payload, 401);
    $client = new PasswordTestClient();
    $client->call('bootstrap');
    $client->call('login', ['email' => $email, 'password' => $old]);
    $client->call('change_password', $payload, 403, false);
    $client->call('change_password', ['current_password' => 'wrong'] + array_diff_key($payload, ['current_password' => true]), 400);
    $client->call('change_password', ['confirm_password' => 'mismatch'] + array_diff_key($payload, ['confirm_password' => true]), 400);
    foreach (['Abc!1234', 'abcdefghi!', 'Abcdefghi1', 'Áéééééé!'] as $invalid) {
        $client->call('change_password', ['current_password' => $old, 'new_password' => $invalid, 'confirm_password' => $invalid], 400);
        if (!password_verify($old, query('SELECT password_hash FROM Account WHERE acc_id = ?', [$id])->fetchColumn())) {
            throw new RuntimeException('Senha alterada por uma solicitação inválida.');
        }
    }
    $client->call('change_password', $payload);
    $hash = query('SELECT password_hash FROM Account WHERE acc_id = ?', [$id])->fetchColumn();
    if (!password_verify($new, $hash) || password_verify($old, $hash)) throw new RuntimeException('Hash incorreto.');
    $client->call('logout', []);
    $client->call('bootstrap');
    $client->call('login', ['email' => $email, 'password' => $old], 401);
    $client->call('login', ['email' => $email, 'password' => $new]);

    db()->beginTransaction();
    query('UPDATE Account SET ignore_leaderboard = FALSE WHERE acc_id = ?', [$id]);
    $rows = array_values(array_filter(leaderboard(nowUtc()), fn($row) => $row['id'] === $id));
    if (count($rows) !== 1 || $rows[0]['email'] !== $email) throw new RuntimeException('E-mail ausente no leaderboard.');
    db()->rollBack();
    echo "Troca de senha: autenticação, CSRF, senha atual, confirmação, tamanho e novo login validados; leaderboard inclui e-mail.\n";
} finally {
    if (db()->inTransaction()) db()->rollBack();
    query('DELETE FROM Account WHERE acc_id = ?', [$id]);
    publishContestState(query('SELECT * FROM ContestState WHERE id = 1')->fetch());
}
