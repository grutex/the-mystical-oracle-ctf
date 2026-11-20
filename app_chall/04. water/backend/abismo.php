<?php
/** O ingresso roubado no primeiro XSS abre a outra origem, sem revelar a prova final. */
declare(strict_types=1);
require __DIR__ . '/lib.php';
$acct = water_account_id();
$token = is_string($_GET['selo'] ?? null) ? $_GET['selo'] : '';
if (water_selo_acct($token) !== $acct) {
    http_response_code(403);
    exit('O ingresso das profundezas não pertence a esta conta.');
}
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('Location: ' . water_depth_origin() . '/abismo.php?selo=' . rawurlencode($token), true, 303);
