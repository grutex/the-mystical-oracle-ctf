const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '../..');
const runtime = fs.readFileSync(path.join(root, 'infrastructure/nginx/oracle-runtime.js.template'), 'utf8')
    .replaceAll('${PORTAL_HOST}', 'mysticaloraclectf.com.br')
    .replaceAll('${MAP_HOST}', 'mapa.mysticaloraclectf.com.br')
    .replaceAll('${CHALLENGE_HOST}', 'mysticaloraclectf.com.br');

for (const local of [false, true]) {
    const suffix = local ? ':8080' : '';
    const scheme = local ? 'http:' : 'https:';
    const portal = local ? 'localhost' : 'mysticaloraclectf.com.br';
    const map = local ? 'mapa.localhost' : 'mapa.mysticaloraclectf.com.br';
    const challenge = local ? 'localhost' : 'mysticaloraclectf.com.br';
    const context = {
        window: {}, location: { hostname: `earth.${challenge}`, protocol: scheme, port: local ? '8080' : '' },
        document: { readyState: 'loading', addEventListener() {} }
    };
    vm.runInNewContext(runtime, context);
    assert.equal(context.window.ORACLE_CONFIG.portalHost, portal);
    assert.equal(context.window.ORACLE_CONFIG.mapHost, map);
    assert.equal(context.window.ORACLE_CONFIG.challengeHost, challenge);
    const loginLink = {};
    const spiritLink = {};
    const spiritNode = { dataset: {}, querySelector() { return spiritLink; } };
    const landingContext = {
        ...context, document: {
            addEventListener() {}, querySelector(selector) {
                return selector === '[data-portal-oracle]' ? loginLink : selector === '.spirit-node' ? spiritNode : null;
            }
        }
    };
    vm.runInNewContext(fs.readFileSync(path.join(root, 'app_chall/00. landing/frontend/oracle_chall_landing.js'), 'utf8'), landingContext);
    landingContext.applyRuntimeLinks();
    assert.equal(loginLink.href, `${scheme}//${portal}${suffix}/oracle`);
    assert.equal(spiritLink.href, `${scheme}//${portal}${suffix}/api/api.php?action=launch&challenge=spirit`);
    assert.equal(spiritNode.dataset.subdomain, `spirit.${challenge}`);
    for (const dir of fs.readdirSync(path.join(root, 'app_chall'))) {
        const file = path.join(root, 'app_chall', dir, 'frontend/travessia.js');
        if (!fs.existsSync(file)) continue;
        const links = [{ dataset: { nav: 'mapa' } }, { dataset: { nav: 'oraculo' } }];
        const navigation = {
            ...context, window: { ORACLE_CONFIG: context.window.ORACLE_CONFIG },
            document: { addEventListener(event, callback) { callback(); }, querySelectorAll() { return links.map(link => ({
                ...link, getAttribute() { return link.dataset.nav; }, setAttribute(name, value) { link[name] = value; },
                set href(value) { link.href = value; }
            })); } }
        };
        vm.runInNewContext(fs.readFileSync(file, 'utf8'), navigation);
        assert.equal(links[0].href, `${scheme}//${map}${suffix}`, dir);
        assert.equal(links[1].href, `${scheme}//${portal}${suffix}/oracle`, dir);
        if (dir.startsWith('05.')) assert.equal(navigation.window.resolverDestino('proximo'), `${scheme}//${map}${suffix}`);
        if (dir.startsWith('04.')) assert.equal(navigation.window.resolverDestino('proximo'), `${scheme}//earth.${challenge}${suffix}`);
    }
}
console.log('Domínios públicos/locais: mapa, portal e travessia dos seis desafios corretos.');
