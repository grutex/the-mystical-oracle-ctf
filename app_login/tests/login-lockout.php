<?php
declare(strict_types=1);
if (getenv('DB_NAME') !== 'ORACLE_LOGIN_LOCKOUT_TEST') throw new RuntimeException('Use ORACLE_LOGIN_LOCKOUT_TEST only.');
$sessions = getenv('SESSION_PATH') ?: '';
if (!str_starts_with($sessions, '/tmp/oracle-login-lockout-')) throw new RuntimeException('Use an isolated session directory.');
require '/var/www/html/api/lib.php';
if (!is_dir($sessions)) mkdir($sessions, 0700, true);
$checks = 0;
function lockCheck(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
final class LockClient {
    private string $cookie = '';
    private string $csrf = '';
    public array $headers = [];
    public function call(string $action, ?array $data = null, int $expected = 200, bool $validCsrf = true): array {
        $headers = ['Host: ' . oracle_portal_host(), 'Cookie: ' . $this->cookie];
        if ($data !== null) {
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'X-CSRF-Token: ' . ($validCsrf ? $this->csrf : 'wrong');
        }
        $body = file_get_contents('http://127.0.0.1:8098/api.php?action=' . $action, false, stream_context_create(['http' => [
            'method' => $data === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers), 'content' => $data === null ? '' : json_encode($data), 'ignore_errors' => true, 'timeout' => 10,
        ]]));
        $this->headers = $http_response_header;
        preg_match('/\s(\d{3})\s/', $this->headers[0], $status);
        lockCheck((int)$status[1] === $expected, "$action expected $expected, received {$status[1]}");
        foreach ($this->headers as $header) if (preg_match('/^Set-Cookie: (ORACLESESS=[^;]+)/i', $header, $match)) $this->cookie = $match[1];
        $result = json_decode((string)$body, true, 512, JSON_THROW_ON_ERROR);
        $this->csrf = $result['csrf'] ?? $this->csrf;
        return $result;
    }
    public function login(string $email, mixed $password, int $expected): array {
        return $this->call('login', ['email' => $email, 'password' => $password], $expected);
    }
}
function lockState(string $user = 'alice'): array {
    return query('SELECT login_failed_attempts, login_blocked_until FROM Account WHERE username = ?', [$user])->fetch();
}
$server = null;
try {
    lockCheck((int)query('SELECT COUNT(*) FROM Account')->fetchColumn() === 0, 'Fixture must be empty');
    query('INSERT INTO ContestState (id) VALUES (1)');
    $password = 'Lockout!Fixture492';
    $hash = password_hash($password, PASSWORD_DEFAULT);
    foreach (['alice'=>'player', 'bob'=>'player', 'admin'=>'admin', 'waiting'=>'player'] as $user => $role) {
        query('INSERT INTO Account (username, alias, email, password_hash, acc_role, ignore_leaderboard) VALUES (?, ?, ?, ?, ?, ?)', [$user, $user, $user . '@example.test', $hash, $role, $user === 'waiting' ? 0 : 1]);
        query('INSERT INTO CtfInfo (user_id) VALUES (?)', [(int)db()->lastInsertId()]);
    }
    $server = proc_open(['php', '-S', '127.0.0.1:8098', '-t', '/var/www/html/api'], [['pipe','r'], ['file',$sessions . '/server.log','a'], ['file',$sessions . '/server.log','a']], $pipes);
    if (!is_resource($server)) throw new RuntimeException('Server failed to start');
    fclose($pipes[0]);
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        $socket = @fsockopen('127.0.0.1', 8098, $errno, $error, .1);
        if ($socket) { fclose($socket); $ready = true; break; }
        usleep(50000);
    }
    lockCheck($ready, 'Fixture server not ready');
    $active = new LockClient(); $active->call('bootstrap'); $active->login('alice@example.test', $password, 200);
    $a = new LockClient(); $a->call('bootstrap');
    $a->call('login', ['email'=>'alice@example.test','password'=>'bad'], 403, false);
    lockCheck((int)lockState()['login_failed_attempts'] === 0, 'CSRF failure consumed allowance');
    $a->login('alice@example.test', 'bad', 401);
    $a->login(' ALICE@EXAMPLE.TEST ', 'bad', 401);
    lockCheck((int)lockState()['login_failed_attempts'] === 2, 'Email normalization bypassed account allowance');
    $a->login('alice@example.test', $password, 200);
    lockCheck((int)lockState()['login_failed_attempts'] === 0, 'Correct password did not clear previous failures');
    for ($i = 1; $i <= LOGIN_MAX_FAILURES; $i++) $a->login('alice@example.test', 'bad', $i === LOGIN_MAX_FAILURES ? 429 : 401);
    $blocked = lockState();
    lockCheck((int)$blocked['login_failed_attempts'] === LOGIN_MAX_FAILURES, 'Wrong failure threshold');
    $remaining = (int)query("SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(6), login_blocked_until) FROM Account WHERE username='alice'")->fetchColumn();
    lockCheck($remaining >= 1795 && $remaining <= 1800, 'Block is not 30 minutes');
    lockCheck(count(array_filter($a->headers, fn($header)=>preg_match('/^Retry-After: 1800$/i', $header))) === 1, 'Retry-After missing');
    $a->login('alice@example.test', $password, 429);
    $fresh = new LockClient(); $fresh->call('bootstrap');
    $fresh->login('alice@example.test', $password, 429);
    lockCheck(lockState() === $blocked, 'Blocked requests changed counter or extended deadline');
    $active->call('me');
    $fresh->login('bob@example.test', $password, 200);
    $warnings = (int)query('SELECT COUNT(*) FROM Warnings')->fetchColumn();
    lockCheck($warnings === 1, 'Threshold warning missing or repeated on blocked requests');
    query("UPDATE Account SET login_blocked_until=TIMESTAMPADD(SECOND,-1,UTC_TIMESTAMP(6)) WHERE username='alice'");
    $a->login('alice@example.test', 'bad', 401);
    lockCheck((int)lockState()['login_failed_attempts'] === 1 && lockState()['login_blocked_until'] === null, 'Expired allowance did not reset');
    $a->login('alice@example.test', $password, 200);
    lockCheck((int)lockState()['login_failed_attempts'] === 0, 'Login after expiry failed to reset allowance');
    for ($i = 1; $i <= LOGIN_MAX_FAILURES; $i++) $a->login('alice@example.test', 'bad', $i === LOGIN_MAX_FAILURES ? 429 : 401);
    query("UPDATE Account SET login_blocked_until=TIMESTAMPADD(SECOND,-1,UTC_TIMESTAMP(6)) WHERE username='alice'");
    $a->login('alice@example.test', $password, 200);
    lockCheck(lockState()['login_blocked_until'] === null, 'Correct login after expiry did not clear block');
    foreach ([str_repeat('x',73), ['invalid'], ''] as $i => $invalid) $a->login('alice@example.test', $invalid, $i === LOGIN_MAX_FAILURES - 1 ? 429 : 401);
    $admin = new LockClient(); $admin->call('bootstrap');
    for ($i = 1; $i <= LOGIN_MAX_FAILURES; $i++) $admin->login('admin@example.test', 'bad', $i === LOGIN_MAX_FAILURES ? 429 : 401);
    lockCheck(lockState('admin')['login_blocked_until'] !== null, 'Admin silently bypassed allowance');
    $waiting = new LockClient(); $waiting->call('bootstrap');
    $waiting->login('waiting@example.test', 'bad', 401);
    $waiting->login('waiting@example.test', $password, 409);
    lockCheck((int)lockState('waiting')['login_failed_attempts'] === 0, 'Event restriction counted as a wrong password');
    for ($i = 0; $i < 4; $i++) $waiting->login('missing@example.test', 'bad', 401);
    echo "OK: $checks assertions; threshold, expiry, reset, cookies, account isolation, active sessions and event rules\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    foreach (glob($sessions . '/*') as $file) if (is_file($file)) unlink($file);
    rmdir($sessions);
}
