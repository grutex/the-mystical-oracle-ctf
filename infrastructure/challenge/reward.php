<?php
declare(strict_types=1);
require __DIR__ . '/challenge-auth.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') challenge_json(['error' => 'Método não permitido.'], 405);
    $identity = challenge_identity();
    if (!$identity) challenge_json(['error' => 'Abra este desafio pelo mapa enquanto estiver autenticado no portal.'], 401);
    $raw = file_get_contents('php://input', false, null, 0, 2049);
    $data = json_decode($raw, true);
    $key = challenge_key();
    if ($key === 'spirit') {
        $name = is_array($data) && is_string($data['name'] ?? null)
            ? strtolower(trim($data['name'])) : '';
        $expected = challenge_spirit_name((int)$identity['uid']);
        if (strlen($name) > 100 || !hash_equals($expected, $name)) {
            challenge_json(['error' => 'A névoa engoliu o Nome.'], 403);
        }
        challenge_json(['flag' => challenge_flag($key, (int)$identity['uid'])]);
    }

    $proof = is_array($data) && is_string($data['proof'] ?? null) ? $data['proof'] : '';

    if ($key === 'light_dark') {
        require_once dirname(__DIR__) . '/private/light-dark.php';
        $dark = is_array($data) && is_string($data['dark'] ?? null) ? trim($data['dark']) : '';
        $light = is_array($data) && is_string($data['light'] ?? null) ? trim($data['light']) : '';
        $account = (int)$identity['uid'];
        $secret = challenge_account_secret();
        if (!hash_equals(oracle_eclipse_fragment($account, 'shadow', $secret), $dark)
            || !hash_equals(oracle_eclipse_fragment($account, 'light', $secret), $light)) {
            challenge_json(['error' => 'O equilíbrio exige os dois fragmentos da sua travessia.'], 403);
        }
        challenge_json(['flag' => challenge_flag($key, $account)]);
    }

    // Água: mensagem individual + recibo privado da segunda revisão.
    if ($key === 'water') {
        require_once dirname(__DIR__) . '/private/water-chain.php';
        $receipt = is_array($data) && is_string($data['receipt'] ?? null) ? $data['receipt'] : '';
        if (!water_valid_receipt((int)$identity['uid'], $receipt)) {
            challenge_json(['error' => 'Apresente o recibo da revisão nas profundezas.'], 403);
        }
        $expected = challenge_water_message((int)$identity['uid']);
        if (!hash_equals($expected, strtolower(trim($proof)))) {
            challenge_json(['error' => 'A prova apresentada não corresponde a este desafio.'], 403);
        }
        challenge_json(['flag' => challenge_flag($key, (int)$identity['uid'])]);
    }

    $proofHashes = [
        'earth' => '292393e511399dea2f9473770ead69b358e9c099bb11554fba3af2fa05354e32',
    ];
    if (!isset($proofHashes[$key]) || !hash_equals($proofHashes[$key], hash('sha256', $proof))) {
        challenge_json(['error' => 'A prova apresentada não corresponde a este desafio.'], 403);
    }
    challenge_json(['flag' => challenge_flag($key, (int)$identity['uid'])]);
} catch (Throwable $error) {
    error_log((string)$error);
    challenge_json(['error' => 'Não foi possível emitir a recompensa.'], 500);
}
