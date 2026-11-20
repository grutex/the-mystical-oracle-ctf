<?php
/**
 * Painel do Guardião Supremo: transforma uma conta em AUTORIZADA (admin).
 * Isolado por instância: esta super-sessão só autoriza a conta dela (<su>).
 * Ao autorizar, já entra como a conta recém-promovida (que agora é admin).
 */
require __DIR__ . '/lib.php';
$s = fire_require('superadmin');
$dominio = $s['user'];                              // dono desta super-sessão

$alvo = $dominio;
if (!fire_promote($alvo)) {
    http_response_code(404);
    fire_json(['error' => 'conta não encontrada — cadastre-a primeiro']);
}
// passa a operar como a conta autorizada (agora com papel admin)
$tok = fire_player_token($alvo);
setcookie('sessions', $tok, ['path' => '/', 'samesite' => 'Lax']);
$_COOKIE['sessions'] = $tok;
fire_json(['ok' => true, 'user' => $alvo, 'role' => 'admin']);
