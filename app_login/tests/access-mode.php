<?php
declare(strict_types=1);
require '/var/www/private/access-mode.php';

putenv('PORTAL_HOST=mysticaloraclectf.com.br');
putenv('CHALLENGE_HOST=mysticaloraclectf.com.br');
putenv('LOCAL_PORT=8080');
putenv('PUBLIC_PORT=443');
putenv('COOKIE_SECURE=1');

foreach (['0', '1', '2'] as $mode) {
    putenv('ACCESS_MODE=' . $mode);
    foreach (['localhost:8080', 'mapa.localhost:8080', 'water.localhost:8080',
        'mysticaloraclectf.com.br', 'mapa.mysticaloraclectf.com.br', 'water.mysticaloraclectf.com.br'] as $host) {
        $_SERVER['HTTP_HOST'] = $host;
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $local = $mode === '0' || ($mode === '2' && ($host === 'localhost:8080' || str_contains($host, '.localhost')));
        $expected = $local ? 'http://localhost:8080' : 'https://mysticaloraclectf.com.br';
        if (oracle_public_origin(oracle_portal_host()) !== $expected || oracle_cookie_secure() === $local) {
            throw new RuntimeException("Origem/cookie incorreto: modo $mode, host $host");
        }
        $challenge = $local ? 'localhost' : 'mysticaloraclectf.com.br';
        if (oracle_challenge_host() !== $challenge) throw new RuntimeException('Host de desafio incorreto.');
    }
}
putenv('ACCESS_MODE=2');
putenv('LOCAL_PORT=9090');
$_SERVER['HTTP_HOST'] = 'earth.localhost:9090';
if (oracle_public_origin(oracle_portal_host()) !== 'http://localhost:9090') {
    throw new RuntimeException('Porta local personalizada incorreta.');
}
echo "Modos 0/1/2: origens, cookies e porta local corretos.\n";
