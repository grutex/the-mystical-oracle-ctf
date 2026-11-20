<?php
declare(strict_types=1);

// Estado e recibos da cadeia. Este arquivo é instalado fora da raiz HTTP.
function water_chain_db(): PDO {
    static $db;
    if ($db) return $db;
    $directory = getenv('WATER_DATA') ?: '/var/www/data';
    if (!is_dir($directory)) mkdir($directory, 0770, true);
    $db = new PDO('sqlite:' . $directory . '/water.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('CREATE TABLE IF NOT EXISTS water_entries (
        acct INTEGER PRIMARY KEY, opened_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $db->exec("CREATE TABLE IF NOT EXISTS water_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        acct INTEGER NOT NULL,
        termo TEXT NOT NULL,
        revisado INTEGER NOT NULL DEFAULT 0,
        nonce TEXT,
        criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec('CREATE INDEX IF NOT EXISTS water_requests_queue ON water_requests (revisado, id)');
    $db->exec('CREATE TABLE IF NOT EXISTS correnteza (id INTEGER PRIMARY KEY AUTOINCREMENT, acct INTEGER NOT NULL, dado TEXT NOT NULL, criado_em TEXT DEFAULT CURRENT_TIMESTAMP)');
    $db->exec('CREATE INDEX IF NOT EXISTS correnteza_account ON correnteza (acct, id)');
    return $db;
}

function water_collection_token(int $acct): string {
    return $acct . '.' . challenge_b64url_encode(hash_hmac('sha256', 'water-drop:' . $acct . oracle_progress_suffix($acct), challenge_account_secret(), true));
}

function water_collection_acct(?string $token): ?int {
    if (!$token || !preg_match('/^([1-9][0-9]{0,9})\.[A-Za-z0-9_-]{43}$/D', $token, $parts)) return null;
    $acct = (int)$parts[1];
    oracle_progress_guard($acct);
    if (oracle_account_banned($acct)) return null;
    return hash_equals(water_collection_token($acct), $token) ? $acct : null;
}

function water_collect(int $acct, string $data): void {
    if ($data === '') return;
    $db = water_chain_db();
    $db->prepare('INSERT INTO correnteza (acct, dado) VALUES (?, ?)')->execute([$acct, substr($data, 0, 2048)]);
    $db->prepare('DELETE FROM correnteza WHERE acct = ? AND id NOT IN (SELECT id FROM correnteza WHERE acct = ? ORDER BY id DESC LIMIT 40)')->execute([$acct, $acct]);
}

function water_entry_token(int $acct): string {
    $signature = challenge_b64url_encode(hash_hmac('sha256', 'water-entry:v2:' . $acct . oracle_progress_suffix($acct), challenge_account_secret(), true));
    return $acct . '.' . $signature;
}

function water_entry_acct(?string $token): ?int {
    if (!$token || !preg_match('/^([1-9][0-9]{0,9})\.[A-Za-z0-9_-]{43}$/D', $token, $parts)) return null;
    $acct = (int)$parts[1];
    oracle_progress_guard($acct);
    if (oracle_account_banned($acct)) return null;
    return hash_equals(water_entry_token($acct), $token) ? $acct : null;
}

// Escopos independentes: revelar uma autorização de revisão não revela o
// segredo do bot nem autoriza outra conta ou a outra etapa.
function water_bot_authorization(string $scope): string {
    $secret = getenv('WATER_BOT_TOKEN') ?: '';
    if (strlen($secret) < 16) throw new RuntimeException('WATER_BOT_TOKEN ausente ou muito curto.');
    return hash_hmac('sha256', $scope, $secret);
}

function water_receipt(array $request): string {
    $body = $request['acct'] . '.' . $request['id'];
    $signature = challenge_b64url_encode(hash_hmac('sha256',
        'water-receipt:v3:' . $body . ':' . $request['nonce'], challenge_account_secret(), true));
    return $body . '.' . $signature;
}

function water_valid_receipt(int $acct, string $receipt): bool {
    if (!preg_match('/^([1-9][0-9]{0,9})\.([1-9][0-9]{0,18})\.[A-Za-z0-9_-]{43}$/D', $receipt, $parts)
        || (int)$parts[1] !== $acct) return false;
    $statement = water_chain_db()->prepare('SELECT * FROM water_requests WHERE id = ? AND acct = ? AND revisado = 1 AND nonce IS NOT NULL');
    $statement->execute([$parts[2], $acct]);
    $request = $statement->fetch();
    return $request && hash_equals(water_receipt($request), $receipt);
}

function water_public_origin(string $host): string {
    return oracle_public_origin($host);
}

function water_depth_origin(): string {
    return water_public_origin('depth-water.' . oracle_challenge_host());
}

function water_surface_origin(): string {
    return water_public_origin('water.' . oracle_challenge_host());
}
