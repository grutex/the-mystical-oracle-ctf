<?php
declare(strict_types=1);
require __DIR__ . '/challenge-auth.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        challenge_json(['error' => 'Método não permitido.'], 405);
    }
    if (challenge_key() !== 'air' || !challenge_identity()) {
        challenge_json(['error' => 'Entre no portal e abra o desafio novamente.'], 401);
    }
    $identity = is_string($_POST['identity'] ?? null) ? $_POST['identity'] : '';
    $identity = strtolower($identity);
    $arnosAccountHash = md5('arnos');
    $arnosCertificate = substr(hash('sha256', 'VENTORUM::arnos::SIGILLUM'), 0, 32);
    if (!preg_match('/^[a-f0-9]{32}$/D', $identity)
        || !in_array($identity, [$arnosAccountHash, $arnosCertificate], true)) {
        challenge_json(['error' => 'Somente o certificador pode consultar os selos antigos.'], 403);
    }
    $certificate = is_string($_POST['certificate'] ?? null)
        ? strtolower(trim($_POST['certificate'])) : '';
    if (!preg_match('/^[a-f0-9]{32}$/D', $certificate)) {
        challenge_json(['error' => 'Apresente um certificado com 32 caracteres hexadecimais.'], 400);
    }
    foreach (['Arnos', 'Rithos', 'Pollos', 'Vultum'] as $name) {
        $expected = substr(hash('sha256', 'VENTORUM::' . strtolower($name) . '::SIGILLUM'), 0, 32);
        if (hash_equals($expected, $certificate)) challenge_json(['name' => $name]);
    }
    challenge_json(['error' => 'Este selo não consta no registro dos nomes antigos.'], 404);
} catch (Throwable $error) {
    error_log((string)$error);
    challenge_json(['error' => 'Não foi possível traduzir o certificado.'], 500);
}
