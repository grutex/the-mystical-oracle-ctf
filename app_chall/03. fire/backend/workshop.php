<?php
require __DIR__ . '/lib.php';
$s = fire_require('player');
$key = fire_state_key($s['user'], 'workshop-used');
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') fire_json(['ok' => true, 'locked' => apcu_exists($key)]);
if ($method !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405);
    fire_json(['error' => 'método não permitido']);
}

$action = $_POST['action'] ?? '';
if (!is_string($action) || !in_array($action, ['supplies', 'print', 'alloy', 'seal'], true)) {
    http_response_code(400);
    fire_json(['error' => 'operação de bancada inválida']);
}
$quantity = 0;
if (in_array($action, ['supplies', 'alloy'], true)) {
    $raw = $_POST['quantity'] ?? null;
    $quantity = is_scalar($raw) ? filter_var($raw, FILTER_VALIDATE_FLOAT) : false;
    if ($quantity === false || !is_finite($quantity) || $quantity < .1 || $quantity > 100) {
        http_response_code(400);
        fire_json(['error' => 'Informe uma quantidade entre 0,1 e 100 kg.']);
    }
}

$reference = strtoupper(bin2hex(random_bytes(4)));
$issued = gmdate('Y-m-d H:i:s') . ' UTC';
$formatKg = fn(float $kg): string => number_format($kg, 2, ',', '.') . ' kg';
switch ($action) {
    case 'supplies':
        $title = 'Requisição de carvão';
        $lines = ['Material: carvão mineral', 'Quantidade solicitada: ' . $formatKg($quantity), 'Destino: bancada do aprendiz', 'Situação: comprovante emitido'];
        break;
    case 'print':
        $title = 'Comprovante de turno';
        $lines = ['Posto: bancada do aprendiz', 'Selo de entrada: válido', 'Acesso à Câmara: requer autorização'];
        break;
    case 'alloy':
        $title = 'Plano de liga — bronze';
        $lines = ['Massa total: ' . $formatKg($quantity), 'Cobre (90%): ' . $formatKg($quantity * .9), 'Estanho (10%): ' . $formatKg($quantity * .1)];
        break;
    case 'seal':
        $title = 'Conferência do selo';
        $lines = ['Integridade: assinatura válida', 'Vínculo da conta: confirmado', 'Autoridade: aprendiz comum'];
        break;
}

if (!apcu_add($key, true, max(60, (int)(getenv('SESSION_TTL') ?: 21600)))) {
    $locked = apcu_exists($key);
    http_response_code($locked ? 409 : 503);
    fire_json(['locked' => $locked, 'error' => $locked ? 'Não é permitido fazer mais requisições com essa sessão.' : 'A bancada está indisponível. Tente novamente.']);
}
$content = implode("\n", array_merge(['A FORJA DO ORÁCULO', $title, 'Registro: ' . $reference, 'Aprendiz: ' . $s['user'], 'Emitido em: ' . $issued, ''], $lines));
fire_json(['ok' => true, 'locked' => true, 'title' => $title, 'content' => $content, 'filename' => 'forja-' . $reference . '.txt']);
