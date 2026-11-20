<?php
declare(strict_types=1);
// Only temporary test accounts are modified by this integration test.
require '/var/www/html/api/lib.php';

function finaleCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
final class FinaleClient {
    private string $cookie = '';
    private string $csrf = '';
    public function call(string $action, ?array $data = null, int $expected = 200, bool $validCsrf = true): array {
        $headers = ['Host: ' . oracle_portal_host(), 'Cookie: ' . $this->cookie];
        if ($data !== null) {
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'X-CSRF-Token: ' . ($validCsrf ? $this->csrf : 'invalid');
        }
        $body = file_get_contents('http://127.0.0.1/api/api.php?action=' . $action, false,
            stream_context_create(['http' => ['method' => $data === null ? 'GET' : 'POST',
                'header' => implode("\r\n", $headers), 'content' => $data === null ? '' : json_encode($data),
                'ignore_errors' => true, 'timeout' => 10]]));
        preg_match('/\s(\d{3})\s/', $http_response_header[0], $status);
        foreach ($http_response_header as $header) {
            if (preg_match('/^Set-Cookie: (ORACLESESS=[^;]+)/i', $header, $cookie)) $this->cookie = $cookie[1];
        }
        finaleCheck((int)$status[1] === $expected, "$action: $body");
        $result = json_decode((string)$body, true, 512, JSON_THROW_ON_ERROR);
        $this->csrf = $result['csrf'] ?? $this->csrf;
        return $result;
    }
}

$username = 'finale_' . bin2hex(random_bytes(5));
$password = 'Finale!TestPassword627';
query('INSERT INTO Account (username, alias, email, password_hash, ignore_leaderboard) VALUES (?, ?, ?, ?, TRUE)',
    [$username, $username, $username . '@example.test', password_hash($password, PASSWORD_DEFAULT)]);
$id = (int)db()->lastInsertId();
query('INSERT INTO CtfInfo (user_id) VALUES (?)', [$id]);
try {
    $client = new FinaleClient();
    $client->call('bootstrap');
    $client->call('oracle_finale', [], 401);
    $client->call('bootstrap');
    $client->call('login', ['email' => $username . '@example.test', 'password' => $password]);
    $client->call('oracle_finale', [], 403, false);
    $client->call('oracle_finale', ['allFlagsSolved' => true, 'hasCompletedCtf' => true], 409);
    $client->call('prepare_oracle_finale', ['hasCrown' => true], 409);
    $initial = $client->call('me')['profile'];
    finaleCheck(!$initial['hasCrown'] && $initial['crownedAt'] === null && !$initial['allFlagsSolved'] && !$initial['oracleFinaleSeen'], 'New account has a completion reward');
    $keys = query('SELECT * FROM Challenges ORDER BY sort_order')->fetchAll();
    foreach ($keys as $index => $challenge) {
        $flag = $challenge['validation_type'] === 'fire_hmac'
            ? 'ORACLE{f1r3_' . substr(hash_hmac('sha256', $username . ':fire', accountSecret()), 0, 16) . '}'
            : personalFlag($challenge['challenge_name'], $id);
        $result = $client->call('submit', ['challenge' => $challenge['challenge_name'], 'flag' => $flag]);
        finaleCheck($result['correct'], 'Fixture flag rejected');
        if ($index < count($keys) - 1) {
            finaleCheck(!$result['profile']['hasCrown'], 'Crown awarded before clicking the eye');
            $client->call('oracle_finale', [], 409);
        }
    }
    $completed = $result['profile'];
    finaleCheck($completed['allFlagsSolved'] && !$completed['hasCrown'] && $completed['crownedAt'] === null && !$completed['oracleFinaleSeen'], 'Last flag should only unlock the pupil');
    $client->call('prepare_oracle_finale', [], 409);
    $beforeClick = nowUtc();
    $crowned = $client->call('oracle_finale', [])['profile'];
    finaleCheck($crowned['oracleFinaleSeen'] && $crowned['hasCrown'], 'Clicking the eye did not grant the crown');
    $crownedAt = query('SELECT crowned_at FROM Account WHERE acc_id = ?', [$id])->fetchColumn();
    finaleCheck($crownedAt >= $beforeClick && $crownedAt <= nowUtc(), 'Crown date must record the first eye activation');
    finaleCheck($crowned['crownedAt'] === iso($crownedAt), 'Profile exposes an incorrect crown date');
    $seenAt = query('SELECT oracle_finale_at FROM CtfInfo WHERE user_id = ?', [$id])->fetchColumn();
    $replayed = $client->call('oracle_finale', [])['profile'];
    finaleCheck($replayed['allFlagsSolved'] && $replayed['hasCompletedCtf'], 'Replay changed the achievement');
    finaleCheck($replayed['score'] === $completed['score'], 'Replay changed the score');
    finaleCheck(query('SELECT oracle_finale_at FROM CtfInfo WHERE user_id = ?', [$id])->fetchColumn() === $seenAt, 'Replay changed the original finale timestamp');
    $prepared = $client->call('prepare_oracle_finale', [])['profile'];
    finaleCheck(!$prepared['oracleFinaleSeen'] && $prepared['allFlagsSolved'] && $prepared['hasCrown'], 'Replay preparation must restore the white eye without removing the crown');
    finaleCheck($prepared['score'] === $completed['score'] && $prepared['crownedAt'] === $crowned['crownedAt'], 'Replay preparation changed the score or crown date');
    finaleCheck(!$client->call('me')['profile']['oracleFinaleSeen'], 'Prepared state did not persist');
    $client->call('oracle_finale', []);
    finaleCheck(query('SELECT crowned_at FROM Account WHERE acc_id = ?', [$id])->fetchColumn() === $crownedAt, 'Another eye activation changed the first crown date');
    $client->call('logout', []);
    $client->call('bootstrap');
    $client->call('login', ['email' => $username . '@example.test', 'password' => $password]);
    finaleCheck($client->call('me')['profile']['oracleFinaleSeen'], 'Finale was lost after another login');
    $reset = $client->call('reset', [])['profile'];
    finaleCheck(!$reset['allFlagsSolved'] && !$reset['oracleFinaleSeen'] && $reset['hasCrown'], 'Reset must restore colors and preserve the crown');
    finaleCheck(query('SELECT crowned_at FROM Account WHERE acc_id = ?', [$id])->fetchColumn() === $crownedAt, 'Reset changed the permanent achievement');
    $client->call('oracle_finale', [], 409);
    $client->call('prepare_oracle_finale', [], 409);
    echo "OK: crown and date granted on eye activation; replay prepares the white eye; crown date survives replays, reset and login.\n";
} finally {
    destroyAccountSessions($id);
    query('DELETE FROM SystemLogs WHERE user_id = ?', [$id]);
    query('DELETE FROM Account WHERE acc_id = ?', [$id]);
    foreach (['.epoch', '.lock'] as $extension) @unlink(oracle_progress_directory() . '/progress-' . $id . $extension);
}
