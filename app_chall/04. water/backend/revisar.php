<?php
/**
 * MYSTICAL ORACLE CTF — MESA DE MODERAÇÃO DA GUARDIÃ (Desafio 04 · water)
 * ======================================================================
 * Página INTERNA, aberta somente pela Guardiã (bot headless) com o token
 * interno. É AQUI que o Stored XSS executa: o corpo de cada reflexo é injetado
 * CRU via innerHTML, no contexto autenticado da Guardiã — que carrega em
 * window.__MARES__ o LINK do arquivo selado das profundezas (leitura por script
 * same-origin = a vulnerabilidade). Abrir esse link entrega a flag da conta.
 *
 * O jogador NÃO deve alcançar esta página (senão leria o selo sem esforço):
 * por isso o token interno. Cada reflexo traz um botão "Aprovar" que a Guardiã
 * clica na sua rotina — o que dispara também qualquer botão que o atacante
 * tenha injetado no corpo do reflexo.
 *
 * Correção didática: escapar a saída (textContent), CSP estrita e jamais deixar
 * segredos acessíveis a script numa página que renderiza conteúdo de terceiros.
 */

declare(strict_types=1);
require __DIR__ . '/lib.php';

$acct = (int)($_GET['acct'] ?? 0);
oracle_progress_guard($acct);
if ($acct < 1 || !hash_equals(water_bot_authorization('review:surface:' . $acct), (string)($_GET['token'] ?? ''))) {
    http_response_code(403);
    exit('A Guardiã não reconhece este chamado.');
}

$db = water_db();

// Reflexos pendentes desta conta; marcamos como revisados JÁ (um reflexo é
// processado uma única vez, evitando que o bot fique preso num payload inerte).
$st = $db->prepare('SELECT id, corpo FROM reflexos WHERE acct = ? AND revisado = 0 ORDER BY id ASC LIMIT 20');
$st->execute([$acct]);
$reflexos = $st->fetchAll(PDO::FETCH_ASSOC);

if ($reflexos) {
    $ids = array_column($reflexos, 'id');
    $marca = implode(',', array_fill(0, count($ids), '?'));
    $db->prepare("UPDATE reflexos SET revisado = 1 WHERE id IN ($marca)")->execute($ids);
}

// Link do arquivo selado DESTA conta. O payload XSS mira exatamente isto:
// roubá-lo permite entrar no arquivo de anotações das profundezas.
$mares = ['arquivo' => '/abismo.php?selo=' . water_selo_token($acct)];

// JSON seguro para embutir em <script> (impede quebra de </script>); o conteúdo
// volta íntegro ao ser parseado e ainda serve para innerHTML.
$flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;
$reflexosJson = json_encode(array_map(
    static fn(array $r): array => ['id' => (int)$r['id'], 'corpo' => (string)$r['corpo']],
    $reflexos
), $flags);
$maresJson = json_encode($mares, $flags);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Guardiã das Marés · Mesa de Moderação</title>
    <style>
        body { background:#04121d; color:#d7f2ff; font-family:system-ui,Segoe UI,sans-serif; margin:0; padding:24px; }
        h1 { font-size:18px; color:#7dd3fc; }
        .selo { font-size:12px; color:#38bdf8; opacity:.5; margin-bottom:18px; }
        .reflexo { background:#082436; border:1px solid #14344a; border-radius:12px; padding:14px 16px; margin:12px 0; }
        .reflexo-corpo { margin-bottom:10px; }
        .aprovar { background:#0ea5e9; color:#04121d; border:0; border-radius:8px; padding:8px 14px; font-weight:600; cursor:pointer; }
    </style>
    <script>
        window.__MARES__ = <?= $maresJson ?>;
        const REFLEXOS = <?= $reflexosJson ?>;
    </script>
</head>
<body>
    <h1>Mesa de Moderação — Guardiã das Marés</h1>
    <div class="selo">conta #<?= (int)$acct ?> · <?= count($reflexos) ?> reflexo(s) a revisar</div>
    <div id="mural"></div>

    <script>
        const mural = document.getElementById('mural');
        for (const reflexo of REFLEXOS) {
            const card = document.createElement('div');
            card.className = 'reflexo';

            const corpo = document.createElement('div');
            corpo.className = 'reflexo-corpo';
            corpo.innerHTML = reflexo.corpo;

            const aprovar = document.createElement('button');
            aprovar.className = 'aprovar';
            aprovar.type = 'button';
            aprovar.textContent = 'Aprovar reflexo';

            card.appendChild(corpo);
            card.appendChild(aprovar);
            mural.appendChild(card);
        }
    </script>
</body>
</html>
