<?php
declare(strict_types=1);
// Only a temporary exempt player is used; does not change the contest state.
require '/var/www/html/api/lib.php';
function checkLogin(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function loginHttp(string $action, string $cookie = '', ?array $data = null, string $csrf = ''): array {
    $headers = ['Cookie: ORACLESESS=' . $cookie];
    if ($data !== null) { $headers[] = 'Content-Type: application/json'; $headers[] = 'X-CSRF-Token: ' . $csrf; }
    $body = file_get_contents('http://portal-web/api/api.php?action=' . $action, false, stream_context_create(['http' => [
        'method' => $data === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers), 'content' => $data === null ? '' : json_encode($data), 'ignore_errors' => true, 'timeout' => 10]]));
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $status);
    $cookies = [];
    foreach ($http_response_header as $header) if (preg_match('/^Set-Cookie: ORACLESESS=([^;]+)/i', $header, $match)) $cookies[] = $match[1];
    return ['status' => (int)$status[1], 'data' => json_decode((string)$body, true), 'cookies' => $cookies];
}
$id = null; $sessions = [];
try {
    $name = 'race_' . bin2hex(random_bytes(5));
    query('INSERT INTO Account (username, alias, email, password_hash, ignore_leaderboard) VALUES (?, ?, ?, ?, 1)', [$name, $name, $name . '@example.test', password_hash('Race!Fixture492', PASSWORD_DEFAULT)]);
    $id = (int)db()->lastInsertId(); query('INSERT INTO CtfInfo (user_id) VALUES (?)', [$id]);
    $bootstrap = loginHttp('bootstrap');
    $old = $bootstrap['cookies'][0]; $sessions[] = $old;
    $login = loginHttp('login', $old, ['email' => $name . '@example.test', 'password' => 'Race!Fixture492'], $bootstrap['data']['csrf']);
    checkLogin($login['status'] === 200, 'Login failed');
    $current = end($login['cookies']); $sessions[] = $current;
    checkLogin($current !== $old, 'Login must rotate the session ID');
    // A timer request sent with the pre-login cookie can finish after login.
    $late = loginHttp('status', $old);
    checkLogin(!$late['cookies'], 'Late timer response overwrites the successful login cookie');
    checkLogin(loginHttp('me', $current)['status'] === 200, 'New login session lost');
    $anonymous = loginHttp('status');
    checkLogin(!$anonymous['cookies'] && !$anonymous['data']['authenticated'], 'Anonymous timer creates a competing cookie');
    // Bootstrap/API requests already sent from another tab must also be harmless.
    $stale = loginHttp('bootstrap', $old);
    checkLogin(!$stale['cookies'] && !$stale['data']['authenticated'], 'Stale bootstrap replaces the current session');
    $staleMe = loginHttp('me', $old);
    checkLogin($staleMe['status'] === 401 && !$staleMe['cookies'], 'Old API request replaces the current cookie');
    $authenticated = loginHttp('bootstrap', $current);
    $again = loginHttp('login', $current, ['email' => $name . '@example.test', 'password' => 'Race!Fixture492'], $authenticated['data']['csrf']);
    $new = end($again['cookies']); $sessions[] = $new;
    checkLogin(loginHttp('me', $current)['status'] === 401, 'Old authenticated session survives rotation');
    checkLogin(loginHttp('me', $new)['status'] === 200, 'Second login not preserved');
    checkLogin(loginHttp('status', $new)['data']['authenticated'], 'Timer fails to recognize the new session');
    echo "Login race: delayed timer/bootstrap cannot replace login cookies; anonymous polling creates no cookie; rotation revokes old authentication.\n";
} finally {
    if ($id) { destroyAccountSessions($id); query('DELETE FROM Account WHERE acc_id = ?', [$id]); }
    foreach ($sessions as $sid) if (is_string($sid) && preg_match('/^[A-Za-z0-9,-]+$/D', $sid)) @unlink((getenv('SESSION_PATH') ?: '/var/www/sessions') . '/sess_' . $sid);
}
