<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
$acct = water_collection_acct(is_string($_GET['drop'] ?? null) ? $_GET['drop'] : null);
if (!$acct) challenge_json(['error' => 'Corrente desconhecida.'], 403);
$data = $_GET['d'] ?? $_POST['d'] ?? file_get_contents('php://input', false, null, 0, 2048);
if (!is_string($data)) challenge_json(['error' => 'Captura inválida.'], 400);
water_collect($acct, $data);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo 'ok';
