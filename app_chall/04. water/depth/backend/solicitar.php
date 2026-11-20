<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') challenge_json(['error' => 'Método não permitido.'], 405);
$acct = depth_account_id();
if (!hash_equals(depth_csrf($acct), (string)($_POST['csrf'] ?? ''))) {
    challenge_json(['error' => 'Abra o arquivo antes de solicitar uma consulta.'], 403);
}
$term = $_POST['termo'] ?? null;
if (!is_string($term) || trim($term) === '' || strlen($term) > 2048) {
    challenge_json(['error' => 'Informe um termo de até 2048 bytes.'], 400);
}
$db = water_chain_db();
$entry = $db->prepare('SELECT 1 FROM water_entries WHERE acct = ?');
$entry->execute([$acct]);
if (!$entry->fetchColumn()) challenge_json(['error' => 'O ingresso ainda não foi apresentado ao arquivo.'], 403);
$pending = $db->prepare('SELECT COUNT(*) FROM water_requests WHERE acct = ? AND revisado = 0');
$pending->execute([$acct]);
if ((int)$pending->fetchColumn() >= 5) challenge_json(['error' => 'Aguarde a Arquivista concluir as consultas pendentes.'], 429);
$statement = $db->prepare('INSERT INTO water_requests (acct, termo) VALUES (?, ?)');
$statement->execute([$acct, $term]);
$id = (int)$db->lastInsertId();
challenge_json([
    'ok' => true,
    'id' => $id,
    // A Arquivista acrescenta sua autorização interna antes de abrir este link.
    'consulta' => '/consulta.php?id=' . $id . '&q=' . rawurlencode($term),
], 201);
