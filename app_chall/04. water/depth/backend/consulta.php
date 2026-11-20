<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$term = $_GET['q'] ?? null;
if (!$id || !is_string($term) || !hash_equals(water_bot_authorization('review:depth:' . $id), (string)($_GET['token'] ?? ''))) {
    http_response_code(403);
    exit('A Arquivista não reconhece esta solicitação.');
}
$db = water_chain_db();
$statement = $db->prepare('SELECT * FROM water_requests WHERE id = ?');
$statement->execute([$id]);
$request = $statement->fetch();
if ($request) {
    oracle_progress_guard((int)$request['acct']);
    $statement->execute([$id]);
    $request = $statement->fetch();
}
if (!$request || !hash_equals($request['termo'], $term)) {
    http_response_code(404);
    exit('A consulta se perdeu entre as correntes.');
}
$request['nonce'] ??= bin2hex(random_bytes(16));
$db->prepare('UPDATE water_requests SET revisado = 1, nonce = ? WHERE id = ?')->execute([$request['nonce'], $id]);
$private = ['mensagem' => challenge_water_message((int)$request['acct']), 'recibo' => water_receipt($request)];
$flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;
$coletor = '/correnteza.php?drop=' . water_collection_token((int)$request['acct']);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><title>Consulta da Arquivista</title><link rel="stylesheet" href="/abismo.css">
<script src="/oracle-runtime.js"></script><script>
window.__ARQUIVO__ = <?= json_encode($private, $flags) ?>;
window.__CORRENTEZA__ = <?= json_encode($coletor, $flags) ?>;
</script><script src="/abismo.js?v=revelar-ingresso-2"></script></head>
<body><main class="poema"><h1>Consulta Reservada</h1><p class="sub">A Arquivista abriu o termo solicitado para localizar o trecho.</p>
<section class="arquivo"><h2>Resultado para</h2>
<div class="resultado"><?= $term ?></div>
<button type="button">Arquivar consulta</button></section></main></body></html>
