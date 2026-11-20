<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
if (!hash_equals(water_bot_authorization('queue:depth'), (string)($_SERVER['HTTP_X_ORACLE_BOT'] ?? ''))) {
    challenge_json(['error' => 'Fila reservada à Arquivista.'], 403);
}
$rows = water_chain_db()->query('SELECT id, acct, termo FROM water_requests WHERE revisado = 0 ORDER BY id LIMIT 20')->fetchAll();
challenge_json(['solicitacoes' => $rows]);
