<?php
declare(strict_types=1);

function oracle_local_access(): bool {
    $mode = getenv('ACCESS_MODE') ?: '0';
    if (!in_array($mode, ['0', '1', '2'], true)) throw new RuntimeException('ACCESS_MODE inválido.');
    if ($mode !== '2') return $mode === '0';
    $host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
    return $host === 'localhost' || str_ends_with($host, '.localhost');
}

function oracle_portal_host(): string {
    return oracle_local_access() ? 'localhost' : (getenv('PORTAL_HOST') ?: 'mysticaloraclectf.com.br');
}

function oracle_challenge_host(): string {
    return oracle_local_access() ? 'localhost' : (getenv('CHALLENGE_HOST') ?: 'mysticaloraclectf.com.br');
}

function oracle_cookie_secure(): bool {
    return !oracle_local_access() && (getenv('COOKIE_SECURE') === '1'
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'));
}

function oracle_public_origin(string $host): string {
    $local = oracle_local_access();
    $scheme = $local ? 'http' : (oracle_cookie_secure() ? 'https' : 'http');
    $port = (int)($local ? (getenv('LOCAL_PORT') ?: 8080) : (getenv('PUBLIC_PORT') ?: 443));
    $suffix = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443) ? '' : ':' . $port;
    return $scheme . '://' . $host . $suffix;
}
