<?php
/**
 * MYSTICAL ORACLE CTF — FORJA / PORTÃO DO FOGO (Desafio 03 · fire)
 * Núcleo compartilhado: tokens de sessão assinados (stateless), flag por
 * jogador (HMAC) e helpers de isolamento.
 *
 * DECISÕES DE ARQUITETURA (importantes para o CTF):
 *  - Sessões são TOKENS ASSINADOS com HMAC (sem session_start do PHP).
 *    Isso mantém tudo stateless (escala p/ ~70) E permite requisições
 *    concorrentes de verdade (session_start bloquearia o arquivo de sessão
 *    e serializaria a race condition da etapa 2).
 *  - Todo estado (sessões falsas, travas, flag) é DERIVADO/ISOLADO por
 *    usuário. Um jogador nunca afeta o outro.
 */

const FIRE_WINDOW_MS = 300;   // janela em que as 4 travas precisam coincidir
const FIRE_SLEEP_MS  = 350;   // latência por trava -> força concorrência (não clique rápido)
const FIRE_LOCK_TTL  = 3;     // segundos que uma trava permanece "engatada" no APCu

function fire_account_secret(): string {
    return getenv('ACCOUNT_SECRET') ?: 'dev-account-secret-troque-em-producao';
}

/* ---------------- contas reais (SQLite) ---------------- */
function fire_db(): PDO {
    static $pdo;
    if ($pdo) return $pdo;
    $dir = getenv('FIRE_DATA') ?: '/var/www/data';
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    $pdo = new PDO('sqlite:' . $dir . '/fire.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout=5000');
    $pdo->exec('PRAGMA journal_mode=WAL');          // melhor concorrência de leitura (~70 alunos)
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        username   TEXT UNIQUE NOT NULL,
        pass_hash  TEXT NOT NULL,
        role       TEXT NOT NULL DEFAULT \'player\',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    // Only old databases need DDL; do not attempt a failing ALTER on every request.
    $columns = $pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('role', $columns, true)) {
        try { $pdo->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'player'"); }
        catch (Throwable $e) {
            // Another worker may have migrated the same legacy database first.
            $columns = $pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('role', $columns, true)) throw $e;
        }
    }
    return $pdo;
}

/* ---------------- tokens de sessão (HMAC, stateless) ---------------- */
function b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64url_dec(string $s): string { return base64_decode(strtr($s, '-_', '+/')); }

function fire_sign(array $payload): string {
    $body = b64url(json_encode($payload, JSON_UNESCAPED_UNICODE));
    $sig  = b64url(hash_hmac('sha256', $body . fire_progress_suffix(), fire_account_secret(), true));
    return $body . '.' . $sig;
}

function fire_verify(?string $token): ?array {
    if (!$token || substr_count($token, '.') !== 1) return null;
    [$body, $sig] = explode('.', $token, 2);
    $calc = b64url(hash_hmac('sha256', $body . fire_progress_suffix(), fire_account_secret(), true));
    if (!hash_equals($calc, $sig)) return null;          // assinatura inválida -> sessão forjada
    $data = json_decode(b64url_dec($body), true);
    return is_array($data) ? $data : null;
}

/**
 * Sessão atual, normalizada em {role, user?}.
 *  - token com 'su'  -> superadmin (Guardião Supremo) da instância <su>
 *  - token com 'u'   -> conta real; o PAPEL vem do BANCO (player|admin),
 *                       então promover a conta muda o papel na hora.
 */
function fire_current(): ?array {
    $s = fire_verify($_COOKIE['sessions'] ?? null);
    if (!$s) return null;
    $portalUser = fire_portal_user();
    if (isset($s['su']) && hash_equals($portalUser, (string)$s['su'])) {
        return ['role' => 'superadmin', 'user' => $portalUser];
    }
    if (isset($s['u']) && hash_equals($portalUser, (string)$s['u'])) {
        return ['role' => fire_role($portalUser), 'user' => $portalUser];
    }
    return null;
}

/* exige um papel; encerra com 401 se não bater */
function fire_require(string $role): array {
    $s = fire_current();
    if (!$s || ($s['role'] ?? '') !== $role) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'sessao invalida ou sem permissao para esta area']);
        exit;
    }
    return $s;
}

/* papel persistido da conta (player por padrão; admin = autorizada) */
function fire_role(string $user): string {
    try {
        $st = fire_db()->prepare('SELECT role FROM users WHERE username = ?');
        $st->execute([$user]);
        $r = $st->fetchColumn();
        return ($r === 'admin') ? 'admin' : 'player';
    } catch (Throwable $e) { return 'player'; }
}

/* promove a conta a autorizada (admin). Usada pelo painel do superadmin. */
function fire_promote(string $user): bool {
    $st = fire_db()->prepare('UPDATE users SET role = ? WHERE username = ?');
    $st->execute(['admin', $user]);
    return $st->rowCount() > 0;
}

/* nome de usuário saneado (identifica a instância isolada do jogador) */
function fire_clean_user(string $u): string {
    $u = trim($u);
    // Preserve the signed portal identity exactly: the flag HMAC uses it too.
    return preg_match('/^[\p{L}\p{N}_.-]{1,32}$/uD', $u) === 1 ? $u : '';
}

/* ---------------- flag por jogador (HMAC; server-side only) ---------------- */
function fire_flag(string $user): string {
    $h = hash_hmac('sha256', $user . ':fire', fire_account_secret());
    return 'ORACLE{f1r3_' . substr($h, 0, 16) . '}';
}

/* ---------------- tokens de sessão desta instância ---------------- */
/* conta comum: só o identificador; o papel vem do banco */
function fire_player_token(string $user): string {
    $token = fire_sign(['u' => $user]);
    // Até usernames mínimos produzem uma entrada visível na faixa 60–100.
    return strlen($token) >= 60 ? $token : fire_sign(['u' => $user, 'p' => '']);
}
/* sessão do GUARDIÃO SUPREMO (superadmin), por instância.
   Payload maior de propósito -> aparece como o arquivo MAIOR em /sessions.
   Curto o suficiente para copiar sem erro no cookie. */
function fire_super_token(string $user): string {
    $sealLength = max(8, 32 - strlen($user));
    return fire_sign([
        'su' => $user,
        'grant' => 'guardiao-supremo',
        'can' => ['users:authorize', 'users:list', 'forge:admin'],
        // Compensa o tamanho do username e mantém o token entre 200–250 bytes.
        'seal' => substr(hash_hmac('sha256', $user . ':guardian-seal', fire_account_secret()), 0, $sealLength),
    ]);
}

/* JSON helper */
function fire_json($data): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* O ingresso assinado pelo portal é a única fonte aceita para o usuário. */
function fire_progress_suffix(): string {
    return oracle_progress_suffix((int)(challenge_identity()['uid'] ?? 0));
}

function fire_state_key(string $user, string $state): string {
    return 'fire:' . $user . fire_progress_suffix() . ':' . $state;
}

function fire_portal_user(): string {
    $identity = challenge_identity();
    $user = fire_clean_user((string)($identity['username'] ?? ''));
    if ($user === '') {
        http_response_code(401);
        fire_json(['error' => 'identidade do portal inválida']);
    }
    return $user;
}

/*
 * A sessão interna do desafio (cookie `sessions`) faz parte do ataque, mas
 * não substitui o login do portal. Todo endpoint da Forja também exige o
 * ingresso assinado `oracle_challenge` emitido ao jogador autenticado.
 */
require_once __DIR__ . '/challenge-auth.php';
if (!challenge_identity()) {
    http_response_code(401);
    fire_json(['error' => 'Entre no portal e abra o desafio novamente.']);
}
