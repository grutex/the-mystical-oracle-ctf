<?php
declare(strict_types=1);
// Executar dentro de portal-web. Usa contas temporárias fora da classificação.
require '/var/www/html/api/lib.php';
if (getenv('LIGHT_TEST_DISPOSABLE') !== '1') {
    throw new RuntimeException('Execute somente pelo Compose de testes, com banco e sessões descartáveis.');
}

function light_check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

final class LightClient {
    public array $cookies = [];
    public array $sessions = [];
    public string $csrf = '';
    public function request(string $service, string $path, mixed $body = null, int $expected = 200, ?string $method = null): array {
        $headers = ['Host: ' . ($service === 'portal-web' ? 'localhost' : 'light-dark.localhost')];
        $cookie = $this->cookies[$service === 'gateway' ? 'light-dark-web' : $service] ?? '';
        if ($cookie) $headers[] = 'Cookie: ' . $cookie;
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            if ($service === 'portal-web') $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        $context = stream_context_create(['http' => [
            'method' => $method ?? ($body === null ? 'GET' : 'POST'),
            'header' => implode("\r\n", $headers), 'content' => $body === null ? '' : json_encode($body),
            'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 8,
        ]]);
        $raw = file_get_contents('http://' . $service . $path, false, $context);
        preg_match('/^HTTP\/\S+ (\d{3})/', $http_response_header[0] ?? '', $status);
        light_check((int)($status[1] ?? 0) === $expected, "$service$path: esperado $expected, recebido " . ($status[1] ?? 'sem resposta') . ': ' . $raw);
        $location = '';
        foreach ($http_response_header as $header) {
            if (preg_match('/^Set-Cookie: ((ORACLESESS|oracle_challenge)=([^;]+))/i', $header, $match)) {
                $this->cookies[$service] = $match[1];
                if ($match[2] === 'ORACLESESS') $this->sessions[] = $match[3];
            }
            if (str_starts_with($header, 'Location: ')) $location = substr($header, 10);
        }
        $data = json_decode((string)$raw, true);
        if (is_array($data) && isset($data['csrf'])) $this->csrf = $data['csrf'];
        return ['data' => $data, 'raw' => $raw, 'location' => $location];
    }
    public function portal(string $action, ?array $data = null): array {
        return $this->request('portal-web', '/api/api.php?action=' . $action, $data)['data'];
    }
    public function launch(): void {
        $response = $this->request('portal-web', '/api/api.php?action=launch&challenge=light_dark', null, 302);
        $url = parse_url($response['location']);
        $this->request('light-dark-web', $url['path'] . '?' . $url['query'], null, 302);
    }
    public function prism(string $url, int $expected = 200, array $extra = []): array {
        return $this->request('light-dark-web', '/prisma.php', ['url' => $url, ...$extra], $expected)['data'];
    }
}

