<?php
/** Cadastro local da senha; o username vem exclusivamente do portal. */
require __DIR__ . '/lib.php';
$user = fire_portal_user();
$pass = (string)($_POST['password'] ?? '');
if (strlen($pass) < 4)     { http_response_code(400); fire_json(['error' => 'o selo (senha) precisa de ao menos 4 caracteres']); }

$db = fire_db();
try {
    $st = $db->prepare('INSERT INTO users (username, pass_hash) VALUES (?, ?)');
    $st->execute([$user, password_hash($pass, PASSWORD_DEFAULT)]);
} catch (PDOException $e) {
    // UNIQUE constraint -> usuário já existe
    http_response_code(409);
    fire_json(['error' => 'tua conta do portal já foi forjada; use a aba Entrar']);
}
fire_json(['ok' => true, 'user' => $user]);
