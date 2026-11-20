<?php
/** Login real: o username vem do portal; verifica apenas a senha local. */
require __DIR__ . '/lib.php';
$user = fire_portal_user();
$pass = (string)($_POST['password'] ?? '');
if ($pass === '') { http_response_code(400); fire_json(['error' => 'informe o selo']); }

$st = fire_db()->prepare('SELECT pass_hash FROM users WHERE username = ?');
$st->execute([$user]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row || !password_verify($pass, $row['pass_hash'])) {
    http_response_code(401);
    fire_json(['error' => 'selo inválido (a conta do portal já foi forjada aqui?)']);
}
$token = fire_player_token($user);
setcookie('sessions', $token, ['path' => '/', 'samesite' => 'Lax']); // NÃO httpOnly: descobrível
$_COOKIE['sessions'] = $token;
fire_json(['ok' => true, 'user' => $user, 'role' => fire_role($user), 'token' => $token]);
