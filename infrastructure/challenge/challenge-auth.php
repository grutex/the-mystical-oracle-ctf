<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/private/access-mode.php';
require_once dirname(__DIR__) . '/private/challenge-progress.php';

function challenge_account_secret(): string {
    $secret = getenv('ACCOUNT_SECRET') ?: '';
    if (strlen($secret) < 32) throw new RuntimeException('ACCOUNT_SECRET ausente ou muito curto.');
    return $secret;
}

function challenge_b64url_encode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function challenge_b64url_decode(string $value): string|false {
    $padding = (4 - strlen($value) % 4) % 4;
    return base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
}

function challenge_sign(array $payload): string {
    $body = challenge_b64url_encode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $signature = challenge_b64url_encode(hash_hmac('sha256', 'ticket:' . $body, challenge_account_secret(), true));
    return $body . '.' . $signature;
}

function challenge_verify(?string $token, ?string $expectedChallenge = null): ?array {
    if (!$token || substr_count($token, '.') !== 1) return null;
    [$body, $signature] = explode('.', $token, 2);
    $expected = challenge_b64url_encode(hash_hmac('sha256', 'ticket:' . $body, challenge_account_secret(), true));
    if (!hash_equals($expected, $signature)) return null;
    $json = challenge_b64url_decode($body);
    if ($json === false) return null;
    $payload = json_decode($json, true);
    if (!is_array($payload) || !isset($payload['uid'], $payload['username'], $payload['challenge'], $payload['exp'])) return null;
    if ((int)$payload['exp'] < time()) return null;
    if ($expectedChallenge !== null && !hash_equals($expectedChallenge, (string)$payload['challenge'])) return null;
    return $payload;
}

function challenge_key(): string {
    return (string)(getenv('CHALLENGE_KEY') ?: '');
}

function challenge_identity(): ?array {
    static $resolved = false;
    static $cached = null;
    if ($resolved) return $cached;
    $resolved = true;
    $identity = challenge_verify($_COOKIE['oracle_challenge'] ?? null, challenge_key());
    return $cached = $identity && challenge_portal_session_active($identity) ? $identity : null;
}

function challenge_portal_session_active(array $identity): bool {
    if (oracle_account_banned((int)($identity['uid'] ?? 0))) return false;
    $sid = $identity['sid'] ?? '';
    if (!is_string($sid) || !preg_match('/^[A-Za-z0-9,-]{16,128}$/D', $sid)) return false;

    $directory = rtrim((string)(getenv('PORTAL_SESSION_PATH') ?: '/var/www/portal-sessions'), '/\\');
    $handle = @fopen($directory . DIRECTORY_SEPARATOR . 'sess_' . $sid, 'rb');
    if ($handle === false) return false;

    $contents = '';
    if (flock($handle, LOCK_SH)) {
        $contents = stream_get_contents($handle) ?: '';
        flock($handle, LOCK_UN);
    }
    fclose($handle);

    // Never wait for the portal's PHP session lock while holding the progress
    // lock: a reset owns that session and waits for active challenge requests.
    oracle_progress_guard((int)($identity['uid'] ?? 0));
    if (!hash_equals(oracle_progress_epoch((int)($identity['uid'] ?? 0)), (string)($identity['progress_epoch'] ?? '0'))) return false;

    if (!preg_match('/(?:^|;)uid\|i:(\d+);/', $contents, $uid)
        || !preg_match('/(?:^|;)last_activity\|i:(\d+);/', $contents, $activity)) return false;
    $ttl = max(300, (int)(getenv('SESSION_TTL') ?: 21600));
    $state = json_decode((string)@file_get_contents($directory . '/contest-state.json'), true);
    if (is_array($state) && !in_array((int)($identity['uid'] ?? 0), $state['exempt_accounts'] ?? [], true)) {
        preg_match('/(?:^|;)contest_epoch\|i:(\d+);/', $contents, $epoch);
        if ($state['paused_at'] || (int)($epoch[1] ?? 0) !== (int)$state['session_epoch']) return false;
    }
    return (int)$uid[1] === (int)($identity['uid'] ?? 0) && time() - (int)$activity[1] <= $ttl;
}
function challenge_account_paused(int $account): bool {
    if (oracle_account_banned($account)) return true;
    $directory = rtrim((string)(getenv('PORTAL_SESSION_PATH') ?: '/var/www/portal-sessions'), '/\\');
    $state = json_decode((string)@file_get_contents($directory . '/contest-state.json'), true);
    return is_array($state) && !empty($state['paused_at']) && !in_array($account, $state['exempt_accounts'] ?? [], true);
}

function challenge_login_url(): string {
    return oracle_public_origin(oracle_portal_host()) . '/login';
}

function challenge_spirit_names(): array {
    return [
        'espectro', 'assombracao', 'alma', 'fantasma', 'aparicao',
        'wraith', 'banshee', 'specter', 'phantom', 'poltergeist',
        'revenant', 'wisp', 'shade', 'apparition', 'ectoplasma',
        'ghoul', 'espirito', 'eidolon', 'larva', 'manes',
        'lemures', 'umbra', 'vulto', 'assombro', 'efialtes',
        'sombra', 'espectral', 'nevoa', 'alquimista', 'ceifador',
        'mortalha', 'penumbra', 'errante', 'lamento', 'sussurro',
        'vigilia', 'oraculo', 'sentinela', 'caminhante', 'habitante',
        'ancestral', 'perdido', 'silhueta', 'bruma', 'eco',
        'abismo', 'noturno', 'lucifugo', 'psicopompo', 'guardiao',
    ];
}

function challenge_spirit_name(int $accountId): string {
    $names = challenge_spirit_names();
    $digest = hash_hmac('sha256', 'spirit-name:' . $accountId, challenge_account_secret(), true);
    $number = unpack('Nvalue', substr($digest, 0, 4));
    return $names[$number['value'] % count($names)];
}

function challenge_water_message(int $accountId): string {
    static $messages;
    $messages ??= require dirname(__DIR__) . '/private/water-messages.php';
    $digest = hash_hmac('sha256', 'water-message:' . $accountId, challenge_account_secret(), true);
    $number = unpack('Nvalue', substr($digest, 0, 4));
    return $messages[$number['value'] % count($messages)];
}

function challenge_flag(string $key, int $accountId): string {
    $prefixes = ['spirit' => 'sp1rit', 'air' => 'a1r', 'water' => 'w4t3r', 'earth' => '34rth', 'light_dark' => 'l1ght_d4rk'];
    if (!isset($prefixes[$key])) throw new RuntimeException('Desafio sem flag individual configurada.');
    $digest = substr(hash_hmac('sha256', 'flag:' . $accountId . ':' . $key, challenge_account_secret()), 0, 20);
    return 'ORACLE{' . $prefixes[$key] . '_' . $digest . '}';
}

function challenge_json(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
