<?php
declare(strict_types=1);
require __DIR__ . '/challenge-auth.php';
require_once dirname(__DIR__) . '/private/light-dark.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') challenge_json(['error' => 'Método não permitido.'], 405);
    $identity = challenge_identity();
    if (!$identity || challenge_key() !== 'light_dark') challenge_json(['error' => 'Entre no portal e abra o desafio novamente.'], 401);
    challenge_json(oracle_light_constellation((int)$identity['uid']));
} catch (Throwable $error) {
    error_log((string)$error);
    challenge_json(['error' => 'As estrelas perderam sua sintonia.'], 500);
}
