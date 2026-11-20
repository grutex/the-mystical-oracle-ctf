<?php
/** Troca o selo local sem exigir o anterior; a identidade vem do portal. */
require __DIR__ . '/lib.php';
$user = fire_portal_user();
$pass = (string)($_POST['password'] ?? '');
if (strlen($pass) < 4) {
    http_response_code(400);
    fire_json(['error' => 'o novo selo precisa de ao menos 4 caracteres']);
}

$st = fire_db()->prepare('UPDATE users SET pass_hash = ? WHERE username = ?');
$st->execute([password_hash($pass, PASSWORD_DEFAULT), $user]);
if ($st->rowCount() < 1) {
    http_response_code(404);
    fire_json(['error' => 'esta conta ainda não foi forjada; use a aba Cadastrar']);
}

// A reforja preserva o papel/progresso e já reacende a sessão do jogador.
$token = fire_player_token($user);
setcookie('sessions', $token, ['path' => '/', 'samesite' => 'Lax']);
$_COOKIE['sessions'] = $token;
fire_json(['ok' => true, 'user' => $user, 'role' => fire_role($user)]);
