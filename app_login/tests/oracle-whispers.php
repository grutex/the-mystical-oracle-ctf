<?php
declare(strict_types=1);
// Run against a separate fixture database, never against the live contest.
if (getenv('DB_NAME') !== 'ORACLE_WHISPERS_TEST') throw new RuntimeException('Use the isolated ORACLE_WHISPERS_TEST database.');
require '/var/www/html/api/lib.php';
function whisperCheck(bool $condition, string $detail): void {
    if (!$condition) throw new RuntimeException($detail);
}
final class WhisperClient {
    private string $cookie = '';
    private string $csrf = '';
    public function call(string $action, ?array $body = null, int $expected = 200, array $params = [], bool $validCsrf = true): array {
        $headers = ['Host: ' . oracle_portal_host(), 'Cookie: ' . $this->cookie];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'X-CSRF-Token: ' . ($validCsrf ? $this->csrf : 'invalid');
        }
        $response = file_get_contents('http://127.0.0.1:8097/api.php?' . http_build_query(['action' => $action] + $params), false,
            stream_context_create(['http' => ['method' => $body === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers),
                'content' => $body === null ? '' : json_encode($body), 'ignore_errors' => true, 'timeout' => 10]]));
        preg_match('/\s(\d{3})\s/', $http_response_header[0], $status);
        foreach ($http_response_header as $header) {
            if (preg_match('/^Set-Cookie: (ORACLESESS=[^;]+)/i', $header, $cookie)) $this->cookie = $cookie[1];
        }
        whisperCheck((int)$status[1] === $expected, "$action expected $expected: $response");
        $result = json_decode((string)$response, true, 512, JSON_THROW_ON_ERROR);
        $this->csrf = $result['csrf'] ?? $this->csrf;
        return $result;
    }
}
$sessions = getenv('SESSION_PATH');
if (!$sessions || !str_starts_with($sessions, '/tmp/oracle-whispers-')) throw new RuntimeException('Use an isolated session directory.');
if (!is_dir($sessions)) mkdir($sessions, 0700, true);
$server = proc_open(['php', '-S', '127.0.0.1:8097', '-t', '/var/www/html/api'],
    [['pipe', 'r'], ['file', '/tmp/oracle-whispers-server.log', 'a'], ['file', '/tmp/oracle-whispers-server.log', 'a']], $pipes);
try {
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', 8097);
        if ($socket) { fclose($socket); break; }
        usleep(100000);
    }
    foreach (['OracleWhispers', 'Warnings', 'SystemLogs', 'Account', 'ContestState'] as $table) query('DELETE FROM ' . $table);
    query('INSERT INTO ContestState (id, started_at) VALUES (1, UTC_TIMESTAMP(6))');
    $password = 'Whisper!Test627';
    foreach (['admin', 'player', 'test'] as $name) {
        query('INSERT INTO Account (username, alias, email, password_hash, acc_role, ignore_leaderboard) VALUES (?, ?, ?, ?, ?, ?)',
            [$name, $name, $name . '@example.test', password_hash($password, PASSWORD_DEFAULT), $name === 'admin' ? 'admin' : 'player', (int)($name === 'test')]);
    }
    $anonymous = new WhisperClient();
    $anonymous->call('bootstrap');
    $anonymous->call('whispers', null, 401);
    $anonymous->call('send_whisper', ['message' => 'forged'], 401);
    foreach (['player', 'test'] as $name) {
        $client = new WhisperClient();
        $client->call('bootstrap');
        $client->call('login', ['email' => $name . '@example.test', 'password' => $password]);
        $client->call('send_whisper', ['message' => 'forged', 'role' => 'admin', 'author_id' => 1], 403);
        whisperCheck($client->call('whispers')['whispers']['latestId'] === 0, 'Unauthorized message was persisted');
        $clients[$name] = $client;
    }
    $admin = new WhisperClient();
    $admin->call('bootstrap');
    $admin->call('login', ['email' => 'admin@example.test', 'password' => $password]);
    $admin->call('send_whisper', ['message' => 'wrong csrf'], 403, [], false);
    foreach (['', '   ', str_repeat('a', 1001), "bad\0control"] as $invalid) $admin->call('send_whisper', ['message' => $invalid], 400);
    $admin->call('send_whisper', null, 405);
    $text = "O véu se abre. 👁\n<img src=x onerror=alert(1)> & ecos";
    $before = nowUtc();
    $sent = $admin->call('send_whisper', ['message' => $text, 'author_id' => 999, 'created_at' => '1900-01-01'])['message'];
    whisperCheck($sent['message'] === $text && $sent['createdAt'] >= iso($before) && $sent['createdAt'] <= iso(nowUtc()), 'Message or server timestamp is incorrect');
    whisperCheck((int)query('SELECT author_id FROM OracleWhispers')->fetchColumn() === (int)query("SELECT acc_id FROM Account WHERE username = 'admin'")->fetchColumn(), 'Client forged message author');
    foreach ($clients as $client) {
        $feed = $client->call('whispers')['whispers'];
        whisperCheck($feed['messages'][0] === $sent && $feed['unreadCount'] === 1, 'Broadcast missing for player/test account');
        $status = $client->call('status', null, 200, ['whispers_after' => 0, 'whispers_seen' => 0]);
        whisperCheck($status['whispers']['messages'][0] === $sent, 'Timer polling did not carry broadcast');
        $empty = $client->call('whispers', null, 200, ['whispers_after' => $sent['id'], 'whispers_seen' => $sent['id']])['whispers'];
        whisperCheck($empty['messages'] === [] && $empty['unreadCount'] === 0, 'Cursor/read marker is incorrect');
        $client->call('whispers', null, 400, ['whispers_after' => '-1']);
        $client->call('status', null, 400, ['whispers_after' => ['bad']]);
    }
    whisperCheck(!isset($anonymous->call('status', null, 200, ['whispers_after' => 0])['whispers']), 'Anonymous status leaked messages');
    query('UPDATE ContestState SET closed_at = UTC_TIMESTAMP(6) WHERE id = 1');
    $admin->call('send_whisper', ['message' => 'O evento terminou.']);
    whisperCheck(count($clients['player']->call('whispers')['whispers']['messages']) === 2, 'Announcement blocked after contest closure');
    query("UPDATE Account SET is_banned = TRUE WHERE username = 'test'");
    $clients['test']->call('whispers', null, 401);
    query("UPDATE Account SET acc_role = 'player' WHERE username = 'admin'");
    $admin->call('send_whisper', ['message' => 'stale admin role'], 403);
    whisperCheck((int)query('SELECT COUNT(*) FROM OracleWhispers')->fetchColumn() === 2, 'Denied send altered history');
    for ($index = 0; $index < 105; $index++) query('INSERT INTO OracleWhispers (author_id, message) VALUES (NULL, ?)', ['History fixture ' . $index]);
    $history = $clients['player']->call('whispers')['whispers'];
    whisperCheck(count($history['messages']) === 100 && $history['unreadCount'] === 107, 'History limit or total unread count is incorrect');
    $ids = array_column($history['messages'], 'id');
    $ordered = $ids; sort($ordered);
    whisperCheck($ids === $ordered && end($ids) === $history['latestId'], 'History must be chronological with a correct cursor');
    echo "OK: admin-only writes, player/test reads, anonymous and banned rejection, CSRF, input limits, server timestamps and shared status polling.\n";
} finally {
    proc_terminate($server); proc_close($server);
    foreach (glob($sessions . '/*') ?: [] as $file) unlink($file);
    rmdir($sessions);
}