$ids = [];
$clients = [];
try {
    foreach (['a', 'b'] as $suffix) {
        $name = 'luztest_' . $suffix . '_' . bin2hex(random_bytes(4));
        $password = 'Aurora!Test492';
        query('INSERT INTO Account (username, alias, email, password_hash, ignore_leaderboard) VALUES (?, ?, ?, ?, TRUE)',
            [$name, 'Viajante da Aurora', $name . '@example.test', password_hash($password, PASSWORD_DEFAULT)]);
        $id = (int)db()->lastInsertId(); $ids[] = $id;
        query('INSERT INTO CtfInfo (user_id) VALUES (?)', [$id]);
        $client = new LightClient(); $clients[] = $client;
        $client->portal('bootstrap');
        $client->portal('login', ['email' => $name . '@example.test', 'password' => $password]);
        $client->launch();
    }
    [$a, $b] = $clients;
    $anonymous = new LightClient();
    $anonymous->request('light-dark-web', '/observatorio', null, 302);
    $anonymous->request('light-dark-web', '/constelacao.php', null, 401);
    $anonymous->request('light-dark-web', '/flag-submit.js', null, 401);
    $anonymous->request('light-dark-web', '/prisma.php', ['url' => 'http://farol.luz/aurora'], 401);
    $anonymous->request('light-dark-web', '/reward.php', ['dark' => '', 'light' => ''], 401);
    $a->request('light-dark-web', '/prisma.php', null, 405);
    $a->request('light-dark-web', '/reward.php', null, 405);
    $a->request('light-dark-web', '/constelacao.php', [], 405);
    foreach (['/observatorio', '/oracle-light-dark', '/observatorio.js', '/observatorio.css', '/equilibrio.js', '/flag-submit.js'] as $path) {
        $a->request('light-dark-web', $path);
    }
    $a->request('light-dark-web', '/observatorio.html', null, 403);
    $a->request('gateway', '/observatorio');
    $routes = [];
    foreach ($clients as $client) {
        $initial = $client->request('light-dark-web', '/constelacao.php')['data']['nodes'];
        light_check($client->request('light-dark-web', '/constelacao.php')['data']['domains']['fragmento']['label'] === null, 'Triângulo entrega o domínio antes da descoberta.');
        light_check($initial['manifesto']['label'] === null && $initial['selo']['label'] === null && $initial['fragmento']['label'] === null, 'Mapa expõe nomes antes das pistas.');
        light_check(!array_filter($initial, fn($node) => $node['discovered']), 'Mapa inicial tem estrelas acesas.');
        $html = $client->request('light-dark-web', '/observatorio')['raw'];
        light_check(str_contains($html, '/flag-submit.js?v=1'), 'Tela sem fluxo compartilhado de cópia e navegação.');
        preg_match('/option value="http:\/\/([a-z]+\.luz)\/aurora"/', $html, $host);
        $beaconHost = $host[1] ?? '';
        light_check($beaconHost === 'celeste.luz' && !str_contains($html, '{{LIGHT_BEACON_HOST}}'), 'Faróis públicos fora da torre Celeste.');
        light_check($client->request('light-dark-web', '/observatorio')['raw'] === $html, 'Nomes mudaram ao recarregar.');
        $celesteRoot = $client->prism('http://celeste.luz/')['transmission'];
        light_check($celesteRoot['stage'] === 'celeste' && str_contains($celesteRoot['message'], 'postes') && !isset($celesteRoot['fragmento_luz']), 'Raiz de Celeste sem mensagem.');
        $hint = $client->prism('http://' . $beaconHost . '/zenite')['transmission'];
        $manifestName = $hint['farol'];
        light_check(preg_match('/^[a-z]+$/D', $manifestName) === 1 && $hint['seal'] === 'manifesto' && str_contains($hint['message'], 'manifesto'), 'Zênite sem pista de sala e manifesto.');
        $manifestUrl = 'http://celeste.luz/' . $manifestName . '/' . $hint['seal'];
        $map = $client->request('light-dark-web', '/constelacao.php')['data']['nodes'];
        light_check($map['zenite']['discovered'] && $map['manifesto']['label'] === $manifestName && !$map['manifesto']['discovered'], 'Zênite não atualiza a pista no mapa.');
        $welcome = $client->prism('http://celeste.luz/' . $manifestName)['transmission'];
        light_check($welcome['stage'] === 'vestibulo' && !empty($welcome['message']) && !array_intersect(['seal', 'pista', 'fragmento_luz'], array_keys($welcome)), 'Ponto do manifesto sem mensagem sutil.');
        light_check(!str_contains(json_encode($welcome), '/manifesto'), 'Mensagem entrega a entrada diretamente.');
        $map = $client->request('light-dark-web', '/constelacao.php')['data']['nodes'];
        light_check($map['manifesto']['discovered'] && $map['selo']['label'] === null && $map['fragmento']['label'] === null, 'Visitar o ponto revela a próxima pista sem consultar o manifesto.');
        light_check($client->prism('http://celeste.luz/' . $manifestName . '/')['transmission'] === $welcome, 'Ponto varia com barra final.');
        $client->prism('http://celeste.luz/' . $manifestName . '/ausente', 404);
        light_check($client->request('light-dark-web', '/constelacao.php')['data']['nodes'] === $map, 'Requisição inválida acende uma estrela.');
        $manifest = $client->prism($manifestUrl)['transmission'];
        $sealName = $manifest['farol'];
        light_check($manifest['seal'] === 'selos' && !str_contains(json_encode($manifest), 'http:') && !isset($manifest['fragmento_luz']), 'Manifesto entrega URL ou fragmento.');
        $sealUrl = 'http://celeste.luz/' . $sealName . '/' . $manifest['seal'];
        $map = $client->request('light-dark-web', '/constelacao.php')['data']['nodes'];
        light_check($map['manifesto']['discovered'] && $map['selo']['label'] === $sealName && !$map['selo']['discovered'], 'Manifesto não revela o nome do selo no mapa.');
        light_check($manifestName !== $sealName, 'Faróis perdidos com a mesma sala.');
        $welcome = $client->prism('http://celeste.luz/' . $sealName)['transmission'];
        light_check($welcome['stage'] === 'vestibulo' && !empty($welcome['message']) && !array_intersect(['seal', 'pista', 'fragmento_luz'], array_keys($welcome)), 'Ponto dos selos sem mensagem sutil.');
        light_check(!str_contains(json_encode($welcome), '/selos'), 'Mensagem entrega a entrada diretamente.');
        light_check($client->prism('http://celeste.luz/' . $sealName . '/')['transmission'] === $welcome, 'Ponto dos selos varia com barra final.');
        $seal = $client->prism($sealUrl)['transmission'];
        light_check(!isset($seal['fragmento_luz']) && $seal['seal'] === 'REDACTED' && !str_contains($seal['pista'], '/fragmento'), 'Selos entregam a entrada apagada antecipadamente.');
        preg_match('/metade de seu nome é "([a-z]+)"/', $manifest['pista'], $first);
        preg_match('/metade de seu nome é "([a-z]+)"/', $seal['pista'], $last);
        light_check(isset($first[1], $last[1]), 'Pistas incompletas do farol solitário.');
        $fragmentHost = $first[1] . $last[1] . '.luz';
        light_check($fragmentHost !== 'celeste.luz' && !str_contains($html, $fragmentHost), 'Farol solitário exposto no HTML ou localizado em Celeste.');
        $map = $client->request('light-dark-web', '/constelacao.php')['data']['nodes'];
        light_check($map['selo']['discovered'] && $map['fragmento']['label'] === null && !$map['fragmento']['discovered'], 'Mapa entrega a solução da pista antes da visita.');
        $root = $client->prism('http://' . $fragmentHost . '/')['transmission'];
        light_check($root['stage'] === 'dominio' && $root['name'] === $first[1] . $last[1] && $root['seal'] === 'fragmento' && !isset($root['fragmento_luz']), 'Raiz sorteada sem dica para o ponto.');
        $fragmentUrl = 'http://' . $fragmentHost . '/' . $root['seal'];
        $sky = $client->request('light-dark-web', '/constelacao.php')['data'];
        light_check($sky['domains']['fragmento']['label'] === $root['name'] && $sky['domains']['fragmento']['discovered'], 'Triângulo não corresponde ao domínio sorteado.');
        light_check($sky['nodes']['fragmento']['label'] === 'Fragmento' && !$sky['nodes']['fragmento']['discovered'], 'Visitar a raiz acende o ponto sem buscar o fragmento.');
        $client->prism('http://' . $fragmentHost . '/selos', 404);
        $routes[] = ['beacon' => 'http://' . $beaconHost, 'manifest' => $manifestUrl, 'seal' => $sealUrl, 'fragment' => $fragmentUrl, 'fragmentName' => $first[1] . $last[1]];
    }
    [$routeA, $routeB] = $routes;
    foreach (['aurora', 'zenite', 'crepusculo'] as $beacon) {
        light_check($a->prism($routeA['beacon'] . '/' . $beacon)['transmission']['stage'] === 'farol', 'Farol sem sinal.');
    }
    $light = $a->prism($routeA['fragment'])['transmission']['fragmento_luz'];
    $fragmentNode = $a->request('light-dark-web', '/constelacao.php')['data']['nodes']['fragmento'];
    light_check($fragmentNode['discovered'] && $fragmentNode['label'] === 'Fragmento', 'Ponto do fragmento não acende após requisição externa.');
    $domain = $a->request('light-dark-web', '/constelacao.php')['data']['domains']['fragmento'];
    light_check($domain['discovered'] && $domain['label'] === $routeA['fragmentName'], 'Triângulo do domínio não revela seu nome.');
    light_check(!$b->request('light-dark-web', '/constelacao.php')['data']['nodes']['fragmento']['discovered'], 'Mapa mistura contas.');
    light_check(preg_match('/^LUMEN-[A-F0-9]{16}$/D', $light) === 1, 'Formato da luz inválido.');
    $other = $b->prism($routeB['fragment'])['transmission']['fragmento_luz'];
    light_check($light !== $other, 'Fragmentos compartilhados entre contas.');
    light_check($light === $a->prism($routeA['fragment'], 200, ['uid' => $ids[1]])['transmission']['fragmento_luz'], 'Identidade controlada pelo cliente.');
    if ($routeA['fragment'] !== $routeB['fragment']) $a->prism($routeB['fragment'], 404);
    if ($routeA['fragmentName'] !== $routeB['fragmentName']) $a->prism('http://' . $routeB['fragmentName'] . '.luz/', 404);
    if ($routeA['seal'] !== $routeB['seal']) $a->prism($routeB['seal'], 404);
    if ($routeA['manifest'] !== $routeB['manifest']) $a->prism($routeB['manifest'], 404);
    $shadow = $a->request('light-dark-web', '/shadow.php?path=' . rawurlencode('../../../../../etc/shadow'))['data']['content'];
    preg_match('/fragmento_trevas=(UMBRA-[A-F0-9]{16})/', $shadow, $match);
    $dark = $match[1] ?? '';
    light_check($dark !== '', 'Caminho das Trevas perdeu o fragmento.');
    foreach ([[], ['dark' => $dark], ['light' => $light], ['dark' => $dark, 'light' => $other], ['dark' => [], 'light' => $light]] as $proof) {
        $a->request('light-dark-web', '/reward.php', $proof, 403);
    }
    $flag = $a->request('light-dark-web', '/reward.php', ['dark' => $dark, 'light' => $light])['data']['flag'];
    light_check($flag === personalFlag('light_dark', $ids[0]), 'Flag incompatível com o portal.');
    $b->request('light-dark-web', '/reward.php', ['dark' => $dark, 'light' => $light], 403);
    foreach (['http://portal-web/api/api.php', 'http://127.0.0.1/', 'http://169.254.169.254/', 'https://farol.luz/aurora',
        'file:///etc/passwd', 'http://farol.luz:8080/', 'http://farol.luz@portal-web/', 'http://farol.luz.evil/',
        'http://farol.luz/aurora?uid=1', 'http://farol.luz/aurora#selo', "http://farol.luz/aurora\r\nX-Test: yes", str_repeat('x', 513)] as $url) {
        $a->prism($url, 400);
    }
    foreach ([[], ['url' => []], ['url' => 12], null] as $body) $a->request('light-dark-web', '/prisma.php', $body, $body === null ? 405 : 400);
    $a->prism('http://celeste.luz/ausente', 404);
    $a->prism('http://celeste.luz/fragmento', 404);
    $a->prism('http://celeste.luz/manifesto', 404);
    $a->prism($routeA['beacon'] . '/selos', 404);
    $a->prism($routeA['seal'] . '/primeira-luz', 404);
    $a->prism('http://light-tower/selos', 400);
    light_check(gethostbyname(parse_url($routeA['manifest'], PHP_URL_HOST)) === parse_url($routeA['manifest'], PHP_URL_HOST), 'Câmara alcançável pelo portal.');
    light_check(gethostbyname('light-tower') === 'light-tower', 'Torre presente na rede do portal.');
    $challenge = query('SELECT * FROM Challenges WHERE challenge_name = ?', ['light_dark'])->fetch();
    light_check(correctFlag($challenge, $flag, query('SELECT * FROM Account WHERE acc_id = ?', [$ids[0]])->fetch()), 'Portal rejeitou a flag.');
    $contest = query('SELECT * FROM ContestState WHERE id = 1')->fetch();
    if (!$contest['closed_at']) {
        $submission = $a->portal('submit', ['challenge' => 'light_dark', 'flag' => $flag]);
        light_check($submission['correct'] && $submission['points'] === 350, 'Pontuação final inválida.');
        light_check($a->portal('submit', ['challenge' => 'light_dark', 'flag' => $flag])['points'] === 0, 'Pontos duplicados.');
    }
    $a->portal('reset', []);
    $a->prism($routeA['beacon'] . '/aurora', 401);
    $a->launch();
    light_check(!array_filter($a->request('light-dark-web', '/constelacao.php')['data']['nodes'], fn($node) => $node['discovered']), 'Reset não apaga o mapa.');
    $a->prism($routeA['beacon'] . '/aurora');
    $a->portal('logout', []);
    $a->prism($routeA['beacon'] . '/aurora', 401);
    echo "PASS: salas individuais em Celeste, pistas sem URL completa, farol solitário em outro host, SSRF real, fragmentos, 350 XP, isolamento, reset e logout.\n";
} finally {
    foreach ($ids as $id) query('DELETE FROM Account WHERE acc_id = ?', [$id]);
    publishContestState(query('SELECT * FROM ContestState WHERE id = 1')->fetch());
    foreach ($clients as $client) foreach (array_unique($client->sessions) as $sid) {
        if (preg_match('/^[A-Za-z0-9,-]{16,128}$/D', $sid)) @unlink(oracle_progress_directory() . '/sess_' . $sid);
    }
    foreach ($ids as $id) {
        @unlink(oracle_progress_directory() . '/progress-' . $id . '.lock');
        @unlink(oracle_progress_directory() . '/progress-' . $id . '.epoch');
    }
}
