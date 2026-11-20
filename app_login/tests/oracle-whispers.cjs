const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const {chromium} = require('playwright');

async function run() {
    const root = path.resolve(__dirname, '../frontend');
    const runtime = fs.readFileSync(path.resolve(__dirname, '../../infrastructure/nginx/oracle-runtime.js.template'), 'utf8')
        .replaceAll('${PORTAL_HOST}', '127.0.0.1').replaceAll('${MAP_HOST}', 'mapa.localhost').replaceAll('${CHALLENGE_HOST}', '127.0.0.1');
    const messages = [];
    const errors = [];
    let statusRequests = 0, sends = 0;
    const feed = url => {
        const after = Number(url.searchParams.get('whispers_after') || 0);
        const seen = Number(url.searchParams.get('whispers_seen') || 0);
        return {messages: messages.filter(m => m.id > after).slice(-100), latestId: messages.at(-1)?.id || 0,
            seenId: seen, unreadCount: messages.filter(m => m.id > seen).length};
    };
    const server = http.createServer(async (request, response) => {
        const url = new URL(request.url, 'http://127.0.0.1');
        const admin = /fixture=admin/.test(request.headers.cookie || '');
        const tester = /fixture=test/.test(request.headers.cookie || '');
        const profile = {id: admin ? 1 : tester ? 3 : 2, username: admin ? 'Administrador' : 'Buscador', role: admin ? 'admin' : 'player', ignore_leaderboard: tester,
            solvedFlags: [], acceptedFlags: {}, score: 0, history: [], questionsCount: 0, hasCrown: false, allFlagsSolved: false, oracleFinaleSeen: false};
        if (url.pathname === '/ctf-status' || url.pathname === '/api/api.php') {
            const action = url.pathname === '/ctf-status' ? 'status' : url.searchParams.get('action');
            let result, code = 200;
            if (action === 'bootstrap') result = {csrf: 'test-csrf'};
            else if (action === 'me') result = {profile, challenges: [], contest: {}};
            else if (action === 'whispers') result = {whispers: feed(url)};
            else if (action === 'status') {
                statusRequests++;
                result = {authenticated: true, session_lost: false, is_admin: admin,
                    contest: {started_at: null, paused_at: null, closed_at: null, elapsed_seconds: 0}};
                if (url.searchParams.has('whispers_after')) result.whispers = feed(url);
            } else if (action === 'send_whisper') {
                if (!admin) { code = 403; result = {error: 'Somente o organizador pode realizar esta ação.'}; }
                else {
                    let body = ''; for await (const chunk of request) body += chunk;
                    const message = {id: ++sends, message: JSON.parse(body).message, createdAt: new Date().toISOString()};
                    messages.push(message); result = {message};
                }
            } else result = {profile};
            response.writeHead(code, {'Content-Type': 'application/json'}).end(JSON.stringify(result)); return;
        }
        if (url.pathname === '/oracle-runtime.js') {
            response.writeHead(200, {'Content-Type': 'text/javascript'}).end(runtime); return;
        }
        const filename = path.resolve(root, '.' + (url.pathname === '/oracle' ? '/landing/oracle-landing.html' : url.pathname));
        if (!filename.startsWith(root + path.sep) || !fs.existsSync(filename) || !fs.statSync(filename).isFile()) {
            response.writeHead(404).end(); return;
        }
        response.writeHead(200, {'Content-Type': ({'.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript'})[path.extname(filename)] || 'text/plain'});
        response.end(fs.readFileSync(filename));
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const url = 'http://127.0.0.1:' + server.address().port;
    let browser;
    try {
        browser = await chromium.launch({headless: true, executablePath: process.env.PLAYWRIGHT_EXECUTABLE_PATH || undefined});
        const adminContext = await browser.newContext({viewport: {width: 1920, height: 1000}});
        const playerContext = await browser.newContext({viewport: {width: 1920, height: 1000}});
        const testerContext = await browser.newContext({viewport: {width: 1920, height: 1000}});
        await adminContext.addCookies([{name: 'fixture', value: 'admin', url}]);
        await testerContext.addCookies([{name: 'fixture', value: 'test', url}]);
        const admin = await adminContext.newPage(), player = await playerContext.newPage(), tester = await testerContext.newPage();
        for (const page of [admin, player, tester]) {
            page.on('pageerror', error => errors.push(error.message));
            if (!process.env.FINALE_VISUAL) {
                await page.route('https://cdn.tailwindcss.com/**', route => route.fulfill({contentType: 'text/javascript', body: 'window.tailwind={config:{}};'}));
                await page.route('https://fonts.googleapis.com/**', route => route.fulfill({contentType: 'text/css', body: ''}));
                await page.route('https://fonts.gstatic.com/**', route => route.fulfill({status: 204, body: ''}));
            }
            await page.goto(url + '/oracle');
            await page.waitForSelector('#oracle-whispers:not([hidden])');
            await page.waitForFunction(() => window.OracleWhispers.markers.whispers_after === 0);
        }
        for (const page of [player, tester]) {
            assert.equal(await page.locator('#whispers-form').count(), 0);
            assert.equal(await page.locator('#whispers-input').count(), 0);
            assert.equal(await page.locator('#whispers-toggle > span').first().textContent(), '📋');
        }
        await admin.locator('#whispers-toggle').click();
        assert.equal(await admin.locator('#whispers-toggle > span').first().textContent(), '<<');
        assert.equal(await admin.locator('.whispers-heading > span').textContent(), '👁');
        assert(await admin.locator('#whispers-form').isVisible());
        const text = 'O véu se move. Preparem-se para a próxima travessia.\nOs sussurros alcançam todos os buscadores.';
        await admin.locator('#whispers-input').fill(text);
        await admin.locator('#whispers-send').click();
        await admin.waitForSelector('#whispers-messages li');
        await player.waitForSelector('#whispers-unread:not([hidden])', {timeout: 12000});
        assert.equal(await player.locator('#whispers-unread').textContent(), '+1');
        assert.equal(await player.locator('#whispers-panel').isVisible(), false, 'New whispers must not force the panel open');
        if (process.env.PORTAL_TEST_ARTIFACTS) {
            await admin.screenshot({path: path.join(process.env.PORTAL_TEST_ARTIFACTS, 'whispers-admin.png')});
            await player.screenshot({path: path.join(process.env.PORTAL_TEST_ARTIFACTS, 'whispers-closed.png')});
        }
        await player.locator('#whispers-toggle').click();
        await tester.locator('#whispers-toggle').click();
        await tester.waitForSelector('#whispers-messages li');
        assert.equal(await tester.locator('#whispers-messages li p').textContent(), text);
        for (const page of [player, tester]) {
            assert.equal(await page.locator('#whispers-form').count(), 0);
            assert.equal(await page.locator('#whispers-input').count(), 0);
        }
        assert.equal(await player.locator('#whispers-messages li p').textContent(), text);
        assert.match(await player.locator('#whispers-messages time').textContent(), /^O olho sussurra \(.+\):$/);
        assert.equal(await player.locator('#whispers-unread').isVisible(), false);
        if (process.env.PORTAL_TEST_ARTIFACTS) await player.screenshot({path: path.join(process.env.PORTAL_TEST_ARTIFACTS, 'whispers-player.png')});
        await player.keyboard.press('Escape');
        // Escape only closes the panel when keyboard focus is inside it.
        if (await player.locator('#whispers-panel').isVisible()) await player.locator('#whispers-toggle').click();
        assert.equal(await player.locator('#whispers-toggle > span').first().textContent(), '📋');
        const xss = '<img src=x onerror="window.whisperPwned=true"> & uma voz além do véu 👁';
        await admin.locator('#whispers-input').fill(xss);
        await admin.locator('#whispers-send').click();
        await player.waitForFunction(() => document.getElementById('whispers-unread').textContent === '+1', {timeout: 12000});
        await player.locator('#whispers-toggle').click();
        assert.equal(await player.locator('#whispers-messages li p').last().textContent(), xss);
        assert.equal(await player.locator('#whispers-messages img').count(), 0);
        assert.equal(await player.evaluate(() => window.whisperPwned), undefined);
        const forged = await player.evaluate(async () => {
            try { await Portal.request('send_whisper', {message: 'forged', role: 'admin'}); return 'accepted'; }
            catch (error) { return error.message; }
        });
        assert.match(forged, /Somente o organizador/);
        assert.equal(sends, 2);
        await player.reload();
        await player.waitForSelector('#oracle-whispers:not([hidden])');
        await player.waitForSelector('#whispers-messages li', {state: 'attached'});
        assert.equal(await player.locator('#whispers-unread').isVisible(), false, 'Read marker must persist across reloads');
        assert.equal(await player.locator('#whispers-form').count(), 0);
        await player.setViewportSize({width: 390, height: 844});
        await player.locator('#whispers-toggle').click();
        const panel = await player.locator('#whispers-panel').boundingBox();
        const button = await player.locator('#whispers-toggle').boundingBox();
        assert(panel.x >= 0 && panel.x + panel.width <= 390 && panel.y + panel.height <= 844);
        assert(button.x + button.width <= 390);
        if (process.env.PORTAL_TEST_ARTIFACTS) await player.screenshot({path: path.join(process.env.PORTAL_TEST_ARTIFACTS, 'whispers-mobile.png')});
        assert(statusRequests >= 4, 'Messages should arrive through the real timer polling');
        assert.deepEqual(errors, []);
        console.log('OK: admin composer, player read-only panel, 7-second delivery, unread badge, persistent reading, literal HTML and mobile layout.');
    } finally { if (browser) await browser.close(); await new Promise(resolve => server.close(resolve)); }
}
run().catch(error => {console.error(error); process.exitCode = 1;});
