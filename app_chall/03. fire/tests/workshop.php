<?php
// Execute inside fire-web: php /tmp/fire-workshop-test.php.
// Uses a temporary SQLite database, isolated APCu keys and a loopback-only server.
$fixture = sys_get_temp_dir() . '/fire-workshop-test-' . bin2hex(random_bytes(6));
mkdir($fixture, 0700);
putenv('FIRE_DATA=' . $fixture);
putenv('ACCOUNT_SECRET=isolated-workshop-test-secret');
putenv('FIRE_WORKSHOP_TEST_SUFFIX=:' . basename($fixture));
putenv('PHP_CLI_SERVER_WORKERS');
$server = null;
$checks = 0;

function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function request(string $endpoint, string $token, string $method = 'GET', array $body = [], string $user = 'Alice'): array {
    global $port;
    $headers = "Cookie: sessions=$token\r\nX-Fixture-User: $user\r\nContent-Type: application/x-www-form-urlencoded\r\n";
    $context = stream_context_create(['http' => ['method' => $method, 'header' => $headers, 'content' => http_build_query($body), 'ignore_errors' => true, 'timeout' => 3]]);
    $result = file_get_contents("http://127.0.0.1:$port/$endpoint.php", false, $context);
    if ($result === false) throw new RuntimeException('Fixture request failed');
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    return [(int)$match[1], json_decode($result, true, 512, JSON_THROW_ON_ERROR), $http_response_header];
}

