const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../frontend/portal-api.js'), 'utf8');
const response = (status, data) => ({status, ok: status < 400, json: async () => data});
function client(fetch) {
    const context = vm.createContext({URL, fetch, window: {},
        document: {currentScript: {src: 'http://localhost:8080/portal-api.js'}},
        location: {assign() { throw new Error('Unexpected redirect'); }}});
    vm.runInContext(source, context);
    return context.window.Portal;
}
async function run() {
    let token = 'old';
    const calls = [];
    const portal = client(async (url, options) => {
        const action = url.searchParams.get('action');
        calls.push({action, options});
        if (action === 'bootstrap') return response(200, {csrf: token});
        if (options.headers['X-CSRF-Token'] !== token) return response(403, {code:'csrf_expired', error:'Stale token'});
        return response(200, {ok:true});
    });
    await Promise.all([portal.request('bootstrap'), portal.request('bootstrap')]);
    assert.equal(calls.length, 1, 'Concurrent bootstrap must share one request');
    token = 'new';
    const credentials = {email:'test@example.test',password:'Test!Password9'};
    await portal.request('login', credentials);
    assert.deepEqual(calls.map(call => call.action), ['bootstrap','login','bootstrap','login']);
    assert.equal(calls[3].options.headers['X-CSRF-Token'], 'new');
    assert.equal(calls[1].options.body, calls[3].options.body);

    let attempts = 0;
    const offline = client(async (url) => {
        if (++attempts === 1) throw new Error('Offline');
        return response(200, url.searchParams.get('action') === 'bootstrap' ? {csrf:'fresh'} : {ok:true});
    });
    await assert.rejects(offline.request('bootstrap'), /Offline/);
    await offline.request('login', credentials);
    assert.equal(attempts, 3, 'Initial failure must not permanently block the form');

    let posts = 0;
    const broken = client(async url => url.searchParams.get('action') === 'bootstrap'
        ? response(200, {csrf:'fresh'}) : (posts++, response(403, {code:'csrf_expired',error:'Still stale'})));
    await assert.rejects(broken.request('login', credentials), /Still stale/);
    assert.equal(posts, 2, 'Retry must be bounded');

    let deniedPosts = 0;
    const forbidden = client(async url => url.searchParams.get('action') === 'bootstrap'
        ? response(200, {csrf:'fresh'}) : (deniedPosts++, response(403, {error:'Forbidden'})));
    await assert.rejects(forbidden.request('login', credentials), /Forbidden/);
    assert.equal(deniedPosts, 1, 'Other permission errors must not trigger a replay');
    console.log('Session recovery: stale token refreshed, one retry, shared bootstrap, network recovery, permission errors preserved.');
}
run().catch(error => { console.error(error); process.exit(1); });
