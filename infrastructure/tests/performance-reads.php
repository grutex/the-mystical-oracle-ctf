<?php
declare(strict_types=1);
if (getenv('ORACLE_PERFORMANCE_TEST') !== '1') throw new RuntimeException('Use only the isolated performance Compose project.');
require '/var/www/html/api/lib.php';
function perfCheck(bool $ok, string $detail): void {
    if (!$ok) throw new RuntimeException($detail);
}
contestTransaction(function () {
    query('DELETE FROM LeaderboardSnapshots');
    query('UPDATE ContestState SET started_at = UTC_TIMESTAMP(6), paused_at = NULL, ends_at = NULL, closed_at = NULL WHERE id = 1');
});
$code = <<<'PHP'
require '/var/www/html/api/lib.php';
db()->beginTransaction();
query('SELECT * FROM ContestState WHERE id = 1 FOR UPDATE')->fetch();
echo "locked\n";
flush();
fgets(STDIN);
db()->rollBack();
PHP;
$process = proc_open(['php', '-r', $code], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
try {
    perfCheck(trim(fgets($pipes[1])) === 'locked', 'Writer did not acquire its lock.');
    // A locking reader fails promptly instead of hanging the test indefinitely.
    db()->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $start = microtime(true);
    $result = contestRead(fn($c, $now) => ['contest' => contestView($c, $now), 'rows' => leaderboard($now)]);
    perfCheck($result['contest']['started_at'] !== null, 'Status lost contest state.');
    perfCheck(!db()->inTransaction(), 'Reader leaked its transaction.');
    printf("OK: status/ranking read while writer holds ContestState: %.1f ms\n", (microtime(true) - $start) * 1000);
} finally {
    fwrite($pipes[0], "release\n");
    foreach ($pipes as $pipe) fclose($pipe);
    proc_close($process);
}
query('UPDATE ContestState SET ends_at = TIMESTAMPADD(SECOND, -1, UTC_TIMESTAMP(6)) WHERE id = 1');
$closed = contestRead(fn($c, $now) => contestView($c, $now));
perfCheck($closed['closed_at'] !== null, 'Read did not enforce scheduled closure.');
contestRead(fn($c) => contestView($c));
perfCheck((int)query('SELECT COUNT(*) FROM LeaderboardSnapshots WHERE final_marker = 1')->fetchColumn() === 1,
    'Scheduled closure must create exactly one final snapshot.');
perfCheck(!db()->inTransaction(), 'Closure leaked its transaction.');
echo "OK: scheduled closure and final snapshot remain serialized.\n";
contestTransaction(function () {
    query('DELETE FROM LeaderboardSnapshots');
    query('UPDATE ContestState SET ends_at = NULL, closed_at = NULL WHERE id = 1');
});
$hash = password_hash('Performance!Test927', PASSWORD_DEFAULT);
for ($i = 0; $i < 120; $i++) {
    $name = 'perf_' . $i;
    query('INSERT INTO Account (username, alias, email, password_hash, ignore_leaderboard) VALUES (?, ?, ?, ?, 0)
        ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), ignore_leaderboard = 0', [$name, $name, $name . '@example.test', $hash]);
    query('INSERT IGNORE INTO CtfInfo (user_id) SELECT acc_id FROM Account WHERE username = ?', [$name]);
}
echo "Prepared 120 isolated load-test accounts.\n";
