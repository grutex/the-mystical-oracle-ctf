<?php
declare(strict_types=1);
if (getenv('LIGHT_TEST_DISPOSABLE') !== '1') throw new RuntimeException('Use a instalação descartável.');
require '/var/www/private/light-dark.php';
$secret = getenv('ACCOUNT_SECRET');
$uid = 42;
$pool = oracle_light_host_pool();
if (count($pool) !== 50 || count(array_unique($pool)) !== 50) throw new RuntimeException('Pool deve conter 50 nomes distintos.');
$variants = [];
$roomPool = oracle_light_room_pool();
if (count($roomPool) !== 50 || count(array_unique($roomPool)) !== 50 || array_intersect($roomPool, ['aurora.luz', 'zenite.luz', 'crepusculo.luz'])) throw new RuntimeException('Salas disputam entradas dos faróis públicos.');
$fragmentPool = oracle_light_fragment_pool();
if (count($fragmentPool) !== 10 || count(array_unique($fragmentPool)) !== 10 || in_array('celeste.luz', $fragmentPool, true)) throw new RuntimeException('Pool do farol solitário inválido.');
$fragmentVariants = [];
foreach (range(1, 200) as $account) {
    $hosts = oracle_light_hosts($account, $secret);
    if ($hosts['beacon'] !== 'celeste.luz' || $hosts['manifest'] === $hosts['seal'] || array_diff([$hosts['manifest'], $hosts['seal']], $roomPool)
        || $hosts !== oracle_light_hosts($account, $secret)) throw new RuntimeException('Sorteio de nomes inválido.');
    $variants[implode('|', $hosts)] = true;
    $fragmentHost = oracle_light_fragment_host($account, $secret);
    if (!in_array($fragmentHost, $fragmentPool, true) || $fragmentHost !== oracle_light_fragment_host($account, $secret)) throw new RuntimeException('Farol solitário inválido.');
    $fragmentVariants[$fragmentHost] = true;
}
if (count($variants) < 2) throw new RuntimeException('Nomes não variam entre contas.');
if (count($fragmentVariants) !== 10) throw new RuntimeException('Sorteio não utiliza as 10 possibilidades.');
$hosts = oracle_light_hosts($uid, $secret);
$cases = [
    ['unsigned', [], 403],
    ['invalid', ['X-Oracle-Account: 42', 'X-Oracle-Expires: ' . (time() + 30), 'X-Oracle-Signature: invalid'], 403],
];
foreach ([-60, 60, 25] as $offset) {
    $expires = time() + $offset;
    $cases[] = ['expiry ' . $offset, ['X-Oracle-Account: ' . $uid, 'X-Oracle-Expires: ' . $expires,
        'X-Oracle-Signature: ' . oracle_light_signature($uid, $expires, $secret)], $offset === 25 ? 200 : 403];
}
foreach ($cases as [$name, $headers, $expected]) {
    $headers[] = 'Host: ' . oracle_light_fragment_host($uid, $secret);
    $context = stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 3]]);
    $raw = file_get_contents('http://light-tower/fragmento', false, $context);
    preg_match('/^HTTP\/\S+ (\d{3})/', $http_response_header[0] ?? '', $match);
    if ((int)($match[1] ?? 0) !== $expected) throw new RuntimeException('Falha na assinatura interna: ' . $name);
    $data = json_decode($raw, true);
    if ($expected === 200 && ($data['fragmento_luz'] ?? '') !== oracle_eclipse_fragment($uid, 'light', $secret)) {
        throw new RuntimeException('Fragmento interno inválido.');
    }
}
echo "PASS: 50 nomes de salas, 10 faróis solitários, sorteio estável por conta e assinaturas internas.\n";
