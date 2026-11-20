const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('app_login/frontend/login/auth.js', 'utf8');
(async () => {
    for (const authenticated of [true, false]) {
        let redirected = null;
        const nodes = new Map();
        const element = id => {
            if (!nodes.has(id)) nodes.set(id, {type: 'password', addEventListener() {}, setAttribute() {}});
            return nodes.get(id);
        };
        const context = {
            document: {getElementById: element, querySelector: element},
            Portal: {request: async action => { assert.equal(action, 'bootstrap'); return {authenticated}; }, page: path => 'https://example.test/' + path},
            location: {replace(url) { redirected = url; }}
        };
        vm.runInNewContext(source, context);
        await new Promise(resolve => setImmediate(resolve));
        assert.equal(redirected, authenticated ? 'https://example.test/oracle' : null);
    }
    console.log('Login navigation: authenticated users return to Oracle; anonymous users keep the login form.');
})();
