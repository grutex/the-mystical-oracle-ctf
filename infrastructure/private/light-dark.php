<?php
declare(strict_types=1);

// Compartilhado pelo leitor, pela câmara interna e pela recompensa.
function oracle_eclipse_fragment(int $account, string $half, string $secret): string {
    if ($account < 1 || !in_array($half, ['shadow', 'light'], true)) {
        throw new InvalidArgumentException('Fragmento inválido.');
    }
    $prefix = $half === 'shadow' ? 'UMBRA-' : 'LUMEN-';
    return $prefix . strtoupper(substr(hash_hmac('sha256', 'light-dark:' . $half . ':' . $account, $secret), 0, 16));
}

function oracle_light_signature(int $account, int $expires, string $secret): string {
    return hash_hmac('sha256', 'light-request:' . $account . ':' . $expires, $secret);
}

function oracle_light_host_pool(): array {
    return array_map(fn(string $name): string => $name . '.luz', [
        'farol', 'camara', 'primeira', 'aurora', 'zenite', 'crepusculo', 'alvorada', 'sol', 'estrela', 'constelacao',
        'prisma', 'cristal', 'quartzo', 'opala', 'ambar', 'rubi', 'safira', 'topazio', 'diamante', 'perola',
        'luminar', 'claridade', 'fulgor', 'brilho', 'centelha', 'chama', 'faisca', 'raio', 'halo', 'aureola',
        'horizonte', 'oriente', 'nascente', 'dourado', 'marfim', 'argento', 'celeste', 'sirius', 'vega', 'rigel',
        'altair', 'deneb', 'antares', 'orion', 'lyra', 'andromeda', 'pegaso', 'fenix', 'solar', 'luzeiro',
    ]);
}

function oracle_light_fragment_pool(): array {
    return array_map(fn(string $name): string => $name . '.luz', [
        'alento', 'abrigo', 'eco', 'silencio', 'vigilia',
        'refugio', 'memoria', 'origem', 'destino', 'essencia',
    ]);
}

function oracle_light_room_pool(): array {
    // As salas usam 50 nomes, sem disputar as entradas dos três faróis públicos.
    $reserved = ['aurora.luz' => 'helios.luz', 'zenite.luz' => 'eos.luz', 'crepusculo.luz' => 'febus.luz'];
    return array_map(fn(string $name): string => $reserved[$name] ?? $name, oracle_light_host_pool());
}

function oracle_light_fragment_host(int $account, string $secret): string {
    if ($account < 1) throw new InvalidArgumentException('Conta inválida.');
    $ranked = [];
    foreach (oracle_light_fragment_pool() as $host) {
        $ranked[$host] = hash_hmac('sha256', 'light-fragment-host:' . $account . ':' . $host, $secret);
    }
    asort($ranked, SORT_STRING);
    return array_key_first($ranked);
}

// A torre pública é Celeste; as duas salas perdidas são sorteadas por conta.
function oracle_light_hosts(int $account, string $secret): array {
    if ($account < 1) throw new InvalidArgumentException('Conta inválida.');
    $ranked = [];
    foreach (oracle_light_host_pool() as $host) {
        $ranked[$host] = hash_hmac('sha256', 'light-host:' . $account . ':' . $host, $secret);
    }
    asort($ranked, SORT_STRING);
    $hosts = array_keys($ranked);
    $rooms = array_combine(oracle_light_host_pool(), oracle_light_room_pool());
    return ['beacon' => 'celeste.luz', 'manifest' => $rooms[$hosts[1]], 'seal' => $rooms[$hosts[2]]];
}

function oracle_light_observation_key(int $account): string {
    return 'light-sky:v2:' . $account . ':' . oracle_progress_epoch($account) . ':'
        . substr(hash_hmac('sha256', 'light-sky', challenge_account_secret()), 0, 16) . ':';
}

function oracle_light_observe(int $account, string $star): void {
    if (!in_array($star, ['aurora', 'zenite', 'crepusculo', 'visita_manifesto', 'visita_selo', 'manifesto', 'selo', 'dominio', 'fragmento'], true)) return;
    // Uma chave por estrela evita perder descobertas feitas simultaneamente.
    if (!apcu_store(oracle_light_observation_key($account) . $star, true, max(300, (int)(getenv('SESSION_TTL') ?: 21600)))) {
        throw new RuntimeException('Não foi possível registrar a transmissão.');
    }
}

function oracle_light_constellation(int $account): array {
    $hosts = oracle_light_hosts($account, challenge_account_secret());
    $key = oracle_light_observation_key($account);
    $visited = [];
    foreach (['aurora', 'zenite', 'crepusculo', 'visita_manifesto', 'visita_selo', 'manifesto', 'selo', 'dominio', 'fragmento'] as $star) {
        $visited[$star] = apcu_fetch($key . $star) === true;
    }
    return ['domains' => [
        'celeste' => ['label' => 'Celeste'],
        'fragmento' => ['label' => $visited['dominio'] || $visited['fragmento'] ? substr(oracle_light_fragment_host($account, challenge_account_secret()), 0, -4) : null, 'discovered' => $visited['dominio'] || $visited['fragmento']],
    ], 'nodes' => [
        'aurora' => ['label' => 'Aurora', 'discovered' => $visited['aurora']],
        'zenite' => ['label' => 'Zênite', 'discovered' => $visited['zenite']],
        'crepusculo' => ['label' => 'Crepúsculo', 'discovered' => $visited['crepusculo']],
        'manifesto' => ['label' => $visited['zenite'] || $visited['visita_manifesto'] || $visited['manifesto'] ? substr($hosts['manifest'], 0, -4) : null, 'discovered' => $visited['visita_manifesto'] || $visited['manifesto']],
        'selo' => ['label' => $visited['manifesto'] || $visited['visita_selo'] || $visited['selo'] ? substr($hosts['seal'], 0, -4) : null, 'discovered' => $visited['visita_selo'] || $visited['selo']],
        // O mapa não resolve a pista: o farol isolado só ganha nome ao ser visitado.
        'fragmento' => ['label' => $visited['dominio'] || $visited['fragmento'] ? 'Fragmento' : null, 'discovered' => $visited['fragmento']],
    ]];
}
