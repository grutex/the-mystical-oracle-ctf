<?php
/**
 * MYSTICAL ORACLE CTF — RELICÁRIO DA TERRA (Desafio 05 · earth)
 * Endpoint de busca DELIBERADAMENTE vulnerável a SQL Injection.
 *
 * A entrada do usuário é concatenada direto na query (sem prepared
 * statement / sem escaping) — é ISTO que torna o UNION-based injection
 * possível. Em produção real: use mysqli prepared statements.
 *
 * Resposta JSON:
 *   sucesso -> { "query": "...", "columns": [...], "rows": [[...], ...] }
 *   erro    -> { "query": "...", "error": "<erro real do MariaDB>" }   (error-based)
 */

require_once __DIR__ . '/challenge-auth.php';
if (!challenge_identity()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => 'Entre no portal e abra o desafio novamente.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// PHP 8.1+ lança exceção no mysqli por padrão. Desligamos para que erros de
// SQL voltem como JSON (error-based) em vez de derrubar a página.
mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('DB_HOST') ?: 'earth-db';
$name = getenv('DB_NAME') ?: 'terra_relicario';
$user = getenv('DB_USER') ?: 'relic_ro';
$pass = getenv('DB_PASS') ?: 'relicpw';

$q = isset($_GET['q']) ? $_GET['q'] : '';

$db = @new mysqli($host, $user, $pass, $name);
if ($db->connect_errno) {
    http_response_code(500);
    echo json_encode(['error' => 'Falha ao conectar ao Relicário: ' . $db->connect_error]);
    exit;
}
$db->set_charset('utf8mb4');

/* ─────────────────────────────────────────────────────────────
   VULNERÁVEL DE PROPÓSITO: entrada concatenada na cláusula LIKE.
   ───────────────────────────────────────────────────────────── */
$sql = "SELECT nome, descricao FROM relicarios WHERE nome LIKE '%$q%'";

$res = $db->query($sql);

if ($res === false) {
    // Erro real do MariaDB -> habilita error-based e mostra o ponto de injeção.
    echo json_encode(['query' => $sql, 'error' => $db->error]);
    $db->close();
    exit;
}

$columns = [];
foreach ($res->fetch_fields() as $f) $columns[] = $f->name;

$rows = [];
while ($row = $res->fetch_row()) $rows[] = $row;

echo json_encode(['query' => $sql, 'columns' => $columns, 'rows' => $rows]);
$res->free();
$db->close();
