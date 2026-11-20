<?php
/**
 * MYSTICAL ORACLE CTF — MURAL DOS REFLEXOS (Desafio 04 · water)
 * ============================================================
 * VULNERÁVEL DE PROPÓSITO: Stored XSS.
 *
 *  POST -> persiste um "reflexo" da conta autenticada. A "sanitização" é um
 *          blocklist INGÊNUO (remove só <script>), fácil de burlar com
 *          handlers de evento (<img onerror>, <svg onload>, <button onclick>).
 *  GET  -> devolve os reflexos da própria conta; o mural renderiza seu HTML
 *          para permitir que o jogador teste a execução localmente.
 *
 * O ponto de coleta (correnteza) NÃO aparece na tela nem no DOM: ele viaja num
 * CABEÇALHO de resposta (X-Mare-Correnteza). O jogador precisa inspecionar o
 * tráfego (DevTools > Network, ou curl -i) para achá-lo — recon de propósito.
 *
 * O payload executa no mural do jogador e na moderação da Guardiã
 * (revisar.php). O link selado só existe no contexto da Guardiã.
 */

require __DIR__ . '/lib.php';

$acct = water_account_id();
$db = water_db();

// A credencial de coleta vai apenas no cabeçalho, junto da pista. Remove o
// cookie do jogador emitido pela versão anterior; o selo continua na Guardiã.
$secure = oracle_cookie_secure();
setcookie('TOKEN', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => $secure, 'samesite' => 'Lax']);
$token = water_drop_token($acct);
header("X-Mare-Correnteza: fetch('/correnteza.php?drop=$token',{method:'POST',body:window.__MARES__.arquivo})");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $corpo = (string)($_POST['corpo'] ?? '');
    if (trim($corpo) === '') challenge_json(['error' => 'O reflexo está vazio.'], 400);
    if (strlen($corpo) > WATER_REFLEXO_MAX) challenge_json(['error' => 'Reflexo longo demais para a superfície.'], 413);

    // "Sanitização" deliberadamente insuficiente: só neutraliza a tag <script>.
    // (Em produção real: escapar a saída / CSP / allowlist de HTML.)
    $corpo = preg_replace('/<\s*\/?\s*script/i', '', $corpo);

    $st = $db->prepare('INSERT INTO reflexos (acct, corpo) VALUES (?, ?)');
    $st->execute([$acct, $corpo]);
    challenge_json(['ok' => true, 'id' => (int)$db->lastInsertId()]);
}

// GET: lista os reflexos da conta (o ponto de coleta já foi no cabeçalho).
$st = $db->prepare('SELECT id, corpo, revisado, criado_em FROM reflexos WHERE acct = ? ORDER BY id DESC LIMIT 30');
$st->execute([$acct]);
$reflexos = array_map(static function (array $r): array {
    return [
        'id' => (int)$r['id'],
        'corpo' => (string)$r['corpo'],
        'revisado' => (bool)$r['revisado'],
        'criado_em' => (string)$r['criado_em'],
    ];
}, $st->fetchAll(PDO::FETCH_ASSOC));

challenge_json(['reflexos' => $reflexos]);
