<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$sessionTtl = max(300, (int)(getenv('SESSION_TTL') ?: 21600));
$sessionPath = getenv('SESSION_PATH') ?: '/var/www/sessions';
$cookieSecure = oracle_cookie_secure();
ini_set('session.use_strict_mode', '1');
ini_set('session.gc_maxlifetime', (string)$sessionTtl);
ini_set('session.save_path', $sessionPath);
session_name('ORACLESESS');
session_set_cookie_params(['lifetime' => $sessionTtl, 'path' => '/', 'httponly' => true,
    'samesite' => 'Lax', 'secure' => $cookieSecure]);
$timerRequest = ($_GET['action'] ?? '') === 'status';
if ($timerRequest) {
    // Polling must never create/replace a browser cookie during login.
    $incomingSid = $_COOKIE[session_name()] ?? '';
    if (is_string($incomingSid) && preg_match('/^[A-Za-z0-9,-]{16,128}$/D', $incomingSid)) session_id($incomingSid);
}
if ($timerRequest && (!isset($incomingSid) || !is_string($incomingSid)
    || !preg_match('/^[A-Za-z0-9,-]{16,128}$/D', $incomingSid)
    || !is_file($sessionPath . '/sess_' . $incomingSid))) {
    $_SESSION = [];
} else {
    session_start(['use_cookies' => !$timerRequest]);
}
if (isset($_SESSION['uid'], $_SESSION['last_activity']) && time() - (int)$_SESSION['last_activity'] > $sessionTtl) {
    rotatePortalSession();
}
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
if (isset($_SESSION['uid'])) {
    $_SESSION['last_activity'] = time();
    if (!$timerRequest) setcookie(session_name(), session_id(), ['expires' => time() + $sessionTtl, 'path' => '/',
        'secure' => $cookieSecure, 'httponly' => true, 'samesite' => 'Lax']);
}

