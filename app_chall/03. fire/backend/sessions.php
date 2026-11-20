<?php
/**
 * Diretório de sessões — ISOLADO por jogador.
 * 198 fakes + a sua + a do GUARDIÃO SUPREMO = 200 entradas por jogador.
 * As 100 entradas adicionais ficam depois da sessão do Guardião.
 * O tamanho maior é a PISTA. Nunca expõe sessões reais de outros usuários.
 */
require __DIR__ . '/lib.php';
$s = fire_current();
if (!$s) { http_response_code(401); fire_json(['error' => 'entre com uma conta para listar as sessoes']); }
$user = $s['user'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    fire_require('player');
    if (($_POST['action'] ?? '') !== 'renew') {
        http_response_code(400);
        fire_json(['error' => 'pedido de sessão inválido']);
    }
    apcu_delete(fire_state_key($user, 'workshop-used'));
    fire_json(['ok' => true, 'sessions' => $_COOKIE['sessions'], 'message' => '(apagar depois)DEV MESSAGE: /sessions atualizado. A bancada está pronta para uma nova requisição.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    fire_json(['error' => 'método não permitido']);
}

$fakeEntry = function (int $i) use ($user): array {
    // Nomes de 4–32 caracteres geram tokens reais variados de 60–100 bytes.
    // O índice em base 36 garante unicidade mesmo nos nomes mais curtos.
    $fakeLength = 4 + (($i * 11) % 29);
    $prefix = 's' . base_convert((string)$i, 10, 36);
    $digest = hash_hmac('sha256', $user . ':fake:' . $i, fire_account_secret());
    $fakeUser = substr($prefix . $digest, 0, $fakeLength);
    $tok = fire_player_token($fakeUser);
    return ['name' => $tok, 'size' => strlen($tok)];
};

$files = [];
for ($i = 1; $i <= 98; $i++) {
    $files[] = $fakeEntry($i);
}
$own = fire_player_token($user);
$files[] = ['name' => $own, 'size' => strlen($own)];
usort($files, fn($a, $b) => strcmp($a['name'], $b['name']));
$sup = fire_super_token($user);                 // a sessão do Guardião Supremo (MAIOR)
$files[] = ['name' => $sup, 'size' => strlen($sup)];

for ($i = 99; $i <= 198; $i++) {
    $files[] = $fakeEntry($i);
}
fire_json(['dir' => '/sessions/' . $user, 'files' => $files]);
