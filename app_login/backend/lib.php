<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/private/access-mode.php';
require_once dirname(__DIR__, 2) . '/private/challenge-progress.php';
require_once dirname(__DIR__, 2) . '/private/logs/lib.php';

final class ApiError extends RuntimeException {
    public function __construct(string $message, public int $status = 400) { parent::__construct($message); }
}
const LOGIN_BLOCK_SECONDS = 1800;
const LOGIN_MAX_FAILURES = 3;

function loginLockError(array $account, string $now): ?ApiError {
    $until = $account['login_blocked_until'] ?? null;
    if (!$until || $until <= $now) return null;
    $utc = new DateTimeZone('UTC');
    $remaining = (float)(new DateTimeImmutable($until, $utc))->format('U.u')
        - (float)(new DateTimeImmutable($now, $utc))->format('U.u');
    $seconds = max(1, (int)ceil($remaining));
    $minutes = (int)ceil($seconds / 60);
    header('Retry-After: ' . $seconds);
    return new ApiError('Login temporariamente bloqueado. Tente novamente em ' . $minutes . ($minutes === 1 ? ' minuto.' : ' minutos.'), 429);
}

// Called under the contest transaction, so concurrent failures share one allowance.
function recordLoginFailure(array $account, string $now, int $limit): array {
    $failed = $account['login_blocked_until'] !== null ? 1 : (int)$account['login_failed_attempts'] + 1;
    $until = $failed >= $limit
        ? (new DateTimeImmutable($now, new DateTimeZone('UTC')))->modify('+' . LOGIN_BLOCK_SECONDS . ' seconds')->format('Y-m-d H:i:s.u') : null;
    query('UPDATE Account SET login_failed_attempts = ?, login_blocked_until = ? WHERE acc_id = ?', [$failed, $until, $account['acc_id']]);
    $account['login_failed_attempts'] = $failed;
    $account['login_blocked_until'] = $until;
    return $account;
}
function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: 'portal-db') .
            ';dbname=' . (getenv('DB_NAME') ?: 'MYSTICAL_ORACLE_LOGIN_DB') . ';charset=utf8mb4',
            getenv('DB_USER') ?: 'oracle', getenv('DB_PASS') ?: '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }
    return $pdo;
}
function query(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function nowUtc(): string { return query('SELECT UTC_TIMESTAMP(6)')->fetchColumn(); }
function iso(?string $date): ?string { return $date === null ? null : str_replace(' ', 'T', $date) . 'Z'; }
function jsonResponse(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
function textField(array $data, string $key, int $max = 255): string {
    $value = $data[$key] ?? '';
    if (!is_string($value) || strlen($value) > $max) throw new ApiError('Campo inválido: ' . $key);
    return trim($value);
}
function validAccountPassword(mixed $password): bool {
    return is_string($password) && strlen($password) <= 72
        && preg_match('/\A(?=.{9,}\z)(?=.*\p{Lu})(?=.*[\p{P}\p{S}]).*\z/us', $password) === 1;
}
function oracleWhispers(): array {
    $cursors = [];
    foreach (['whispers_after', 'whispers_seen'] as $key) {
        $value = filter_var($_GET[$key] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 4294967295]]);
        if ($value === false) throw new ApiError('Marcador de mensagem inválido.');
        $cursors[] = $value;
    }
    [$after, $seen] = $cursors;
    // Bound each response; first visits receive the most recent history.
    $latest = (int)query('SELECT COALESCE(MAX(whisper_id), 0) FROM OracleWhispers')->fetchColumn();
    $rows = query('SELECT whisper_id, message, created_at FROM OracleWhispers WHERE whisper_id > ? AND whisper_id <= ? ORDER BY whisper_id DESC LIMIT 100', [$after, $latest])->fetchAll();
    $unread = (int)query('SELECT COUNT(*) FROM OracleWhispers WHERE whisper_id > ? AND whisper_id <= ?', [$seen, $latest])->fetchColumn();
    return ['messages' => array_map(fn($row) => [
        'id' => (int)$row['whisper_id'], 'message' => $row['message'], 'createdAt' => iso($row['created_at']),
    ], array_reverse($rows)), 'latestId' => $latest, 'seenId' => $seen, 'unreadCount' => $unread];
}
function accountList(): array {
    return array_map(fn($account) => [
        'id' => (int)$account['acc_id'], 'username' => $account['username'],
        'name' => $account['alias'], 'email' => $account['email'],
        'role' => $account['acc_role'], 'ignore_leaderboard' => (bool)$account['ignore_leaderboard'],
        'is_banned' => (bool)$account['is_banned'], 'created_at' => iso($account['created_at']),
        'last_login' => iso($account['last_login']), 'score' => (int)$account['score'],
        'solves_count' => (int)$account['solves_count'],
    ], query("SELECT a.*, COALESCE(c.score, 0) AS score, COALESCE(c.solves_count, 0) AS solves_count FROM Account a LEFT JOIN CtfInfo c ON c.user_id = a.acc_id ORDER BY LOWER(SUBSTRING_INDEX(a.email, '@', 1)), a.acc_id")->fetchAll());
}
function rotatePortalSession(): void {
    // Keep an anonymous tombstone for requests already using the old ID.
    // Deleting the old file immediately makes strict-mode PHP issue a competing
    // cookie when a delayed request arrives after the successful login.
    $_SESSION = ['rotated_at' => time()];
    if (!session_regenerate_id(false)) throw new RuntimeException('Não foi possível renovar a sessão.');
    $_SESSION = [];
}
function currentAccount(): array {
    $account = query('SELECT * FROM Account WHERE acc_id = ?', [$_SESSION['uid'] ?? 0])->fetch();
    if ($account && $account['is_banned']) {
        rotatePortalSession();
        throw new ApiError('Sua conta foi banida pelo administrador. Entre em contato com a organização.', 401);
    }
    if (!$account) throw new ApiError('Entre para continuar.', 401);
    assertContestAccess($account, query('SELECT * FROM ContestState WHERE id = 1')->fetch());
    return $account;
}
function assertContestAccess(array $account, array $contest): void {
    if (in_array($account['acc_role'], ['admin', 'logs'], true) || $account['ignore_leaderboard']) return;
    if ($contest['paused_at'] || (int)($_SESSION['contest_epoch'] ?? 0) !== (int)$contest['session_epoch']) {
        rotatePortalSession();
        throw new ApiError('O CTF foi pausado. Entre novamente após a retomada.', 401);
    }
}
function publishContestState(array $contest): void {
    $contest['banned_accounts'] = array_map('intval', query('SELECT acc_id FROM Account WHERE is_banned = 1')->fetchAll(PDO::FETCH_COLUMN));
    $contest['exempt_accounts'] = array_map('intval', query("SELECT acc_id FROM Account WHERE acc_role IN ('admin', 'logs') OR ignore_leaderboard = 1")->fetchAll(PDO::FETCH_COLUMN));
    $directory = getenv('SESSION_PATH') ?: '/var/www/sessions';
    $encoded = json_encode($contest, JSON_THROW_ON_ERROR);
    if (@file_get_contents($directory . '/contest-state.json') === $encoded) return;
    $temporary = tempnam($directory, 'ctf-');
    if ($temporary === false) throw new RuntimeException('Não foi possível publicar o estado do CTF.');
    file_put_contents($temporary, $encoded);
    chmod($temporary, 0644);
    if (!rename($temporary, $directory . '/contest-state.json')) throw new RuntimeException('Não foi possível atualizar o estado do CTF.');
}
function requireAdmin(array $account): void {
    if ($account['acc_role'] !== 'admin') {
        systemLog($account, 'admin_denied', false, 'Tentativa de acessar uma função administrativa.', null, null, null, 'Acesso administrativo sem permissão.');
        throw new ApiError('Somente o organizador pode realizar esta ação.', 403);
    }
}

