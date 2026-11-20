<?php
declare(strict_types=1);
require dirname(__DIR__) . '/private/light-dark.php';

function light_reply(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

try {
    $secret = getenv('ACCOUNT_SECRET') ?: '';
    if (strlen($secret) < 32) throw new RuntimeException('Segredo da torre ausente.');
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') light_reply(['error' => 'Método não permitido.'], 405);
    $account = $_SERVER['HTTP_X_ORACLE_ACCOUNT'] ?? '';
    $expires = $_SERVER['HTTP_X_ORACLE_EXPIRES'] ?? '';
    $signature = $_SERVER['HTTP_X_ORACLE_SIGNATURE'] ?? '';
    if (!preg_match('/^[1-9]\d{0,9}$/D', $account) || !preg_match('/^\d{10}$/D', $expires)
        || (int)$expires < time() || (int)$expires > time() + 30
        || !hash_equals(oracle_light_signature((int)$account, (int)$expires, $secret), $signature)) {
        light_reply(['error' => 'A torre reconhece apenas seus próprios instrumentos.'], 403);
    }
    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $host = preg_replace('/:80$/', '', $host);
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $hosts = oracle_light_hosts((int)$account, $secret);
    $manifestName = substr($hosts['manifest'], 0, -4);
    $sealName = substr($hosts['seal'], 0, -4);
    $fragmentHost = oracle_light_fragment_host((int)$account, $secret);
    $fragmentName = substr($fragmentHost, 0, -4);
    $split = (int)ceil(strlen($fragmentName) / 2);
    if ($host === 'celeste.luz' && $path === '/') {
        light_reply([
            'stage' => 'celeste', 'name' => 'Torre Celeste',
            'message' => 'Bem-vindo a Celeste! Aqui até os postes têm iluminação espiritual. Só não peça para trocar a lâmpada: o último mago levou três eras e uma escada.',
        ]);
    }
    if ($host === $fragmentHost && $path === '/') {
        light_reply([
            'stage' => 'dominio', 'name' => $fragmentName,
            'message' => 'Você encontrou a torre fora do traçado. Seu ponto de luz guarda a metade que falta ao equilíbrio. Procure a entrada indicada pelo selo, diretamente neste domínio.',
            'seal' => 'fragmento',
        ]);
    }
    $beacons = [
        '/aurora' => ['name' => 'Aurora', 'intensity' => 72, 'message' => 'A primeira vigília acendeu os cristais. As fontes catalogadas respondem ao prisma da torre.'],
        '/zenite' => ['name' => 'Zênite', 'intensity' => 94,
            'message' => 'Nota de manutenção: o manifesto dos faróis perdidos está na torre Celeste, no domínio da luz. O nome do farol identifica a sala; o selo identifica a entrada dessa sala.',
            'farol' => $manifestName, 'seal' => 'manifesto'],
        '/crepusculo' => ['name' => 'Crepúsculo', 'intensity' => 38, 'message' => 'O vigia tentou visitar a câmara pela janela. Nada viu. Somente o prisma envia sua voz pelos corredores internos da torre.'],
    ];
    if ($host === $hosts['beacon'] && isset($beacons[$path])) {
        light_reply(['stage' => 'farol', 'beacon' => $path, ...$beacons[$path]]);
    }
    if ($host === 'celeste.luz' && in_array($path, ['/' . $manifestName, '/' . $manifestName . '/'], true)) {
        light_reply([
            'stage' => 'vestibulo', 'farol' => $manifestName,
            'message' => 'O zelador passou a noite discursando para uma lâmpada. Ela não respondeu, mas exigiu tudo por escrito. Desde então, ele chama isso de reunião produtiva.',
        ]);
    }
    if ($host === 'celeste.luz' && in_array($path, ['/' . $sealName, '/' . $sealName . '/'], true)) {
        light_reply([
            'stage' => 'vestibulo', 'farol' => $sealName,
            'message' => 'O vigia coleciona cera e jura que é para fazer velas. Curiosamente, todas as cartas dele chegam fechadas demais. A lâmpada prefere não comentar.',
        ]);
    }
    if ($host === 'celeste.luz' && $path === '/' . $manifestName . '/manifesto') {
        light_reply([
            'stage' => 'manifesto', 'name' => 'Câmara da Primeira Luz',
            'message' => 'O catálogo apagou nosso nome. Outro farol perdido guarda os selos em sua própria sala, nesta mesma torre.',
            'farol' => $sealName, 'seal' => 'selos',
            'pista' => 'Uma luz permanece fora do traçado. A primeira metade de seu nome é "' . substr($fragmentName, 0, $split) . '". A outra metade foi guardada entre os selos.',
        ]);
    }
    if ($host === 'celeste.luz' && $path === '/' . $sealName . '/selos') {
        light_reply([
            'stage' => 'selo', 'name' => 'Registro dos Selos',
            'message' => 'O fragmento não repousa em Celeste. Seu guardião é um farol solitário, fora desta torre.',
            'pista' => 'A segunda metade de seu nome é "' . substr($fragmentName, $split) . '". Una as metades na ordem do manifesto e dos selos. Esse nome pertence ao domínio da luz; consulte sua raiz para recuperar a indicação apagada.',
            'seal' => 'REDACTED',
        ]);
    }
    if ($host === $fragmentHost && $path === '/fragmento') {
        light_reply([
            'stage' => 'fragmento', 'name' => 'Farol Solitário', 'farol' => $fragmentName,
            'message' => 'A luz reconhece quem a encontrou. Leve este fragmento ao Limiar e reúna as duas metades.',
            'fragmento_luz' => oracle_eclipse_fragment((int)$account, 'light', $secret),
        ]);
    }
    light_reply(['error' => 'Nenhuma fonte responde nesse ponto da torre.'], 404);
} catch (Throwable $error) {
    error_log((string)$error);
    light_reply(['error' => 'A câmara perdeu sua luz.'], 500);
}
