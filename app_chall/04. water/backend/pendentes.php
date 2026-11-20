<?php
/**
 * MYSTICAL ORACLE CTF — FILA DA GUARDIÃ (Desafio 04 · water)
 * Endpoint INTERNO: lista as contas com reflexos ainda não revisados, para o
 * bot saber quais murais visitar. Protegido pelo token interno (WATER_BOT_TOKEN)
 * — o jogador não deve alcançar esta fila.
 */

require __DIR__ . '/lib.php';

if (!hash_equals(water_bot_authorization('queue:surface'), (string)($_SERVER['HTTP_X_ORACLE_BOT'] ?? ''))) {
    challenge_json(['error' => 'A Guardiã não reconhece este chamado.'], 403);
}

$st = water_db()->query('SELECT acct FROM reflexos WHERE revisado = 0 GROUP BY acct ORDER BY MIN(id) LIMIT 20');
$contas = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

challenge_json(['contas' => $contas]);
