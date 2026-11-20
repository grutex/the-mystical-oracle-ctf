(function () {
    const config = window.ORACLE_CONFIG || {};
    const origin = (host) => `${location.protocol}//${host}${location.port ? `:${location.port}` : ''}`;
    const portal = origin(config.portalHost || 'mysticaloraclectf.com.br');
    const map = origin(config.mapHost || config.challengeHost || 'mapa.mysticaloraclectf.com.br');
    const destinations = { oraculo: `${portal}/oracle`, mapa: map };

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-nav]').forEach((link) => {
            const destination = destinations[link.dataset.nav];
            if (destination) link.href = destination;
        });
    });
})();
