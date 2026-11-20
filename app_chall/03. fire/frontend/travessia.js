/**
 * MYSTICAL ORACLE CTF — TRAVESSIA (resolvedor de navegação entre telas)
 * Em PRODUÇÃO -> subdomínios; em LOCALHOST -> arquivos locais / mesma origem.
 * Uso: data-nav="<chave>" nos links. Edite só o bloco DEST.
 */
(function () {
    const host = location.hostname;
    const config = window.ORACLE_CONFIG || {};
    const configured = Boolean(config.portalHost && config.challengeHost);
    const ehLocal = !configured && (host === 'localhost' || host === '127.0.0.1' || host === '' || host === '0.0.0.0');
    const origin = (hostname) => `${location.protocol}//${hostname}${location.port ? `:${location.port}` : ''}`;
    const portal = origin(config.portalHost || 'mysticaloraclectf.com.br');
    const challenges = config.challengeHost || 'mysticaloraclectf.com.br';
    const R = '/app_chall';

    const DEST = {
        oraculo: { prod: `${portal}/oracle`,                   local: '/oracle' },
        mapa:    { prod: origin(config.mapHost || challenges),                   local: `${R}/00.landing/frontend/oracle_chall_landing.html` },
        // próximo elemento da cadeia: Água
        proximo: { prod: `${portal}/api/api.php?action=launch&challenge=water`, local: `${R}/00.landing/frontend/oracle_chall_landing.html` },
        // voltar ao Portão do Fogo (relativo — forja.html e oracle_fire.html são vizinhos)
        portao:  { prod: '/oracle-fire',                      local: '/oracle-fire' }
    };

    window.resolverDestino = function (chave) {
        const d = DEST[chave]; if (!d) return '#';
        return ehLocal ? d.local : d.prod;
    };
    function aplicar(raiz) {
        (raiz || document).querySelectorAll('[data-nav]').forEach((el) => {
            const url = window.resolverDestino(el.getAttribute('data-nav'));
            if (url && url !== '#') el.setAttribute('href', url);
        });
    }
    window.aplicarTravessia = aplicar;
    document.addEventListener('DOMContentLoaded', () => aplicar(document));
})();
