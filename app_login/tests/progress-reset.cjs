const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('infrastructure/nginx/oracle-runtime.js.template', 'utf8')
    .replaceAll('${PORTAL_HOST}', 'localhost').replaceAll('${MAP_HOST}', 'mapa.localhost').replaceAll('${CHALLENGE_HOST}', 'localhost');

(async () => {
    for (const [pathname, reset, expected] of [['/forja', true, 'http://localhost:8080/oracle'], ['/forja', false, 'http://localhost:8080/login'], ['/consulta.php', true, null]]) {
        let mount;
        let redirected = null;
        const storage = new Map([['arq_auth', 'guest']]);
        const context = {
            window: {}, location: {hostname: 'fire.localhost', protocol: 'http:', port: '8080', pathname, replace(url) { redirected = url; }},
            document: {readyState: 'loading', hidden: false, body: {appendChild() {}}, querySelectorAll() { return []; },
                createElement() { return {style: {}, setAttribute() {}}; }, addEventListener(event, callback) { if (event === 'DOMContentLoaded') mount = callback; }},
            sessionStorage: {removeItem(key) { storage.delete(key); }},
            fetch: async () => ({ok: true, json: async () => ({contest: {elapsed_seconds: 0}, authenticated: false, progress_reset: reset})}),
            setInterval() {}, Date
        };
        vm.runInNewContext(source, context);
        mount();
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(redirected, expected);
        assert.equal(storage.has('arq_auth'), !(reset && expected !== null));
    }
    console.log('Reset UI: open challenges return to Oracle, Air login cleared, session loss and bot pages preserved.');
})();
