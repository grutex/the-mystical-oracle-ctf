/**
 * MYSTICAL ORACLE CTF — TRAVESSIA (resolvedor de navegação entre telas)
 * ---------------------------------------------------------------------
 * Em PRODUÇÃO os botões apontam para os subdomínios reais; em LOCALHOST
 * apontam para os arquivos locais (exige servir a RAIZ do projeto).
 *
 * Uso no HTML: dê a cada link/botão um atributo data-nav="<chave>".
 * Para trocar os domínios de produção, edite apenas o bloco DEST.
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
        // Oráculo = tela de submeter flag (/oracle com o #flag-form)
        oraculo: {
            prod:  `${portal}/oracle`,
            local: '/oracle'
        },
        // Mapa dos cinco elementos
        mapa: {
            prod:  origin(config.mapHost || challenges),
            local: `${R}/00.landing/frontend/oracle_chall_landing.html`
        },
        // Próximo elemento da cadeia: Fogo (ainda não construído -> cai no mapa localmente)
        proximo: {
            prod:  `${portal}/api/api.php?action=launch&challenge=fire`,
            local: `${R}/00.landing/frontend/oracle_chall_landing.html`
        },
        // Voltar ao Portão do Ar (relativo — ventos.html e oracle_air.html são vizinhos)
        portao: {
            prod:  '/oracle-air',
            local: '/oracle-air'
        }
    };

    window.resolverDestino = function (chave) {
        const d = DEST[chave];
        if (!d) return '#';
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
