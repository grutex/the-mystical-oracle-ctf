<?php
/**
 * MYSTICAL ORACLE CTF — A CORRENTEZA (Desafio 04 · water)
 * =======================================================
 * O "servidor do atacante", self-contained. Dois modos:
 *
 *  1) SINK (exfiltração): qualquer requisição com ?drop=<token> grava o dado
 *     recebido sob a conta assinada no token. É para cá que o payload XSS,
 *     executado no navegador da Guardiã, manda o selo roubado. Não exige
 *     ingresso do portal (a Guardiã não é o jogador) — o token do dreno é a
 *     autorização, e ele é dado ao jogador pelo mural (reflexo.php).
 *
 *  2) LEITURA: requisição autenticada (ingresso do portal) sem ?drop devolve
 *     as capturas da própria conta, que o Espelho exibe em tempo real.
 *
 * O dado é sempre exibido ESCAPADO no front — a captura em si não é um sink.
 */

require __DIR__ . '/lib.php';

$db = water_db();

/* ---------------- modo SINK: ?drop=<token> ---------------- */
if (isset($_GET['drop'])) {
    $acct = water_drop_acct((string)$_GET['drop']);
    if ($acct === null) { http_response_code(403); header('Cache-Control: no-store'); exit('corrente desconhecida'); }

    // Aceita o dado por 'd' (querystring, cômodo para new Image().src=...) ou
    // pelo corpo bruto do POST (fetch). Guardamos um recorte defensivo.
    $dado = (string)($_GET['d'] ?? $_POST['d'] ?? file_get_contents('php://input') ?: '');
    water_collect($acct, $dado);

    // Resposta mínima; permissiva para não travar o fetch do payload.
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('Access-Control-Allow-Origin: *');
    echo 'ok';
    exit;
}

/* ---------------- modo LEITURA: jogador autenticado ---------------- */
$acct = water_account_id();
$st = $db->prepare('SELECT id, dado, criado_em FROM correnteza WHERE acct = ? ORDER BY id DESC LIMIT ?');
$st->execute([$acct, WATER_CORRENTEZA_MAX]);
$capturas = array_map(static function (array $r): array {
    $captura = ['id' => (int)$r['id'], 'criado_em' => (string)$r['criado_em']];
    $dado = (string)$r['dado'];
    try {
        $parecer = json_decode($dado, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        $parecer = null;
    }
    if (is_array($parecer) && is_string($parecer['mensagem'] ?? null) && is_string($parecer['recibo'] ?? null)) {
        return $captura + ['mensagem' => $parecer['mensagem'], 'recibo' => $parecer['recibo']];
    }
    return $captura + ['dado' => $dado];
}, $st->fetchAll(PDO::FETCH_ASSOC));

challenge_json(['capturas' => $capturas]);
