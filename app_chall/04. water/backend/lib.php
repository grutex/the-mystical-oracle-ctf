<?php
/**
 * MYSTICAL ORACLE CTF — ESPELHO DAS ÁGUAS / PORTÃO DA ÁGUA (Desafio 04 · water)
 * Núcleo compartilhado: armazenamento dos reflexos (Stored XSS), coletor da
 * "correnteza" (exfiltração), credencial da Guardiã e helpers de isolamento.
 *
 * DECISÕES DE ARQUITETURA (importantes para o CTF):
 *  - A VULNERABILIDADE é Stored XSS: o jogador persiste um "reflexo" cujo HTML
 *    é renderizado CRU na página de moderação que a Guardiã (bot headless)
 *    abre. Ler o reflexo executa o payload no contexto autenticado dela.
 *  - No contexto da Guardiã há o LINK de um arquivo selado (abismo.php) com um
 *    token DERIVADO POR CONTA (HMAC). O XSS rouba esse link; abri-lo no navegador
 *    entrega a flag da conta. O link da SUA Guardiã só abre o SEU arquivo.
 *  - O coletor "correnteza" simula o servidor do atacante: o jogador recebe um
 *    ponto de coleta assinado (por conta) e aponta o payload para ele. Assim
 *    tudo é self-contained (sem webhook/internet externa).
 *  - A página da Guardiã (revisar.php) é protegida por um token interno
 *    (WATER_BOT_TOKEN): o jogador NÃO pode abri-la — senão leria o link sem XSS.
 */

require_once __DIR__ . '/challenge-auth.php';
require_once dirname(__DIR__) . '/private/water-chain.php';

const WATER_REFLEXO_MAX = 4096;   // tamanho máximo de um reflexo
const WATER_CORRENTEZA_MAX = 40;  // capturas guardadas por conta (anel)

/* Token interno entre o desafio e o bot (nunca vai ao cliente). */
function water_bot_token(): string {
    return (string)(getenv('WATER_BOT_TOKEN') ?: 'dev-water-bot-token-troque-em-producao');
}

/* ---------------- armazenamento (SQLite) ---------------- */
function water_db(): PDO {
    static $pdo;
    if ($pdo) return $pdo;
    $dir = getenv('WATER_DATA') ?: '/var/www/data';
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    $pdo = new PDO('sqlite:' . $dir . '/water.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA busy_timeout=5000');
    $pdo->exec('PRAGMA journal_mode=WAL');   // ~70 alunos lendo/escrevendo em paralelo
    $pdo->exec('CREATE TABLE IF NOT EXISTS reflexos (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        acct       INTEGER NOT NULL,
        corpo      TEXT NOT NULL,
        revisado   INTEGER NOT NULL DEFAULT 0,
        criado_em  TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS correnteza (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        acct       INTEGER NOT NULL,
        dado       TEXT NOT NULL,
        criado_em  TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS reflexos_queue ON reflexos (revisado, acct, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS reflexos_account ON reflexos (acct, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS correnteza_account ON correnteza (acct, id)');
    return $pdo;
}

/* ---------------- token do arquivo selado (abismo) — HMAC por conta ---------------- */
/* Autoriza abrir abismo.php. Vive no contexto da Guardiã (é o alvo do XSS) e é
   POR CONTA: o link roubado da SUA Guardiã só abre o SEU arquivo. */
function water_selo_token(int $acct): string {
    return water_entry_token($acct);
}

function water_selo_acct(?string $token): ?int {
    return water_entry_acct($token);
}

/* ---------------- ponto de coleta (correnteza) assinado por conta ---------------- */
function water_drop_token(int $acct): string {
    return water_collection_token($acct);
}

/* Cookie interno da Guardiã. O jogador recebe a credencial de coleta somente
   no cabeçalho X-Mare-Correnteza, sem cookie no host público. */
function water_drop_cookie(int $acct, bool $secure = false): void {
    setcookie('TOKEN', water_drop_token($acct), [
        'path' => '/',
        'secure' => $secure,
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

function water_drop_acct(?string $token): ?int {
    return water_collection_acct($token);
}

/* ---------------- acesso do jogador (exige ingresso do portal) ---------------- */
/* Retorna o account id autenticado ou encerra a requisição. */
function water_account_id(): int {
    $identity = challenge_identity();
    if (!$identity) {
        challenge_json(['error' => 'Entre no portal e abra o desafio novamente.'], 401);
    }
    return (int)$identity['uid'];
}
