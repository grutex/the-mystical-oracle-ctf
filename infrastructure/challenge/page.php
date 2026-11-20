<?php
declare(strict_types=1);

require __DIR__ . '/challenge-auth.php';

/**
 * Serve somente as telas conhecidas do desafio. Manter a lista fechada evita
 * que o parâmetro interno da reescrita seja usado para ler outros arquivos.
 */
$pages = [
    'spirit' => [
        '/' => 'oracle_spirit.html',
        '/oracle-spirit' => 'oracle_spirit.html',
        '/veil' => '0232a57db92c1c62161124a6ed855f96/veil.html',
        '/whispers' => '0232a57db92c1c62161124a6ed855f96/whispers.html',
    ],
    'air' => [
        '/' => 'oracle_air.html',
        '/oracle-air' => 'oracle_air.html',
        '/arquivo' => 'arquivo.html',
    ],
    'fire' => [
        '/' => 'oracle_fire.html',
        '/oracle-fire' => 'oracle_fire.html',
        '/forja' => 'forja.html',
    ],
    'water' => [
        '/' => 'oracle_water.html',
        '/oracle-water' => 'oracle_water.html',
        '/espelho' => 'espelho.html',
    ],
    'earth' => [
        '/' => 'oracle_earth.html',
        '/oracle-earth' => 'oracle_earth.html',
        '/catalogo' => 'catalogo.html',
    ],
    'light_dark' => [
        '/' => 'oracle_light_dark.html',
        '/oracle-light-dark' => 'oracle_light_dark.html',
        '/arquivo-sombras' => 'arquivo_sombras.html',
        '/leitor-sombras' => 'leitor_sombras.html',
        '/observatorio' => 'observatorio.html',
    ],
];

$identity = challenge_identity();
if (!$identity) {
    header('Location: ' . challenge_login_url(), true, 302);
    exit;
}

$challenge = challenge_key();
$route = '/' . trim((string)($_GET['route'] ?? '/'), '/');
$route = $route === '//' ? '/' : $route;
$file = $pages[$challenge][$route] ?? null;

if ($file === null) {
    http_response_code(404);
    exit('Caminho não encontrado.');
}

if ($challenge === 'spirit' && $route === '/veil' && array_key_exists('q', $_GET)) {
    $name = is_string($_GET['q']) ? strtolower(trim($_GET['q'])) : '';
    $expected = challenge_spirit_name((int)$identity['uid']);
    if (strlen($name) > 100 || !hash_equals($expected, $name)) {
        http_response_code(403);
    }
}

$path = __DIR__ . '/' . $file;
if (!is_file($path)) {
    http_response_code(500);
    exit('Tela do desafio indisponível.');
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
$html = file_get_contents($path);
if ($html === false) {
    http_response_code(500);
    exit('Tela do desafio indisponível.');
}
if ($challenge === 'light_dark' && $route === '/observatorio') {
    require_once dirname(__DIR__) . '/private/light-dark.php';
    $hosts = oracle_light_hosts((int)$identity['uid'], challenge_account_secret());
    $html = str_replace('{{LIGHT_BEACON_HOST}}', htmlspecialchars($hosts['beacon'], ENT_QUOTES, 'UTF-8'), $html);
}
if ($challenge === 'spirit' && $route === '/whispers') {
    $names = array_map(
        fn(string $name): string => htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        challenge_spirit_names()
    );
    $html = str_replace('{{SPIRIT_NAMES}}', implode("\n", $names), $html);
}
$progress = json_encode([(int)$identity['uid'], oracle_progress_epoch((int)$identity['uid'])], JSON_THROW_ON_ERROR);
$resetStorage = '<script>(()=>{try{const key="oracle-progress";const epoch=JSON.stringify(' . $progress . ');if(sessionStorage.getItem(key)!==epoch){sessionStorage.removeItem("arq_auth");sessionStorage.setItem(key,epoch);}}catch(_){}})();</script>';
echo str_replace('</head>', $resetStorage . '<script src="/flag-submit.js?v=1"></script></head>', $html);
