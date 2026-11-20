<?php
declare(strict_types=1);
require __DIR__ . '/challenge-auth.php';
require_once dirname(__DIR__) . '/private/light-dark.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') challenge_json(['error' => 'Método não permitido.'], 405);
    $identity = challenge_identity();
    if (!$identity || challenge_key() !== 'light_dark') challenge_json(['error' => 'Entre no portal e abra o desafio novamente.'], 401);
    $raw = file_get_contents('php://input', false, null, 0, 2049);
    $data = json_decode($raw ?: '', true);
    $url = is_array($data) && is_string($data['url'] ?? null) ? $data['url'] : '';
    $parts = parse_url($url);
    // Contenção do laboratório: 50 nomes da torre e 10 faróis isolados.
    // A falha intencional é permitir a câmara interna, além dos faróis catalogados.
    if (strlen($url) > 512 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
        || !is_array($parts) || ($parts['scheme'] ?? '') !== 'http'
        || !in_array($parts['host'] ?? '', [...oracle_light_host_pool(), ...oracle_light_fragment_pool()], true)
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || (isset($parts['port']) && $parts['port'] !== 80)) {
        challenge_json(['error' => 'O prisma não reconhece essa fonte de luz.'], 400);
    }
    $account = (int)$identity['uid'];
    $expires = time() + 30;
    $signature = oracle_light_signature($account, $expires, challenge_account_secret());
    $context = stream_context_create(['http' => [
        'method' => 'GET', 'timeout' => 3, 'follow_location' => 0,
        'ignore_errors' => true,
        'header' => "Host: " . $parts['host'] . "\r\nAccept: application/json\r\nX-Oracle-Account: $account\r\nX-Oracle-Expires: $expires\r\nX-Oracle-Signature: $signature\r\n",
    ]]);
    // Os nomes fictícios são hosts virtuais da torre isolada; não dependem do
    // DNS público. Host e caminho escolhidos pelo cliente chegam por HTTP real.
    $destination = 'http://light-tower' . ($parts['path'] ?? '/');
    $response = @file_get_contents($destination, false, $context, 0, 8193);
    if ($response === false || strlen($response) > 8192) challenge_json(['error' => 'A transmissão não alcançou a torre.'], 502);
    $transmission = json_decode($response, true);
    if (!is_array($transmission)) challenge_json(['error' => 'A torre enviou uma transmissão ilegível.'], 502);
    preg_match('/^HTTP\/\S+ (\d{3})/', $http_response_header[0] ?? '', $status);
    $code = (int)($status[1] ?? 502);
    if ($code === 200) {
        $star = ($transmission['stage'] ?? '') === 'farol'
            ? ltrim((string)($transmission['beacon'] ?? ''), '/') : (string)($transmission['stage'] ?? '');
        if ($star === 'vestibulo') {
            $hosts = oracle_light_hosts($account, challenge_account_secret());
            $name = (string)($transmission['farol'] ?? '');
            $star = $name === substr($hosts['manifest'], 0, -4) ? 'visita_manifesto'
                : ($name === substr($hosts['seal'], 0, -4) ? 'visita_selo' : '');
        }
        oracle_light_observe($account, $star);
    }
    challenge_json(['source' => $url, 'transmission' => $transmission, 'constellation' => oracle_light_constellation($account)], $code);
} catch (Throwable $error) {
    error_log((string)$error);
    challenge_json(['error' => 'O prisma perdeu sua sintonia.'], 500);
}
