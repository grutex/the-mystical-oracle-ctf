<?php
declare(strict_types=1);

// Loaded by the portal; database and account helpers are supplied by its lib.php.
// X-Real-IP is overwritten by our gateway; arbitrary client forwarded headers are not used.
function accessIp(): ?string {
    $ip = $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
}
function logText(string $value, int $limit): string {
    preg_match('/\A.{0,' . $limit . '}/us', $value, $match);
    return $match[0] ?? '[texto inválido]';
}
function systemLog(?array $account, string $event, ?bool $success, string $detail, ?string $challenge = null, ?string $flag = null, ?int $submission = null, ?string $warning = null): int {
    query('INSERT INTO SystemLogs (user_id, username, email, event_type, success, detail, challenge_name, submitted_flag, ip_address, submission_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$account['acc_id'] ?? null, $account['username'] ?? 'desconhecido', $account['email'] ?? null, $event, $success === null ? null : (int)$success,
        logText($detail, 500), $challenge === null ? null : logText($challenge, 20), $flag === null ? null : logText($flag, 255), accessIp(), $submission]);
    $logId = (int)db()->lastInsertId();
    if (!$warning && $success === false && $account && in_array($event, ['login', 'flag_submit'], true)) {
        $seconds = $event === 'login' ? 300 : 60;
        $threshold = $event === 'login' ? 5 : 10;
        $count = (int)query("SELECT COUNT(*) FROM SystemLogs WHERE user_id = ? AND event_type = ? AND success = 0 AND created_at >= TIMESTAMPADD(SECOND, -$seconds, UTC_TIMESTAMP(6))", [$account['acc_id'], $event])->fetchColumn();
        if ($count >= $threshold && $count % $threshold === 0) $warning = "$count falhas de " . ($event === 'login' ? 'login em 5 minutos.' : 'submissão de flags em 60 segundos.');
    }
    if ($warning) query('INSERT INTO Warnings (log_id, user_id, ip_address, reason) VALUES (?, ?, ?, ?)', [$logId, $account['acc_id'] ?? null, accessIp(), $warning]);
    return $logId;
}
function logRows(string $kind, ?int $user, int $before, bool $redactFlags = false): array {
    $where = ['l.log_id < ?']; $params = [$before ?: PHP_INT_MAX];
    if ($kind === 'flags') $where[] = "l.event_type = 'flag_submit'";
    if ($user !== null) { $where[] = 'l.user_id = ?'; $params[] = $user; }
    $join = $kind === 'warnings' ? 'JOIN Warnings w ON w.log_id = l.log_id' : '';
    $extra = $kind === 'warnings' ? ', w.reason AS warning' : '';
    $rows = query('SELECT l.log_id, l.user_id, l.username, l.email, l.event_type, l.success, l.detail, l.challenge_name, l.submitted_flag, l.ip_address, l.created_at' . $extra . ' FROM SystemLogs l ' . $join . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY l.log_id DESC LIMIT 101', $params)->fetchAll();
    $more = count($rows) > 100; $rows = array_slice($rows, 0, 100);
    foreach ($rows as &$row) {
        $row['log_id'] = (int)$row['log_id'];
        $row['success'] = $row['success'] === null ? null : (bool)$row['success'];
        if ($redactFlags) $row['submitted_flag'] = null;
        $row['created_at'] = iso($row['created_at']);
    }
    return ['logs' => $rows, 'has_more' => $more];
}
function logAccounts(int $admin): array {
    $stats = query('SELECT a.acc_id, COUNT(l.log_id) AS total, COALESCE(MAX(l.log_id), 0) AS latest, SUM(IF(l.log_id > COALESCE(r.last_log_id, 0), 1, 0)) AS unread FROM Account a LEFT JOIN SystemLogs l ON l.user_id = a.acc_id LEFT JOIN AdminLogReads r ON r.user_id = a.acc_id AND r.admin_id = ? GROUP BY a.acc_id', [$admin])->fetchAll();
    $byId = array_column($stats, null, 'acc_id');
    return array_map(fn($a) => $a + ['logs_count' => (int)$byId[$a['id']]['total'], 'unread' => (int)$byId[$a['id']]['unread'], 'latest_log_id' => (int)$byId[$a['id']]['latest']], accountList());
}
