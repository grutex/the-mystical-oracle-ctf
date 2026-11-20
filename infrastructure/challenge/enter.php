<?php
declare(strict_types=1);
require __DIR__ . '/challenge-auth.php';

try {
    $identity = challenge_verify($_GET['ticket'] ?? null, challenge_key());
    if (!$identity || !challenge_portal_session_active($identity)) {
        http_response_code(401);
        exit('Ingresso inválido ou expirado. Volte ao portal e abra o desafio novamente.');
    }
    $ttl = max(300, (int)(getenv('CHALLENGE_TTL') ?: 21600));
    $identity['exp'] = time() + $ttl;
    $secure = oracle_cookie_secure();
    setcookie('oracle_challenge', challenge_sign($identity), [
        'expires' => $identity['exp'], 'path' => '/', 'secure' => $secure,
        'httponly' => true, 'samesite' => 'Lax'
    ]);
    header('Location: /' . ltrim((string)(getenv('APP_INDEX') ?: ''), '/'), true, 302);
} catch (Throwable $error) {
    error_log((string)$error);
    http_response_code(500);
    echo 'Não foi possível iniciar o desafio.';
}