try {
    $action = $_GET['action'] ?? 'me';
    $readActions = ['bootstrap', 'status', 'me', 'leaderboard', 'snapshots', 'snapshot', 'launch', 'accounts_page', 'accounts', 'logs_page', 'logs', 'log_accounts', 'whispers'];
    $writeActions = ['register', 'login', 'logout', 'profile', 'change_password', 'question', 'submit', 'reset', 'reset_contest', 'save_snapshot', 'start', 'pause', 'resume', 'schedule', 'close', 'update_accounts', 'ban_account', 'read_account_logs', 'reset_application', 'oracle_finale', 'prepare_oracle_finale', 'send_whisper'];
    if (!is_string($action) || !in_array($action, array_merge($readActions, $writeActions), true)) {
        throw new ApiError('Ação desconhecida.', 404);
    }
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method !== (in_array($action, $readActions, true) ? 'GET' : 'POST')) {
        throw new ApiError('Método não permitido.', 405);
    }
    $data = [];
    if ($method === 'POST') {
        if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
            if ($action === 'submit' && isset($_SESSION['uid'])) {
                $actor = query('SELECT * FROM Account WHERE acc_id = ?', [$_SESSION['uid']])->fetch();
                if ($actor) systemLog($actor, 'flag_submit', false, 'Tentativa rejeitada: sessão/CSRF mudou.');
            }
            jsonResponse(['error' => 'A sessão mudou. Recaregue a página.', 'code' => 'csrf_expired'], 403);
        }
        $maxBody = $action === 'update_accounts' ? 1048576 : 8192;
        $raw = file_get_contents('php://input', false, null, 0, $maxBody + 1);
        if (strlen($raw) > $maxBody) throw new ApiError('Solicitação muito grande.', 413);
        $data = json_decode($raw, true);
        if (!is_array($data)) throw new ApiError('Solicitação inválida.');
    }
    if ($action === 'bootstrap') {
        $authenticated = false;
        $role = null;
        if (isset($_SESSION['uid'])) {
            try { $role = currentAccount()['acc_role']; $authenticated = true; }
            catch (ApiError $error) { if ($error->status !== 401) throw $error; }
        }
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
        jsonResponse(['csrf' => $_SESSION['csrf'], 'authenticated' => $authenticated, 'role' => $role]);
    }
    if ($action === 'status') {
        $lost = false;
        $admin = false;
        $authenticatedAccount = null;
        if (isset($_SESSION['uid'])) {
            try { $authenticatedAccount = currentAccount(); $admin = $authenticatedAccount['acc_role'] === 'admin'; } catch (ApiError $e) { $lost = true; }
        }
        $contest = contestRead(fn($c, $now) => contestView($c, $now));
        $status = ['contest' => $contest, 'session_lost' => $lost, 'authenticated' => isset($_SESSION['uid']), 'is_admin' => $admin];
        if ($authenticatedAccount && isset($_GET['whispers_after'])) $status['whispers'] = oracleWhispers();
        jsonResponse($status);
    }
    if ($action === 'register') {
        $username = strtolower(textField($data, 'username', 32));
        $alias = $username;
        $email = strtolower(textField($data, 'email', 254));
        $password = $data['password'] ?? '';
        if (!preg_match('/^[a-z0-9_.-]{3,32}$/D', $username)) throw new ApiError('Use de 3 a 32 caracteres: a-z, 0-9, ponto, hífen ou sublinhado.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ApiError('Informe um e-mail válido.');
        if (!validAccountPassword($password)) throw new ApiError('Use ao menos 9 caracteres, uma letra maiúscula e um símbolo.');
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $result = contestTransaction(function ($c) use ($username, $alias, $email, $hash) {
            if ($c['closed_at']) return new ApiError('O CTF está encerrado.', 409);
            try {
                query('INSERT INTO Account (username, alias, email, password_hash, ignore_leaderboard) VALUES (?, ?, ?, ?, FALSE)', [$username, $alias, $email, $hash]);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) return new ApiError('Nome de usuário ou e-mail já cadastrado.', 409);
                throw $e;
            }
            $id = (int)db()->lastInsertId();
            query('INSERT INTO CtfInfo (user_id) VALUES (?)', [$id]);
            return ['ok' => true];
        });
        jsonResponse($result, 201);
    }
    if ($action === 'login') {
        $email = strtolower(textField($data, 'email', 254));
        $password = $data['password'] ?? '';
        $validPasswordFormat = is_string($password) && strlen($password) <= 72;
        // BCrypt is CPU work: do it before taking the event's global write lock.
        // Re-read under the lock so password changes/bans during verification win.
        $candidate = query('SELECT acc_id, password_hash, login_blocked_until FROM Account WHERE email = ?', [$email])->fetch();
        if ($candidate && $candidate['login_blocked_until'] && ($locked = loginLockError($candidate, nowUtc()))) throw $locked;
        $verified = $validPasswordFormat && $candidate && password_verify($password, $candidate['password_hash']);
        $account = contestTransaction(function ($contest, $now) use ($email, $password, $validPasswordFormat, $candidate, $verified) {
            $account = query('SELECT * FROM Account WHERE email = ?', [$email])->fetch();
            if ($account && ($locked = loginLockError($account, $now))) return $locked;
            $sameCredentials = $account && $candidate && $account['acc_id'] === $candidate['acc_id']
                && $account['password_hash'] === $candidate['password_hash'];
            $valid = $sameCredentials ? $verified : ($validPasswordFormat && $account && password_verify($password, $account['password_hash']));
            if (!$account || !$valid) {
                $locked = null;
                if ($account) {
                    $account = recordLoginFailure($account, $now, LOGIN_MAX_FAILURES);
                    $locked = loginLockError($account, $now);
                }
                $warning = $locked ? 'Bloqueio de login por 30 minutos após ' . LOGIN_MAX_FAILURES . ' senhas incorretas.' : null;
                systemLog($account ?: null, 'login', false, $warning ?? 'Credenciais inválidas.', null, null, null, $warning);
                return $locked ?? new ApiError('Credenciais inválidas.', 401);
            }
            if ($account['login_failed_attempts'] || $account['login_blocked_until']) {
                query('UPDATE Account SET login_failed_attempts = 0, login_blocked_until = NULL WHERE acc_id = ?', [$account['acc_id']]);
            }
            if ($account['is_banned']) {
                systemLog($account, 'login', false, 'Tentativa de login em conta banida.', null, null, null, 'Login de conta banida.');
                return new ApiError('Sua conta foi banida pelo administrador. Entre em contato com a organização.', 401);
            }
            if (!in_array($account['acc_role'], ['admin', 'logs'], true) && !$account['ignore_leaderboard'] && !$contest['started_at']) {
                return new ApiError('O Jogo ainda não começou.', 409);
            }
            if (!in_array($account['acc_role'], ['admin', 'logs'], true) && !$account['ignore_leaderboard'] && $contest['paused_at']) {
                return new ApiError('O CTF está pausado. Aguarde a retomada.', 409);
            }
            $account['contest_epoch'] = (int)$contest['session_epoch'];
            return $account;
        });
        rotatePortalSession();
        $_SESSION['uid'] = (int)$account['acc_id'];
        $_SESSION['last_activity'] = time();
        $_SESSION['contest_epoch'] = $account['contest_epoch'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        setcookie(session_name(), session_id(), ['expires' => time() + $sessionTtl, 'path' => '/',
            'secure' => $cookieSecure, 'httponly' => true, 'samesite' => 'Lax']);
        query('UPDATE Account SET last_login = UTC_TIMESTAMP(6) WHERE acc_id = ?', [$account['acc_id']]);
        systemLog($account, 'login', true, 'Login realizado.');
        jsonResponse(['ok' => true, 'csrf' => $_SESSION['csrf'], 'redirect' => $account['acc_role'] === 'logs' ? '/logs' : '/oracle']);
    }
    $account = currentAccount();
    $id = (int)$account['acc_id'];
    if ($account['acc_role'] === 'logs' && !in_array($action, ['logout', 'me', 'logs_page', 'logs', 'log_accounts', 'read_account_logs'], true)) {
        throw new ApiError('Esta conta tem acesso somente aos logs.', 403);
    }
    if (in_array($action, ['accounts_page', 'accounts', 'update_accounts', 'ban_account', 'reset_application', 'send_whisper'], true)) requireAdmin($account);
    if (in_array($action, ['logs_page', 'logs', 'log_accounts', 'read_account_logs'], true)
        && !in_array($account['acc_role'], ['admin', 'logs'], true)) {
        throw new ApiError('Esta conta não pode consultar os logs.', 403);
    }
    if ($action === 'whispers') jsonResponse(['whispers' => oracleWhispers()]);
    if ($action === 'accounts_page' || $action === 'logs_page') {
        header('Content-Type: text/html; charset=utf-8');
        readfile(dirname(__DIR__, 2) . '/private/' . ($action === 'logs_page' ? 'logs/logs.html' : 'accounts.html'));
        exit;
    }
    if ($action === 'logs') {
        $kind = $_GET['kind'] ?? 'flags';
        if (!in_array($kind, ['flags', 'warnings', 'account'], true)) throw new ApiError('Tipo de log inválido.');
        $user = $kind === 'account' ? filter_var($_GET['user_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
        $before = filter_var($_GET['before'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($user === false || $before === false) throw new ApiError('Filtro inválido.');
        jsonResponse(logRows($kind, $user, $before, $account['acc_role'] === 'logs'));
    }
    if ($action === 'log_accounts') jsonResponse(['accounts' => logAccounts($id)]);
    if ($action === 'launch') {
        $key = $_GET['challenge'] ?? '';
        if (!is_string($key) || !preg_match('/^[a-z_]{2,20}$/D', $key)) throw new ApiError('Desafio inválido.');
        $challenge = query('SELECT * FROM Challenges WHERE challenge_name = ?', [$key])->fetch();
        if (!$challenge) throw new ApiError('Desafio não encontrado.', 404);
        if (!$challenge['enabled']) throw new ApiError('Este desafio ainda não está disponível.', 409);
        header('Location: ' . challengeLaunchUrl($account, $key), true, 302);
        exit;
    }
    if ($action === 'logout') {
        systemLog($account, 'logout', true, 'Sessão encerrada pelo usuário.');
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $params['path'],
            'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Lax']);
        session_destroy();
        jsonResponse(['ok' => true]);
    }
    if (in_array($action, ['save_snapshot', 'start', 'pause', 'resume', 'reset_contest', 'reset_application', 'schedule', 'close'], true)) requireAdmin($account);
    $contestOperation = in_array($action, ['me', 'leaderboard', 'snapshots', 'snapshot', 'accounts'], true)
        ? 'contestRead' : 'contestTransaction';
    $sessionsToDestroy = [];
    $result = $contestOperation(function ($contest, $now) use ($action, $data, $account, $id, &$sessionsToDestroy) {
        $fresh = query('SELECT is_banned FROM Account WHERE acc_id = ?', [$id])->fetchColumn();
        if ($fresh) {
            rotatePortalSession();
            return new ApiError('Sua conta foi banida pelo administrador. Entre em contato com a organização.', 401);
        }
        try { assertContestAccess($account, $contest); } catch (ApiError $e) { return $e; }
        if ($action === 'send_whisper') {
            $author = query('SELECT * FROM Account WHERE acc_id = ? FOR UPDATE', [$id])->fetch();
            requireAdmin($author);
            $message = textField($data, 'message', 4000);
            if (!preg_match('/\A[\s\S]{1,1000}\z/u', $message) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $message)) {
                return new ApiError('Escreva uma mensagem de até 1000 caracteres.');
            }
            query('INSERT INTO OracleWhispers (author_id, message, created_at) VALUES (?, ?, ?)', [$id, $message, $now]);
            $whisperId = (int)db()->lastInsertId();
            systemLog($author, 'admin_whisper', true, 'Publicou o sussurro #' . $whisperId . '.');
            return ['message' => ['id' => $whisperId, 'message' => $message, 'createdAt' => iso($now)]];
        }
        if ($action === 'accounts') return ['accounts' => accountList()];
        if ($action === 'read_account_logs') {
            $target = $data['user_id'] ?? null; $latest = $data['last_log_id'] ?? null;
            if (!is_int($target) || !is_int($latest) || $target <= 0 || $latest < 0) return new ApiError('Leitura inválida.');
            if (!query('SELECT 1 FROM Account WHERE acc_id = ?', [$target])->fetchColumn()) return new ApiError('Conta não encontrada.', 404);
            $max = (int)query('SELECT COALESCE(MAX(log_id), 0) FROM SystemLogs WHERE user_id = ?', [$target])->fetchColumn();
            if ($latest > $max) return new ApiError('Registro inválido.');
            query('INSERT INTO AdminLogReads (admin_id, user_id, last_log_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE last_log_id = GREATEST(last_log_id, VALUES(last_log_id))', [$id, $target, $latest]);
            return ['ok' => true];
        }
        if ($action === 'ban_account') {
            $target = $data['user_id'] ?? null;
            if (!is_int($target) || $target <= 0) return new ApiError('Conta inválida.');
            $victim = query('SELECT * FROM Account WHERE acc_id = ? FOR UPDATE', [$target])->fetch();
            if (!$victim) return new ApiError('Conta não encontrada.', 404);
            if ($victim['acc_role'] === 'admin') return new ApiError('Contas de administrador não podem ser banidas.', 403);
            if (!$victim['is_banned']) {
                query('UPDATE Account SET is_banned = 1 WHERE acc_id = ?', [$target]);
                systemLog($victim, 'ban', false, 'Conta banida pelo administrador ' . $account['username'] . '.');
                systemLog($account, 'admin_ban', true, 'Baniu a conta ' . $victim['username'] . ' (#' . $target . ').');
            }
            return ['ok' => true, 'accounts' => accountList()];
        }
        if ($action === 'update_accounts') {
            $changes = $data['accounts'] ?? null;
            if (!is_array($changes) || !array_is_list($changes) || count($changes) > 5000) {
                return new ApiError('Lista de alterações inválida.');
            }
            $ids = [];
            foreach ($changes as $change) {
                if (!is_array($change) || !is_int($change['id'] ?? null) || $change['id'] <= 0
                    || !is_bool($change['ignore_leaderboard'] ?? null) || isset($ids[$change['id']])) {
                    return new ApiError('Alteração de conta inválida.');
                }
                $ids[$change['id']] = true;
            }
            if ($ids) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $found = query("SELECT acc_id FROM Account WHERE acc_id IN ($placeholders) FOR UPDATE", array_keys($ids))->fetchAll();
                if (count($found) !== count($ids)) return new ApiError('Uma das contas não existe mais. Atualize a listagem.', 409);
                foreach ($changes as $change) {
                    query('UPDATE Account SET ignore_leaderboard = ? WHERE acc_id = ?', [(int)$change['ignore_leaderboard'], $change['id']]);
                    $target = query('SELECT * FROM Account WHERE acc_id = ?', [$change['id']])->fetch();
                    systemLog($target, 'account_type', true, 'Administrador ' . $account['username'] . ' alterou a conta para ' . ($change['ignore_leaderboard'] ? 'teste.' : 'jogador.'));
                }
            }
            return ['ok' => true, 'accounts' => accountList()];
        }
        if ($action === 'reset_application') {
            if (($data['confirmation'] ?? null) !== 'RESETAR CTF') {
                return new ApiError('Confirmação inválida. O reset não foi executado.');
            }
            $accounts = query('SELECT * FROM Account ORDER BY acc_id FOR UPDATE')->fetchAll();
            $adminId = null; $gandalfId = null; $theffzId = null;
            foreach ($accounts as $target) {
                $username = strtolower($target['username']);
                $email = strtolower((string)($target['email'] ?? ''));
                if ($username === 'admin' && $target['acc_role'] === 'admin') $adminId = (int)$target['acc_id'];
                if ($username === 'gandalf') $gandalfId = (int)$target['acc_id'];
                if ($email === 'theffz@oracle.mystical') $theffzId = (int)$target['acc_id'];
            }
            $coreAccountIds = array_values(array_filter([$adminId, $gandalfId, $theffzId]));
            $logsViewerIds = array_map('intval', query("SELECT acc_id FROM Account WHERE acc_role = 'logs'")->fetchAll(PDO::FETCH_COLUMN));
            $preservedIds = array_values(array_unique(array_merge($coreAccountIds, $logsViewerIds)));
            if (count($coreAccountIds) !== 3) {
                return new ApiError('O reset exige as contas Admin, Gandalf e TheFFZ; confirme os cadastros antes de tentar novamente.', 409);
            }
            if ($id !== $adminId) return new ApiError('Entre pela conta Admin principal para executar o reset.', 403);

            resetAllChallengeState($accounts);
            $sessionsToDestroy = array_values(array_diff(array_map(fn($row) => (int)$row['acc_id'], $accounts), [$id]));
            query('DELETE FROM Warnings');
            query('DELETE FROM SystemLogs');
            query('DELETE FROM FlagSubmissions');
            query('DELETE FROM ChallengeSolves');
            query('DELETE FROM AdminLogReads');
            query('DELETE FROM OracleWhispers');
            query('DELETE FROM LeaderboardSnapshots');
            query('DELETE FROM CtfInfo');
            $preserveMarks = implode(',', array_fill(0, count($preservedIds), '?'));
            query("DELETE FROM Account WHERE acc_id NOT IN ($preserveMarks)", $preservedIds);
            query("UPDATE Account SET questions_count = 0, has_crown = 0, crowned_at = NULL,
                ctf_completed_at = NULL, is_banned = 0, login_failed_attempts = 0,
                login_blocked_until = NULL, last_login = NULL WHERE acc_id IN ($preserveMarks)", $preservedIds);
            foreach ($preservedIds as $preservedId) query('INSERT INTO CtfInfo (user_id) VALUES (?)', [$preservedId]);
            query('UPDATE ContestState SET started_at = NULL, paused_at = NULL, paused_seconds = 0,
                ends_at = NULL, closed_at = NULL, session_epoch = session_epoch + 1 WHERE id = 1');
            return ['ok' => true, 'removedAccounts' => count($accounts) - count($preservedIds),
                'accounts' => accountList(), 'contest' => contestView(query('SELECT * FROM ContestState WHERE id = 1')->fetch())];
        }
        if ($action === 'reset_contest') {
            query('DELETE FROM ChallengeSolves');
            query('UPDATE CtfInfo SET spirit_flag_solved_at = NULL, air_flag_solved_at = NULL,
                fire_flag_solved_at = NULL, water_flag_solved_at = NULL, earth_flag_solved_at = NULL,
                light_dark_flag_solved_at = NULL, challenge_solved_at = NULL, oracle_finale_at = NULL, last_solve_at = NULL,
                solves_count = 0, score = 0');
            // Mantém os registros anteriores e libera o marcador final para a nova rodada.
            query('UPDATE LeaderboardSnapshots SET final_marker = NULL WHERE final_marker = 1');
            query('UPDATE ContestState SET started_at = NULL, paused_at = NULL, paused_seconds = 0,
                ends_at = NULL, closed_at = NULL, session_epoch = session_epoch + 1 WHERE id = 1');
            return ['contest' => contestView(query('SELECT * FROM ContestState WHERE id = 1')->fetch())];
        }
        if ($action === 'pause' || $action === 'resume') {
            if (!$contest['started_at'] || $contest['closed_at']) return new ApiError('O CTF precisa estar aberto.', 409);
            if ($action === 'pause' && !$contest['paused_at']) {
                query('UPDATE ContestState SET paused_at = ?, session_epoch = session_epoch + 1 WHERE id = 1', [$now]);
            } elseif ($action === 'resume' && $contest['paused_at']) {
                query('UPDATE ContestState SET ends_at = IF(ends_at IS NULL, NULL, TIMESTAMPADD(MICROSECOND, TIMESTAMPDIFF(MICROSECOND, paused_at, ?), ends_at)), paused_seconds = paused_seconds + TIMESTAMPDIFF(MICROSECOND, paused_at, ?) / 1000000, paused_at = NULL WHERE id = 1', [$now, $now]);
            }
            return ['contest' => contestView(query('SELECT * FROM ContestState WHERE id = 1')->fetch())];
        }
        if ($action === 'me' && $account['acc_role'] === 'logs') return ['profile' => ['role' => 'logs'], 'challenges' => [],
            'contest' => contestView($contest), 'csrf' => $_SESSION['csrf']];
        if ($action === 'me') return ['profile' => profile($account), 'challenges' => challenges(),
            'contest' => contestView($contest), 'csrf' => $_SESSION['csrf']];
        if ($action === 'leaderboard') {
            $board = $account['acc_role'] === 'admin' || !$account['ignore_leaderboard'] ? 'players' : 'testers';
            $requestedBoard = $_GET['board'] ?? $board;
            if (!in_array($requestedBoard, ['players', 'testers'], true)) return new ApiError('Classificação inválida.', 400);
            if ($requestedBoard !== $board && $account['acc_role'] !== 'admin') {
                return new ApiError('Você não tem acesso a essa classificação.', 403);
            }
            $testAccounts = $requestedBoard === 'testers';
            $snapshot = !$testAccounts && $contest['closed_at'] ? finalSnapshot() : null;
            return ['board' => $requestedBoard,
                'rows' => $snapshot ? leaderboardCrownStatus($snapshot['rows']) : leaderboard($now, $testAccounts),
                'contest' => contestView($contest),
                'snapshot_id' => $snapshot['id'] ?? null, 'server_time' => iso($now)];
        }
        if (in_array($action, ['snapshots', 'snapshot'], true) && $account['ignore_leaderboard']) {
            requireAdmin($account);
        }
        if ($action === 'snapshots') {
            return ['snapshots' => array_map(fn($s) => ['id' => (int)$s['id'],
                'captured_at' => iso($s['captured_at']), 'cutoff_at' => iso($s['cutoff_at']),
                'final' => (bool)($s['final_marker'] || (json_decode($s['payload'], true)['final'] ?? false))],
                query('SELECT id, captured_at, cutoff_at, final_marker, payload FROM LeaderboardSnapshots ORDER BY id DESC')->fetchAll())];
        }
        if ($action === 'snapshot') {
            $snapshotId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
            $s = query('SELECT id, payload FROM LeaderboardSnapshots WHERE id = ?', [$snapshotId ?: 0])->fetch();
            if (!$s) return new ApiError('Registro não encontrado.', 404);
            $payload = json_decode($s['payload'], true, 512, JSON_THROW_ON_ERROR);
            $payload['rows'] = leaderboardCrownStatus($payload['rows'] ?? []);
            return ['snapshot' => ['id' => (int)$s['id']] + $payload];
        }
        if ($action === 'save_snapshot' || $action === 'close') {
            if ($contest['closed_at']) return ['snapshot' => finalSnapshot(), 'contest' => contestView($contest)];
            $final = $action === 'close';
            if ($final) {
                query('UPDATE ContestState SET closed_at = ? WHERE id = 1', [$now]);
                $contest['closed_at'] = $now;
            }
            return ['snapshot' => saveSnapshot($now, $final, $id), 'contest' => contestView($contest)];
        }
        if ($action === 'start') {
            if ($contest['closed_at']) return new ApiError('O CTF está encerrado.', 409);
            if (!$contest['started_at']) {
                query('UPDATE ContestState SET started_at = ? WHERE id = 1', [$now]);
                $contest['started_at'] = $now;
            }
            return ['contest' => contestView($contest)];
        }
        if ($action === 'change_password') {
            $current = $data['current_password'] ?? null;
            $password = $data['new_password'] ?? null;
            $confirmation = $data['confirm_password'] ?? null;
            if (!is_string($current) || strlen($current) > 72) return new ApiError('Senha atual incorreta.');
            if (!validAccountPassword($password)) {
                return new ApiError('Use ao menos 9 caracteres, uma letra maiúscula e um símbolo.');
            }
            if (!is_string($confirmation) || !hash_equals($password, $confirmation)) {
                return new ApiError('A confirmação não corresponde à nova senha.');
            }
            $hash = query('SELECT password_hash FROM Account WHERE acc_id = ? FOR UPDATE', [$id])->fetchColumn();
            if (!$hash || !password_verify($current, $hash)) return new ApiError('Senha atual incorreta.');
            query('UPDATE Account SET password_hash = ? WHERE acc_id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
            systemLog($account, 'password_change', true, 'Senha alterada.');
            return ['ok' => true];
        }
        if ($action === 'oracle_finale' || $action === 'prepare_oracle_finale') {
            $progress = profile($account);
            if ($account['acc_role'] !== 'player' || !$progress['allFlagsSolved']) {
                return new ApiError('Complete todos os desafios para receber o último olhar do Oráculo.', 409);
            }
            if ($action === 'prepare_oracle_finale') {
                if (!$progress['hasCrown']) return new ApiError('Receba a coroa antes de rever o final.', 409);
                query('UPDATE CtfInfo SET oracle_finale_at = NULL WHERE user_id = ?', [$id]);
            } else {
                query('UPDATE Account SET has_crown = TRUE, crowned_at = COALESCE(crowned_at, ?) WHERE acc_id = ?', [$now, $id]);
                query('UPDATE CtfInfo SET oracle_finale_at = COALESCE(oracle_finale_at, ?) WHERE user_id = ?', [$now, $id]);
            }
            return ['profile' => profile($account)];
        }
        if ($contest['closed_at']) return new ApiError('O CTF está encerrado. A classificação final foi preservada.', 409);
        if ($action === 'schedule') {
            $value = textField($data, 'ends_at', 40);
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d\TH:i:s\Z') !== $value) return new ApiError('Informe uma data válida.');
            $deadline = $date->format('Y-m-d H:i:s.000000');
            if ($deadline <= $now) return new ApiError('O encerramento deve estar no futuro.');
            query('UPDATE ContestState SET ends_at = ? WHERE id = 1', [$deadline]);
            $contest['ends_at'] = $deadline;
            return ['contest' => contestView($contest)];
        }
        if ($action === 'profile') {
            $alias = textField($data, 'alias', 180);
            if ($alias === '' || preg_match_all('/./us', $alias) > 60) return new ApiError('Informe um nome de até 60 caracteres.');
            query('UPDATE Account SET alias = ? WHERE acc_id = ?', [$alias, $id]);
            systemLog($account, 'profile_change', true, 'Nome de exibição alterado.');
        } elseif ($action === 'question') {
            query('UPDATE Account SET questions_count = questions_count + 1 WHERE acc_id = ?', [$id]);
        } elseif ($action === 'reset') {
            resetChallengeState($account);
            query('DELETE FROM ChallengeSolves WHERE user_id = ?', [$id]);
            query('UPDATE Account SET questions_count = 0 WHERE acc_id = ?', [$id]);
            syncCtfInfo($id);
            systemLog($account, 'progress_reset', true, 'Progresso e estados dos desafios redefinidos.');
        } elseif ($action === 'submit') {
            if ($account['acc_role'] !== 'player') return new ApiError('Use uma conta de jogador para submeter flags.', 403);
            $key = textField($data, 'challenge', 20);
            $flag = textField($data, 'flag', 255);
            if (!preg_match('/^ORACLE\{[^{}\r\n]+\}$/D', $flag)) return new ApiError('Use o formato ORACLE{...}, respeitando maiúsculas e minúsculas.');
            $challenge = query('SELECT * FROM Challenges WHERE challenge_name = ?', [$key])->fetch();
            if (!$challenge) return new ApiError('Desafio não encontrado.', 404);
            if (!$challenge['enabled']) return new ApiError('Este desafio aguarda configuração.', 409);
            $correct = correctFlag($challenge, $flag, $account);
            query('INSERT INTO FlagSubmissions (user_id, challenge_name, submitted_flag, is_correct, submitted_at, ip_address)
                VALUES (?, ?, ?, ?, ?, ?)', [$id, $key, $flag, (int)$correct, $now, accessIp()]);
            $submissionId = (int)db()->lastInsertId();
            $decoy = decoyFlagChallenge($flag);
            $warning = $decoy === null ? null : 'Submissão de flag-isca publicada no desafio ' . $decoy . '. Pode ter sido copiada manualmente; não comprova uso de IA.';
            $detail = $decoy !== null
                ? 'Flag honeypot enviada para o desafio ' . $decoy . '.'
                : ($correct ? 'Flag correta.' : 'Flag incorreta.');
            systemLog($account, 'flag_submit', $correct, $detail,
                $key, $flag, $submissionId, $warning);
            $already = false;
            if ($correct) {
                $already = (bool)query('SELECT 1 FROM ChallengeSolves WHERE user_id = ? AND challenge_name = ?', [$id, $key])->fetchColumn();
                if (!$already) {
                    query('INSERT INTO ChallengeSolves (user_id, challenge_name, points, solved_at) VALUES (?, ?, ?, ?)',
                        [$id, $key, $challenge['points'], $now]);
                    syncCtfInfo($id);
                }
            }
            return ['correct' => $correct, 'already_solved' => $already,
                'points' => $correct && !$already ? (int)$challenge['points'] : 0, 'profile' => profile($account)];
        }
        return ['ok' => true, 'profile' => profile(currentAccount())];
    });
    if ($action === 'ban_account') destroyAccountSessions($data['user_id']);
    foreach ($sessionsToDestroy as $sessionAccountId) destroyAccountSessions((int)$sessionAccountId);
    if ($action === 'reset_application') systemLog($account, 'admin_reset_application', true, 'Resetou o CTF para uma nova rodada; Admin, Gandalf, TheFFZ e as contas com papel logs foram preservadas.');
    elseif (in_array($action, ['start', 'pause', 'resume', 'reset_contest', 'save_snapshot', 'schedule', 'close'], true)) systemLog($account, 'admin_' . $action, true, 'Comando administrativo: ' . $action . '.');
    jsonResponse($result);
} catch (ApiError $e) {
    if (($action ?? '') === 'submit') {
        $actor = $account ?? (isset($_SESSION['uid']) ? query('SELECT * FROM Account WHERE acc_id = ?', [$_SESSION['uid']])->fetch() : null);
        if (is_array($actor)) systemLog($actor, 'flag_submit', false, $e->getMessage(), is_string($data['challenge'] ?? null) ? $data['challenge'] : null, is_string($data['flag'] ?? null) ? $data['flag'] : null);
    }
    jsonResponse(['error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log((string)$e);
    jsonResponse(['error' => 'Não foi possível concluir a solicitação. Tente novamente.'], 500);
}
