<?php
declare(strict_types=1);
// Uses temporary test accounts; never resets an existing participant.
require '/var/www/html/api/lib.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
final class ResetClient {
    public array $cookies = [];
    public string $csrf = '';
    public function request(string $service, string $path, ?array $data = null, int $expected = 200): array {
        $hosts = ['fire-web' => 'fire', 'water-web' => 'water', 'water-depth-web' => 'depth-water'];
        $host = $service === 'portal-web' ? oracle_portal_host() : $hosts[$service] . '.' . oracle_challenge_host();
        $headers = ['Host: ' . $host, 'Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies))];
        if ($data !== null) {
            $headers[] = 'Content-Type: ' . ($service === 'portal-web' ? 'application/json' : 'application/x-www-form-urlencoded');
            $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        $context = stream_context_create(['http' => ['method' => $data === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers),
            'content' => $data === null ? '' : ($service === 'portal-web' ? json_encode($data) : http_build_query($data)),
            'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]]);
        $body = file_get_contents("http://$service$path", false, $context);
        preg_match('/\s(\d{3})\s/', $http_response_header[0], $status);
        foreach ($http_response_header as $header) {
            if (preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i', $header, $cookie)) $this->cookies[$cookie[1]] = $cookie[2];
            if (str_starts_with($header, 'Location: ')) $location = substr($header, 10);
        }
        check((int)$status[1] === $expected, "$service$path: HTTP {$status[1]} $body");
        $result = json_decode((string)$body, true) ?? [];
        $this->csrf = $result['csrf'] ?? $this->csrf;
        return $result + ['location' => $location ?? '', 'html' => $body];
    }
    public function call(string $action, ?array $data = null): array {
        return $this->request('portal-web', '/api/api.php?action=' . $action, $data);
    }
    public function launch(string $key, string $service, ResetClient $challenge): void {
        $url = $this->request('portal-web', '/api/api.php?action=launch&challenge=' . $key, null, 302)['location'];
        $challenge->request($service, parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY), null, 302);
    }
}

$ids = [];
$clients = [];
$suffix = bin2hex(random_bytes(5));
$fireDb = null;
$waterDb = null;
try {
    foreach (['reset', 'control'] as $label) {
        $username = $label . '_' . $suffix;
        $password = 'Progress!Reset492';
        query('INSERT INTO Account (username, alias, email, password_hash, acc_role, ignore_leaderboard) VALUES (?, ?, ?, ?, ?, 1)',
            [$username, $username, $username . '@example.test', password_hash($password, PASSWORD_DEFAULT), 'player']);
        $id = (int)db()->lastInsertId();
        $ids[$label] = $id;
        query('INSERT INTO CtfInfo (user_id) VALUES (?)', [$id]);
        $portal = new ResetClient();
        $portal->call('bootstrap');
        $portal->call('login', ['email' => $username . '@example.test', 'password' => $password]);
        $session = $portal->cookies['ORACLESESS'];
        $portal->request('portal-web', '/login');
        check($portal->call('bootstrap')['authenticated'], 'Returning to login lost authentication');
        check($portal->cookies['ORACLESESS'] === $session, 'Navigation rotated the portal session');
        $fire = new ResetClient();
        $portal->launch('fire', 'fire-web', $fire);
        $fire->request('fire-web', '/register.php', ['password' => 'local-seal']);
        $fire->request('fire-web', '/login.php', ['password' => 'local-seal']);
        $fireDb ??= new PDO('sqlite:/var/www/challenge-data/fire/fire.sqlite');
        $list = $fire->request('fire-web', '/sessions');
        usort($list['files'], fn($a, $b) => $b['size'] <=> $a['size']);
        $fire->cookies['sessions'] = $list['files'][0]['name'];
        $fire->request('fire-web', '/promote.php', []);
        check($fire->request('fire-web', '/whoami.php')['role'] === 'admin', 'Promotion fixture failed');
        $water = new ResetClient();
        $portal->launch('water', 'water-web', $water);
        // Marked reviewed to keep the real bot out of these fixtures.
        $water->request('water-web', '/correnteza.php');
        $waterDb ??= new PDO('sqlite:/var/www/challenge-data/water/water.sqlite');
        $waterDb->prepare('INSERT INTO reflexos (acct, corpo, revisado) VALUES (?, ?, 1)')->execute([$id, 'reset fixture']);
        $waterDb->prepare('INSERT INTO correnteza (acct, dado) VALUES (?, ?)')->execute([$id, 'reset capture']);
        $clients[$label] = [$portal, $fire, $water, $username];
    }
    [$portal, $fire, $water, $username] = $clients['reset'];
    foreach ([1, 2, 3, 4] as $n) $fire->request('fire-web', '/lock.php', ['n' => $n]);
    check(!$fire->request('fire-web', '/vault.php')['solved'], 'Sequential locks must not open the Cofre');
    $children = [];
    foreach ([1, 2, 3, 4] as $n) {
        $cookie = implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($fire->cookies), $fire->cookies));
        $code = '$context=stream_context_create(["http"=>["method"=>"POST","header"=>"Cookie: " . ' . var_export($cookie, true) . ' . "\r\nContent-Type: application/x-www-form-urlencoded","content"=>"n=' . $n . '"]]);echo file_get_contents("http://fire-web/lock.php",false,$context);';
        $process = proc_open(['php', '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $children[] = [$process, $pipes];
    }
    foreach ($children as [$process, $pipes]) { stream_get_contents($pipes[1]); fclose($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[2]); check(proc_close($process) === 0, 'Parallel lock failed'); }
    check($fire->request('fire-web', '/vault.php')['solved'], 'Cofre fixture not solved');
    check(!$clients['control'][1]->request('fire-web', '/vault.php')['solved'], 'Parallel locks opened another account');
    $acct = $ids['reset'];
    $oldEntry = $acct . '.' . ticketB64(hash_hmac('sha256', 'water-entry:v2:' . $acct, accountSecret(), true));
    $oldDrop = $acct . '.' . ticketB64(hash_hmac('sha256', 'water-drop:' . $acct, accountSecret(), true));
    $depth = new ResetClient();
    $depth->request('water-depth-web', '/abismo.php?selo=' . $oldEntry, null, 303);
    $nonce = bin2hex(random_bytes(16));
    $waterDb->prepare('INSERT INTO water_requests (acct, termo, revisado, nonce) VALUES (?, ?, 1, ?)')->execute([$acct, 'reset request', $nonce]);
    $requestId = (int)$waterDb->lastInsertId();
    $body = $acct . '.' . $requestId;
    $oldReceipt = $body . '.' . ticketB64(hash_hmac('sha256', 'water-receipt:v3:' . $body . ':' . $nonce, accountSecret(), true));
    $oldTicket = $fire->cookies['oracle_challenge'];
    $oldFire = $fire->cookies['sessions'];
    $portal->call('reset', []);
    check($portal->call('me')['profile']['id'] === $ids['reset'], 'Portal session was lost');
    check(oracle_progress_epoch($ids['reset']) !== '0', 'Epoch not rotated');
    check(oracle_progress_epoch($ids['control']) === '0', 'Other account reset');
    $fire->request('fire-web', '/whoami.php', null, 401);
    $status = $fire->request('fire-web', '/contest-status.php');
    check($status['progress_reset'] && !$status['authenticated'], 'Open page did not detect reset');
    $portal->launch('fire', 'fire-web', $fire);
    $fire->cookies['sessions'] = $oldFire;
    check($fire->request('fire-web', '/whoami.php')['role'] === 'guest', 'Old Fire token still accepted');
    $fire->request('fire-web', '/register.php', ['password' => 'new-local-seal']);
    $fire->request('fire-web', '/login.php', ['password' => 'new-local-seal']);
    check($fire->request('fire-web', '/whoami.php')['role'] === 'player', 'Fire role not reset');
    $list = $fire->request('fire-web', '/sessions');
    usort($list['files'], fn($a, $b) => $b['size'] <=> $a['size']);
    $fire->cookies['sessions'] = $list['files'][0]['name'];
    $fire->request('fire-web', '/promote.php', []);
    check(!$fire->request('fire-web', '/vault.php')['solved'], 'Cofre remained open');
    [$otherPortal, $otherFire] = $clients['control'];
    check($otherFire->request('fire-web', '/whoami.php')['role'] === 'admin', 'Other Fire account affected');
    $portal->launch('water', 'water-web', $water);
    $water->request('water-web', '/correnteza.php?drop=' . $oldDrop, ['d' => 'stale capture'], 403);
    $depth->request('water-depth-web', '/abismo.php?selo=' . $oldEntry, null, 403);
    check($depth->request('water-depth-web', '/contest-status.php')['progress_reset'], 'Abismo page did not detect reset');
    $response = $water->request('water-web', '/reward.php', ['proof' => 'anything', 'receipt' => $oldReceipt], 403);
    check($water->request('water-web', '/correnteza.php')['capturas'] === [], 'Captures not cleared');
    foreach (['reflexos', 'correnteza', 'water_entries', 'water_requests'] as $table) {
        $st = $waterDb->prepare("SELECT COUNT(*) FROM $table WHERE acct = ?");
        $st->execute([$ids['reset']]); check((int)$st->fetchColumn() === 0, "$table not cleared");
        if (in_array($table, ['reflexos', 'correnteza'], true)) { $st->execute([$ids['control']]); check((int)$st->fetchColumn() === 1, "Other $table affected"); }
    }
    // Reset while a delayed lock request is active: neither side may deadlock,
    // and that request must not reopen the next generation of the Cofre.
    $cookie = implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($fire->cookies), $fire->cookies));
    $code = '$context=stream_context_create(["http"=>["timeout"=>5,"method"=>"POST","header"=>"Cookie: " . ' . var_export($cookie, true) . ' . "\r\nContent-Type: application/x-www-form-urlencoded","content"=>"n=1"]]);echo file_get_contents("http://fire-web/lock.php",false,$context);';
    $process = proc_open(['php', '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    usleep(100000);
    $portal->call('reset', []);
    stream_get_contents($pipes[1]); fclose($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[2]);
    check(proc_close($process) === 0, 'Concurrent reset failed');
    check($otherFire->request('fire-web', '/whoami.php')['role'] === 'admin', 'Concurrent reset affected other account');
    echo "Progress reset: old tickets, Fire sessions and Water access/collectors revoked; Cofre closed; Water cleared; concurrent request handled; portal and other account preserved.\n";
} finally {
    foreach ($ids as $label => $id) {
        if (isset($clients[$label])) $clients[$label][0]->call('logout', []);
        if ($fireDb && $fireDb->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn()) $fireDb->prepare('DELETE FROM users WHERE username = ?')->execute([$label . '_' . $suffix]);
        foreach (['reflexos', 'correnteza', 'water_entries', 'water_requests'] as $table) {
            if ($waterDb && $waterDb->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='$table'")->fetchColumn()) $waterDb->prepare("DELETE FROM $table WHERE acct = ?")->execute([$id]);
        }
        query('DELETE FROM Account WHERE acc_id = ?', [$id]);
        @unlink(oracle_progress_directory() . '/progress-' . $id . '.epoch');
        @unlink(oracle_progress_directory() . '/progress-' . $id . '.lock');
    }
    publishContestState(query('SELECT * FROM ContestState WHERE id = 1')->fetch());
}
