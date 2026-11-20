<?php
/** Estado do Cofre (somente sessão admin). Isolado por jogador via APCu. */
require __DIR__ . '/lib.php';
$s = fire_require('admin');
$user = $s['user'];

$locks = [];
$now = microtime(true) * 1000;
for ($i = 1; $i <= 4; $i++) {
    $timestamp = apcu_fetch(fire_state_key($user, 'lock' . $i), $exists);
    $remaining = $exists ? max(0, FIRE_WINDOW_MS - ($now - (float)$timestamp)) : 0;
    $locks[] = [
        'n' => $i,
        'active' => $remaining > 0,
        'remaining_ms' => (int)ceil($remaining),
    ];
}
$solved = apcu_exists(fire_state_key($user, 'solved'));
$out = ['solved' => $solved, 'locks' => $locks, 'window_ms' => FIRE_WINDOW_MS];
if ($solved) $out['flag'] = fire_flag($user);
fire_json($out);
