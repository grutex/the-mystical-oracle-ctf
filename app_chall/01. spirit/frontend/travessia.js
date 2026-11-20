/**
 * MYSTICAL ORACLE CTF — TRAVESSIA (resolvedor de navegação entre telas)
 * ---------------------------------------------------------------------
 * Cada desafio vive num subdomínio. Em PRODUÇÃO os botões apontam para
 * os subdomínios reais; em LOCALHOST eles apontam para os arquivos locais
 * (exige servir a RAIZ do projeto, ex.: `python3 -m http.server` na raiz).
 *
 * Uso no HTML: dê a cada link/botão um atributo data-nav="<chave>".
 *   <a data-nav="mapa">...</a>
 * O href é preenchido automaticamente conforme o ambiente.
 *
 * Para trocar os domínios de produção, edite apenas o bloco DEST abaixo.
 */
(function () {
    const host = location.hostname;
    const config = window.ORACLE_CONFIG || {};
    const configured = Boolean(config.portalHost && config.challengeHost);
    const ehLocal = !configured && (host === 'localhost' || host === '127.0.0.1' || host === '' || host === '0.0.0.0');
    const origin = (hostname) => `${location.protocol}//${hostname}${location.port ? `:${location.port}` : ''}`;
    const portal = origin(config.portalHost || 'mysticaloraclectf.com.br');
    const challenges = config.challengeHost || 'mysticaloraclectf.com.br';

    // Raiz local do projeto quando servido a partir da pasta do repositório.
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
        // Próximo elemento da cadeia: Ar (ainda não construído -> cai no mapa localmente)
        proximo: {
            prod:  `${portal}/api/api.php?action=launch&challenge=air`,
            local: `${R}/00.landing/frontend/oracle_chall_landing.html`
        },
        // Voltar ao Portão do Espírito (relativo — funciona em ambos)
        portao: {
            prod:  '/oracle-spirit',
            local: '/oracle-spirit'
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