try {
    foreach (['lib', 'sessions', 'workshop', 'whoami', 'logout', 'promote', 'vault'] as $file) {
        if (!copy('/var/www/html/' . $file . '.php', $fixture . '/' . $file . '.php')) throw new RuntimeException('Rebuild fire-web before testing');
    }
    file_put_contents($fixture . '/challenge-auth.php', '<?php function challenge_identity() { $user=$_SERVER["HTTP_X_FIXTURE_USER"]??"Alice"; return ["uid"=>$user==="Alice"?101:102,"username"=>$user]; } function oracle_progress_suffix($id) { return getenv("FIRE_WORKSHOP_TEST_SUFFIX").":".$id; }');
    require $fixture . '/lib.php';
    $insert = fire_db()->prepare('INSERT INTO users (username, pass_hash) VALUES (?, ?)');
    foreach (['Alice', 'Bob'] as $user) $insert->execute([$user, 'fixture-only']);
    $alice = fire_player_token('Alice');
    $guardian = fire_super_token('Alice');
    $_SERVER['HTTP_X_FIXTURE_USER'] = 'Bob';
    $bob = fire_player_token('Bob');
    $_SERVER['HTTP_X_FIXTURE_USER'] = 'Alice';

    file_put_contents($fixture . '/state.php', '<?php require __DIR__."/lib.php"; if(isset($_GET["seed"])) { apcu_store(fire_state_key("Alice","solved"),1,600); apcu_store(fire_state_key("Alice","lock1"),123,600); } fire_json(["solved"=>apcu_fetch(fire_state_key("Alice","solved")),"lock1"=>apcu_fetch(fire_state_key("Alice","lock1")),"role"=>fire_role("Alice")]);');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('Cannot allocate fixture port');
    $port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $server = proc_open([PHP_BINARY, '-d', 'apc.enable_cli=1', '-S', "127.0.0.1:$port", '-t', $fixture], [0 => ['pipe', 'r'], 1 => ['file', $fixture . '/server.log', 'a'], 2 => ['file', $fixture . '/server.log', 'a']], $pipes);
    if (!is_resource($server)) throw new RuntimeException('Cannot launch fixture server');
    fclose($pipes[0]);
    $ready = false;
    for ($i = 0; $i < 40; $i++) {
        $probe = @fsockopen('127.0.0.1', $port, $errno, $error, .1);
        if ($probe) { fclose($probe); $ready = true; break; }
        usleep(50000);
    }
    check($ready, 'Fixture server did not start');
    // Seed independent challenge state to verify workshop calls never modify it.
    $seed = file_get_contents("http://127.0.0.1:$port/state.php?seed=1");
    $originalState = json_decode($seed, true, 512, JSON_THROW_ON_ERROR);
    check(request('workshop', '')[0] === 401, 'Guest accessed workshop');
    check(request('workshop', $alice, 'GET', [], 'Bob')[0] === 401, 'Foreign token accepted');
    check(request('workshop', $guardian)[0] === 401, 'Guardian accepted by apprentice endpoint');
    check(request('workshop', $alice)[1]['locked'] === false, 'Initial workshop is locked');
    foreach ([['action'=>'unknown'], ['action'=>'alloy','quantity'=>0], ['action'=>'supplies','quantity'=>101], ['action'=>'alloy','quantity'=>['10']]] as $bad) {
        check(request('workshop', $alice, 'POST', $bad)[0] === 400, 'Invalid operation accepted');
        check(request('workshop', $alice)[1]['locked'] === false, 'Invalid input consumed allowance');
    }
    foreach (['supplies', 'print', 'alloy', 'seal'] as $action) {
        [$status, $result] = request('workshop', $alice, 'POST', ['action'=>$action, 'quantity'=>10, 'username'=>'Bob']);
        check($status === 200 && $result['ok'] && $result['locked'], 'Operation did not complete: ' . $action);
        check(str_contains($result['content'], 'Aprendiz: Alice') && !str_contains($result['content'], 'Bob'), 'Record used untrusted identity');
        check(preg_match('/^forja-[A-F0-9]{8}\.txt$/D', $result['filename']) === 1, 'Unsafe record filename');
        if ($action === 'alloy') check(str_contains($result['content'], '9,00 kg') && str_contains($result['content'], '1,00 kg'), 'Alloy calculation is incorrect');
        check(request('workshop', $alice)[1]['locked'] === true, 'Lock did not survive a subsequent request');
        check(request('workshop', $alice, 'POST', ['action'=>'seal'])[0] === 409, 'Second operation bypassed allowance');
        check(request('workshop', $bob, 'GET', [], 'Bob')[1]['locked'] === false, 'Another player was locked');
        [$status, $renewed, $headers] = request('sessions', $alice, 'POST', ['action'=>'renew', 'sessions'=>$guardian, 'username'=>'Bob']);
        check($status === 200 && $renewed['sessions'] === $alice, 'Renewal changed or accepted an injected token');
        check(!array_filter($headers, fn($h)=>stripos($h, 'Set-Cookie:') === 0), 'Renewal rewrote a cookie');
        check(request('whoami', $alice)[1]['role'] === 'player', 'Renewal changed privileges');
        check(request('workshop', $alice)[1]['locked'] === false, 'Renewal did not release the workshop');
    }
    check(request('sessions', $guardian, 'POST', ['action'=>'renew'])[0] === 401, 'Guardian renewed apprentice allowance');
    check(request('sessions', $alice, 'POST', ['action'=>'bad'])[0] === 400, 'Unknown renewal action accepted');
    $directory = request('sessions', $alice)[1]['files'];
    check(count($directory) === 200 && $directory[99]['name'] === $guardian, 'Directory layout regressed');
    check(count(array_unique(array_column($directory, 'name'))) === 200, 'Duplicate session tokens');
    foreach ($directory as $index => $entry) {
        check($entry['size'] === strlen($entry['name']), 'Declared size is incorrect');
        check($index === 99 ? ($entry['size'] >= 200 && $entry['size'] <= 250) : ($entry['size'] >= 60 && $entry['size'] <= 100), 'Session size is out of range');
    }
    request('workshop', $alice, 'POST', ['action'=>'seal']);
    check(request('logout', $alice)[1]['ok'], 'Logout failed');
    check(request('workshop', $alice)[1]['locked'] === false, 'Logout did not clear workshop allowance');
    check(request('state', $alice)[1] === $originalState, 'Workshop changed role, solved state or locks');
    check(request('promote', $guardian, 'POST')[1]['user'] === 'Alice', 'Intended promotion path regressed');
    check(request('vault', $alice)[1]['solved'] === true, 'Vault state did not survive workshop operations');
    check(request('workshop', $alice)[0] === 401, 'Promoted account accessed apprentice endpoint');
    echo "OK: $checks assertions; operations, isolation, allowance, renewal, directory and challenge state\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    foreach (glob($fixture . '/*') as $file) unlink($file);
    rmdir($fixture);
}
