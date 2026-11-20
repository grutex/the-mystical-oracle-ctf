<?php
/**
 * Aciona UMA trava (somente sessão admin), isolada por jogador.
 *   chave APCu: fire:<user>:lock<n>
 * Latência artificial (FIRE_SLEEP_MS) faz acionamentos SEQUENCIAIS (esperando
 * cada resposta) caírem fora da janela. Só requisições PARALELAS engatam as
 * quatro dentro de FIRE_WINDOW_MS e abrem o cofre.
 * NÃO usa session_start -> requisições concorrentes rodam de verdade em paralelo.
 */
require __DIR__ . '/lib.php';
$s = fire_require('admin');
$user = $s['user'];

$n = (int)($_POST['n'] ?? $_GET['n'] ?? 0);
if ($n < 1 || $n > 4) { http_response_code(400); fire_json(['error' => 'trava invalida']); }

usleep(FIRE_SLEEP_MS * 1000);                 // processamento da trava
$now = microtime(true) * 1000;                // ms
apcu_store(fire_state_key($user, 'lock' . $n), $now, FIRE_LOCK_TTL);

// lê as quatro travas engatadas agora
$ts = [];
for ($i = 1; $i <= 4; $i++) {
    $v = apcu_fetch(fire_state_key($user, 'lock' . $i), $ok);
    if ($ok) $ts[$i] = $v;
}

$opened = false; $flag = null;
if (count($ts) === 4 && (max($ts) - min($ts)) <= FIRE_WINDOW_MS) {
    apcu_store(fire_state_key($user, 'solved'), 1, 3600);
    $opened = true;
    $flag = fire_flag($user);
}
if (apcu_exists(fire_state_key($user, 'solved'))) { $opened = true; $flag = fire_flag($user); }

fire_json([
    'lock' => $n,
    'opened' => $opened,
    'flag' => $flag,
    'active' => count(array_filter($ts, fn($timestamp) => ($now - (float)$timestamp) <= FIRE_WINDOW_MS)),
    'window_ms' => FIRE_WINDOW_MS,
]);