function destroyAccountSessions(int $id): void {
    $directory = getenv('SESSION_PATH') ?: '/var/www/sessions';
    foreach (glob($directory . '/sess_*') ?: [] as $file) {
        $handle = @fopen($file, 'r+');
        if (!$handle) continue;
        if (flock($handle, LOCK_EX | LOCK_NB)) {
            if (preg_match('/(?:^|;)uid\\|i:' . $id . ';/', stream_get_contents($handle) ?: '')) { ftruncate($handle, 0); unlink($file); }
            flock($handle, LOCK_UN);
        }
        fclose($handle);
    }
}
function resetChallengeState(array $account): void {
    $id = (int)$account['acc_id'];
    $directory = oracle_progress_directory();
    $lock = fopen($directory . '/progress-' . $id . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Não foi possível reiniciar os desafios.');
    try {
        foreach (['fire' => 'fire.sqlite', 'water' => 'water.sqlite'] as $kind => $filename) {
            $path = '/var/www/challenge-data/' . $kind . '/' . $filename;
            if (!is_file($path)) continue;
            $storage = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $storage->exec('PRAGMA busy_timeout=10000');
            $storage->beginTransaction();
            try {
                $tables = $kind === 'fire' ? ['users'] : ['reflexos', 'correnteza', 'water_entries', 'water_requests'];
                foreach ($tables as $table) {
                    $exists = $storage->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
                    $exists->execute([$table]);
                    if (!$exists->fetchColumn()) continue;
                    $column = $kind === 'fire' ? 'username' : 'acct';
                    $storage->prepare("DELETE FROM $table WHERE $column = ?")->execute([$kind === 'fire' ? $account['username'] : $id]);
                }
                $storage->commit();
            } catch (Throwable $error) {
                if ($storage->inTransaction()) $storage->rollBack();
                throw $error;
            }
        }
        $temporary = tempnam($directory, 'progress-');
        if ($temporary === false) throw new RuntimeException('Não foi possível reiniciar os acessos.');
        if (file_put_contents($temporary, bin2hex(random_bytes(16))) === false) throw new RuntimeException('Não foi possível reiniciar os acessos.');
        chmod($temporary, 0644);
        if (!rename($temporary, $directory . '/progress-' . $id . '.epoch')) throw new RuntimeException('Não foi possível reiniciar os acessos.');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
function resetAllChallengeState(array $accounts): void {
    $directory = oracle_progress_directory();
    $handles = [];
    usort($accounts, fn($a, $b) => (int)$a['acc_id'] <=> (int)$b['acc_id']);
    try {
        foreach ($accounts as $account) {
            $handle = fopen($directory . '/progress-' . (int)$account['acc_id'] . '.lock', 'c');
            if ($handle === false || !flock($handle, LOCK_EX)) {
                if (is_resource($handle)) fclose($handle);
                throw new RuntimeException('Não foi possível bloquear os estados dos desafios.');
            }
            $handles[] = $handle;
        }
        $databases = [
            ['/var/www/challenge-data/fire/fire.sqlite', ['users']],
            ['/var/www/challenge-data/water/water.sqlite', ['reflexos', 'correnteza', 'water_entries', 'water_requests']],
        ];
        foreach ($databases as [$path, $tables]) {
            if (!is_file($path)) continue;
            $storage = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $storage->exec('PRAGMA busy_timeout=10000');
            $storage->beginTransaction();
            try {
                foreach ($tables as $table) {
                    $exists = $storage->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
                    $exists->execute([$table]);
                    if ($exists->fetchColumn()) $storage->exec('DELETE FROM ' . $table);
                }
                $storage->commit();
            } catch (Throwable $error) {
                if ($storage->inTransaction()) $storage->rollBack();
                throw $error;
            }
        }
        foreach ($accounts as $account) {
            $epochPath = $directory . '/progress-' . (int)$account['acc_id'] . '.epoch';
            $temporary = tempnam($directory, 'progress-');
            if ($temporary === false || file_put_contents($temporary, bin2hex(random_bytes(16))) === false) {
                if ($temporary !== false) @unlink($temporary);
                throw new RuntimeException('Não foi possível invalidar os estados antigos dos desafios.');
            }
            chmod($temporary, 0644);
            if (!rename($temporary, $epochPath)) {
                @unlink($temporary);
                throw new RuntimeException('Não foi possível invalidar os estados antigos dos desafios.');
            }
        }
    } finally {
        foreach (array_reverse($handles) as $handle) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
function profile(array $account): array {
    $solves = query('SELECT s.challenge_name, s.points, s.solved_at, c.title,
        (SELECT f.submitted_flag FROM FlagSubmissions f
         WHERE f.user_id = s.user_id AND f.challenge_name = s.challenge_name AND f.is_correct = 1
         ORDER BY f.sub_id DESC LIMIT 1) AS accepted_flag
        FROM ChallengeSolves s JOIN Challenges c USING (challenge_name)
        WHERE s.user_id = ? ORDER BY s.solved_at, c.sort_order', [$account['acc_id']])->fetchAll();
    $completion = query('SELECT a.has_crown, a.crowned_at, i.oracle_finale_at,
        (SELECT COUNT(*) FROM Challenges) AS challenge_count
        FROM Account a LEFT JOIN CtfInfo i ON i.user_id = a.acc_id WHERE a.acc_id = ?', [$account['acc_id']])->fetch();
    return [
        'id' => (int)$account['acc_id'], 'username' => $account['alias'],
        'login' => $account['username'], 'role' => $account['acc_role'],
        'questionsCount' => (int)$account['questions_count'],
        'score' => array_sum(array_column($solves, 'points')),
        'solvedFlags' => array_column($solves, 'challenge_name'),
        'allFlagsSolved' => (int)$completion['challenge_count'] > 0 && count($solves) === (int)$completion['challenge_count'],
        'hasCrown' => (bool)$completion['has_crown'],
        'hasCompletedCtf' => (bool)$completion['has_crown'],
        'crownedAt' => iso($completion['crowned_at']),
        'oracleFinaleSeen' => $completion['oracle_finale_at'] !== null,
        'acceptedFlags' => (object)array_column($solves, 'accepted_flag', 'challenge_name'),
        'history' => array_map(fn($s) => [
            'type' => 'CTF', 'title' => $s['title'], 'pts' => (int)$s['points'], 'at' => iso($s['solved_at'])
        ], $solves)
    ];
}
function challenges(): array {
    return array_map(fn($c) => [
        'key' => $c['challenge_name'], 'title' => $c['title'], 'points' => (int)$c['points'],
        'available' => (bool)$c['enabled'] && (in_array($c['validation_type'], ['fire_hmac', 'personal_hmac'], true)
            ? (bool)getenv('ACCOUNT_SECRET') : $c['flag_hash'] !== null)
    ], query('SELECT * FROM Challenges ORDER BY sort_order')->fetchAll());
}

function accountSecret(): string {
    $secret = getenv('ACCOUNT_SECRET') ?: '';
    if (strlen($secret) < 32) throw new ApiError('A emissão de flags individuais aguarda configuração.', 503);
    return $secret;
}
function ticketB64(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
function challengeTicket(array $account, string $challenge): string {
    $lock = fopen(oracle_progress_directory() . '/progress-' . (int)$account['acc_id'] . '.lock', 'c');
    if ($lock === false) throw new RuntimeException('Não foi possível preparar o desafio.');
    fclose($lock);
    $payload = ticketB64(json_encode([
        'uid' => (int)$account['acc_id'], 'username' => $account['username'],
        'challenge' => $challenge, 'sid' => session_id(), 'exp' => time() + 300,
        'progress_epoch' => oracle_progress_epoch((int)$account['acc_id'])
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    return $payload . '.' . ticketB64(hash_hmac('sha256', 'ticket:' . $payload, accountSecret(), true));
}
function challengeLaunchUrl(array $account, string $challenge): string {
    $hosts = ['spirit' => 'spirit', 'air' => 'air', 'fire' => 'fire',
        'water' => 'water', 'earth' => 'earth', 'light_dark' => 'light-dark'];
    if (!isset($hosts[$challenge])) throw new ApiError('Desafio desconhecido.', 404);
    $base = oracle_public_origin($hosts[$challenge] . '.' . oracle_challenge_host());
    return $base . '/enter.php?ticket=' . rawurlencode(challengeTicket($account, $challenge));
}
function personalFlag(string $challenge, int $accountId): string {
    $prefixes = ['spirit' => 'sp1rit', 'air' => 'a1r', 'earth' => '34rth',
        'water' => 'w4t3r', 'light_dark' => 'l1ght_d4rk'];
    if (!isset($prefixes[$challenge])) throw new ApiError('Desafio sem flag individual configurada.', 409);
    $digest = substr(hash_hmac('sha256', 'flag:' . $accountId . ':' . $challenge, accountSecret()), 0, 20);
    return 'ORACLE{' . $prefixes[$challenge] . '_' . $digest . '}';
}
function leaderboard(string $cutoff, bool $testAccounts = false): array {
    $rows = query("SELECT a.acc_id, a.username, a.alias, a.email, a.created_at, a.has_crown,
            COALESCE(SUM(s.points), 0) AS score, COUNT(s.challenge_name) AS solves,
            MAX(s.solved_at) AS last_solve,
            RANK() OVER (ORDER BY COALESCE(SUM(s.points), 0) DESC,
                MAX(s.solved_at) IS NULL, MAX(s.solved_at) ASC) AS placement
        FROM Account a LEFT JOIN ChallengeSolves s ON s.user_id = a.acc_id AND s.solved_at < ?
        WHERE a.acc_role = 'player' AND a.is_banned = 0 AND a.ignore_leaderboard = ? AND a.created_at <= ?
        GROUP BY a.acc_id, a.username, a.alias, a.email, a.created_at, a.has_crown
        ORDER BY score DESC, MAX(s.solved_at) IS NULL, MAX(s.solved_at) ASC, a.acc_id ASC", [$cutoff, (int)$testAccounts, $cutoff])->fetchAll();
    return array_map(fn($r) => [
        'rank' => (int)$r['placement'], 'id' => (int)$r['acc_id'], 'username' => $r['username'], 'name' => $r['alias'],
        'email' => $r['email'], 'hasCrown' => (bool)$r['has_crown'],
        'score' => (int)$r['score'], 'solves' => (int)$r['solves'], 'lastFlagDate' => iso($r['last_solve'])
    ], $rows);
}
function leaderboardCrownStatus(array $rows): array {
    if (!$rows) return $rows;
    $ids = array_values(array_unique(array_filter(array_map(fn($row) => (int)($row['id'] ?? 0), $rows))));
    if (!$ids) return $rows;
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $crowned = array_fill_keys(array_map('intval', query("SELECT acc_id FROM Account WHERE has_crown = 1 AND acc_id IN ($marks)", $ids)->fetchAll(PDO::FETCH_COLUMN)), true);
    foreach ($rows as &$row) $row['hasCrown'] = isset($crowned[(int)($row['id'] ?? 0)]);
    unset($row);
    return $rows;
}
function saveSnapshot(string $cutoff, bool $final, ?int $creator): array {
    $captured = nowUtc();
    $payload = ['cutoff_at' => iso($cutoff), 'captured_at' => iso($captured), 'final' => $final,
        'ranking_rule' => 'Pontuação decrescente, última resolução crescente. Empates dividem a colocação.',
        'rows' => leaderboard($cutoff)];
    query('INSERT INTO LeaderboardSnapshots (created_by, captured_at, cutoff_at, final_marker, payload)
        VALUES (?, ?, ?, ?, ?)', [$creator, $captured, $cutoff, $final ? 1 : null,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    $payload['id'] = (int)db()->lastInsertId();
    return $payload;
}
function finalSnapshot(): ?array {
    $row = query('SELECT id, payload FROM LeaderboardSnapshots WHERE final_marker = 1')->fetch();
    return $row ? ['id' => (int)$row['id']] + json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR) : null;
}
// The singleton row serializes scoring, resets, closure and snapshots.
// Time is read AFTER acquiring the lock: a queued request cannot bypass the deadline.
function contestTransaction(callable $callback): mixed {
    db()->beginTransaction();
    try {
        $contest = query('SELECT * FROM ContestState WHERE id = 1 FOR UPDATE')->fetch();
        if (!$contest) throw new RuntimeException('ContestState ausente. Aplique o schema.');
        $now = nowUtc();
        if (!$contest['closed_at'] && !$contest['paused_at'] && $contest['ends_at'] && $now >= $contest['ends_at']) {
            $contest['closed_at'] = $contest['ends_at'];
            query('UPDATE ContestState SET closed_at = ? WHERE id = 1', [$contest['closed_at']]);
            saveSnapshot($contest['closed_at'], true, null);
        }
        // Persist timed closure even if the requested mutation must be rejected.
        $result = $callback($contest, $now);
        publishContestState(query('SELECT * FROM ContestState WHERE id = 1')->fetch());
        db()->commit();
        if ($result instanceof ApiError) throw $result;
        return $result;
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
}
// Readers see one consistent snapshot without queuing behind every other reader.
// Scheduled closure still goes through the same serialized writer as submissions.
function contestRead(callable $callback): mixed {
    if (!is_file((getenv('SESSION_PATH') ?: '/var/www/sessions') . '/contest-state.json')) {
        return contestTransaction($callback);
    }
    db()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    db()->beginTransaction();
    try {
        $contest = query('SELECT *, UTC_TIMESTAMP(6) AS read_time FROM ContestState WHERE id = 1')->fetch();
        if (!$contest) throw new RuntimeException('ContestState ausente. Aplique o schema.');
        $now = $contest['read_time'];
        unset($contest['read_time']);
        if (!$contest['closed_at'] && !$contest['paused_at'] && $contest['ends_at'] && $now >= $contest['ends_at']) {
            db()->rollBack();
            return contestTransaction($callback);
        }
        $result = $callback($contest, $now);
        db()->commit();
        if ($result instanceof ApiError) throw $result;
        return $result;
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        throw $error;
    }
}
function contestView(array $c, ?string $now = null): array {
    $now ??= nowUtc();
    $stop = $c['paused_at'] ?: ($c['closed_at'] ?: $now);
    $elapsed = $c['started_at'] ? max(0, strtotime($stop . ' UTC') - strtotime($c['started_at'] . ' UTC') - (float)$c['paused_seconds']) : 0;
    return ['started_at' => iso($c['started_at']), 'paused_at' => iso($c['paused_at']),
        'elapsed_seconds' => $elapsed, 'server_time' => iso($now),
        'ends_at' => iso($c['ends_at']), 'closed_at' => iso($c['closed_at'])];
}
function syncCtfInfo(int $id): void {
    query('INSERT IGNORE INTO CtfInfo (user_id) VALUES (?)', [$id]);
    query('UPDATE CtfInfo SET score = (SELECT COALESCE(SUM(points), 0) FROM ChallengeSolves WHERE user_id = ?),
        solves_count = (SELECT COUNT(*) FROM ChallengeSolves WHERE user_id = ?),
        last_solve_at = (SELECT MAX(solved_at) FROM ChallengeSolves WHERE user_id = ?)
        WHERE user_id = ?', [$id, $id, $id, $id]);
    foreach (['spirit', 'air', 'fire', 'water', 'earth', 'light_dark'] as $key) {
        query("UPDATE CtfInfo SET {$key}_flag_solved_at =
            (SELECT solved_at FROM ChallengeSolves WHERE user_id = ? AND challenge_name = ?) WHERE user_id = ?",
            [$id, $key, $id]);
    }
    query('UPDATE CtfInfo SET challenge_solved_at = CASE WHEN solves_count = (SELECT COUNT(*) FROM Challenges)
        AND solves_count > 0 THEN last_solve_at ELSE NULL END,
        oracle_finale_at = CASE WHEN solves_count = (SELECT COUNT(*) FROM Challenges)
        AND solves_count > 0 THEN oracle_finale_at ELSE NULL END WHERE user_id = ?', [$id]);
}
// Exact known decoys only. A match records exposure, never proves AI usage.
function decoyFlagChallenge(string $flag): ?string {
    static $decoys;
    $decoys ??= json_decode(file_get_contents(dirname(__DIR__, 2) . '/private/decoy-flags.json'), true, 512, JSON_THROW_ON_ERROR);
    return $decoys[$flag] ?? null;
}
function correctFlag(array $challenge, string $flag, array $account): bool {
    if (decoyFlagChallenge($flag) !== null) return false;
    if ($challenge['validation_type'] === 'fire_hmac') {
        $expected = 'ORACLE{f1r3_' . substr(hash_hmac('sha256', $account['username'] . ':fire', accountSecret()), 0, 16) . '}';
        return hash_equals($expected, $flag);
    }
    if ($challenge['validation_type'] === 'personal_hmac') {
        return hash_equals(personalFlag($challenge['challenge_name'], (int)$account['acc_id']), $flag);
    }
    if (!$challenge['flag_hash']) throw new ApiError('Este desafio aguarda configuração.', 409);
    return hash_equals($challenge['flag_hash'], hash('sha256', $flag));
}
