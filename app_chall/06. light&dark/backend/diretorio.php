<?php
declare(strict_types=1);

require __DIR__ . '/challenge-auth.php';

try {
    if (!challenge_identity()) {
        challenge_json(['error' => 'Entre no portal e abra o desafio novamente.'], 401);
    }

    // A primeira tela conhece somente o diretório inicial e os seus nomes.
    // Ela não recebe caminho do navegador, nem contém dados dos arquivos.
    challenge_json([
        'entries' => ['caderno-vigilia.md', 'chaveiro.ini', 'inventario-selos.json', 'leitura-inicial.txt', 'manifesto.txt', 'registro-rupturas.csv', 'revogacoes.log', 'selo-cinza.asc', 'selo-da-lua.pem', 'trilha-auditoria.log', 'vigias.txt'],
    ]);
} catch (Throwable $error) {
    error_log((string)$error);
    challenge_json(['error' => 'O diretório não respondeu.'], 500);
}
