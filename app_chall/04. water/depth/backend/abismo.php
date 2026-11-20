<?php
/** Arquivo reservado: ingresso do primeiro XSS e consulta da segunda etapa. */
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
if (isset($_GET['selo'])) {
    $token = is_string($_GET['selo']) ? $_GET['selo'] : '';
    $acct = water_entry_acct($token);
    if (!$acct) { http_response_code(403); exit('Ingresso das profundezas inválido.'); }
    $secure = oracle_cookie_secure();
    setcookie('oracle_depth', $token, ['path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    water_chain_db()->prepare('INSERT OR IGNORE INTO water_entries (acct) VALUES (?)')->execute([$acct]);
    header('Location: /abismo.php', true, 303);
    exit;
}
$acct = depth_account_id();
$surface = water_surface_origin() . '/espelho';
$mensagemOculta = challenge_water_message($acct);
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Profundezas da Maré · Cântico do Amor</title>
    <link rel="stylesheet" href="/abismo.css">
</head>
<body>
<main class="poema">
    <h1>Cântico das Profundezas</h1>
    <p class="sub">um poema para quem aprende a amar como o mar</p>

    <div class="estrofe"><p>
        Amar é ser maré: partir e retornar,<br>
        gastar-se contra a pedra e ainda assim voltar,<br>
        trazer na espuma o nome que aprendi a guardar,<br>
        e sobre a areia fria escrevê-lo devagar.
    </p></div>

    <div class="estrofe"><p>
        Foste o primeiro sal a arder na minha sede,<br>
        a corrente sem mapa que meu peito concede,<br>
        o naufrágio que escolho toda vez que amanhece,<br>
        e o porto que, sem muros, mesmo assim me acolhe.
    </p></div>

    <div class="estrofe"><p>
        Se o tempo é uma ressaca que arrasta o que fui,<br>
        teu riso é a lua branca que a água reconstrói;<br>
        em cada onda quebrada um verso se dilui,<br>
        e no que se desfaz, teu nome me constrói.
    </p></div>

    <div class="estrofe"><p>
        Amei-te em dias calmos, de espelho sem tremor,<br>
        e amei-te na tormenta, no grito e no pavor,<br>
        porque o amor que é raso evapora ao primeiro calor,<br>
        mas o que desce ao fundo não teme o dissabor.
    </p></div>

    <div class="estrofe"><p>
        Desci por ti ao escuro onde a luz não alcança,<br>
        onde o peixe é cego e o silêncio dança,<br>
        e ali, no frio imenso da mais funda distância,<br>
        achei que todo abismo guarda alguma esperança.
    </p></div>

    <div class="estrofe"><p>
        Não me peças a calma da lagoa parada,<br>
        pois te amo com a força de uma vaga apressada;<br>
        se queres o meu tudo, terás também a enxurrada,<br>
        a cheia, a vazante, a promessa molhada.
    </p></div>

    <div class="estrofe"><p>
        Há noites em que a bruma me esconde de mim,<br>
        e só o teu farol me devolve ao enfim;<br>
        navego teu olhar como quem lê um jardim<br>
        de corais que floresceram só para dizer sim.
    </p></div>

    <div class="estrofe"><p>
        Quantas cartas salgadas o vento me roubou,<br>
        quantas marés de ausência teu barco atravessou,<br>
        e ainda assim, viajante, meu leito te esperou,<br>
        paciente como a areia que a onda perdoou.
    </p></div>

    <div class="estrofe"><p>
        Ensinaste-me a arte de conter tempestade,<br>
        de ser fundo e ser manso, de ter e dar vontade,<br>
        de amar sem afogar, com toda a liberdade<br>
        de quem devolve a concha à sua imensidade.
    </p></div>

    <div class="estrofe"><p>
        Se um dia eu me evaporo e viro só neblina,<br>
        hei de cair de novo na tua campina,<br>
        porque a água que ama não conhece rotina:<br>
        ela sobe, ela chove, ela sempre destina.
    </p></div>

    <div class="estrofe"><p>
        E quando enfim a lua chamar o mar de volta,<br>
        e a última das ondas na costa se der solta,<br>
        que reste este cântico, esta espuma resolta,<br>
        provando que te amei em cada maré revolta.
    </p></div>

    <div class="estrofe"><p>
        Pois amar é ser maré: partir e retornar —<br>
        e eu, que sou de água, só sei te encontrar.
    </p></div>

    <p class="fim">✦ ⟡ ✦</p>

    <p class="rodape-oculto"><?= depth_escape($mensagemOculta) ?></p>

    <section class="arquivo">
        <h2>Solicitar consulta reservada</h2>
        <p>Indique o termo que a Arquivista deve localizar. Ela abrirá a consulta para conferi-lo.</p>
        <form id="consulta-form" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= depth_escape(depth_csrf($acct)) ?>">
            <label for="termo">Termo de busca</label>
            <textarea id="termo" name="termo" maxlength="2048" required placeholder="Qual trecho o arquivo deve procurar?"></textarea>
            <button type="submit">Solicitar à Arquivista</button>
        </form>
        <p id="consulta-status" role="status"></p>
        <a href="<?= depth_escape($surface) ?>" target="_blank" rel="noopener">Acompanhar a Correnteza no Espelho</a>
    </section>
</main>
<script src="/oracle-runtime.js"></script><script src="/abismo.js?v=revelar-ingresso-1"></script>
</body>
</html>
