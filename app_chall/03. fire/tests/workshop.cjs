const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const frontend = path.resolve(__dirname, '../frontend');
const artifacts = process.env.ORACLE_TEST_ARTIFACTS || path.join(os.tmpdir(), 'oracle-fire-workshop-ui');

(async () => {
    fs.mkdirSync(artifacts, { recursive: true });
    const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined });
    try {
        for (const width of [1440, 390]) {
            const context = await browser.newContext({ viewport: { width, height: 1100 }, acceptDownloads: true });
            try {
                await context.addCookies([{ name: 'sessions', value: 'unchanged-player-token', url: 'https://forja.test' }]);
                await context.addInitScript(() => { window.printCount = 0; window.print = () => { window.printCount++; }; });
                const page = await context.newPage();
                const errors = [];
                const renewals = [];
                let locked = false;
                let role = 'player';
                let operations = 0;
                page.on('pageerror', error => errors.push(error.message));
                await page.route('**/*', async route => {
                    const request = route.request();
                    const file = new URL(request.url()).pathname.slice(1);
                    if (file === 'whoami.php') return route.fulfill({ json: { role, user: 'Alice', portal_user: 'Alice' } });
                    if (file === 'workshop.php') {
                        if (request.method() === 'GET') return route.fulfill({ json: { ok: true, locked } });
                        assert.equal(locked, false);
                        locked = true;
                        operations++;
                        const action = new URLSearchParams(request.postData()).get('action');
                        return route.fulfill({ json: { ok: true, locked, title: `Registro: ${action}`, content: `Aprendiz: Alice\n${action}\n<script>window.injected=true</script>`, filename: 'forja-1234ABCD.txt' } });
                    }
                    if (file === 'sessions') {
                        assert.equal(request.method(), 'POST');
                        assert.equal(new URLSearchParams(request.postData()).get('action'), 'renew');
                        renewals.push(request.postData());
                        locked = false;
                        return route.fulfill({ json: { ok: true, sessions: 'unchanged-player-token', message: '(apagar depois)DEV MESSAGE: /sessions atualizado. A bancada está pronta para uma nova requisição.' } });
                    }
                    if (['forja.html', 'forja.css', 'forja.js'].includes(file)) return route.fulfill({ body: fs.readFileSync(path.join(frontend, file)), contentType: file.endsWith('.css') ? 'text/css' : file.endsWith('.js') ? 'application/javascript' : 'text/html' });
                    return route.fulfill({ body: '', contentType: 'application/javascript' });
                });
                const ready = () => page.locator('[data-workshop-action="supplies"]').waitFor({ state: 'visible' });
                await page.goto('https://forja.test/forja.html');
                await ready();
                await page.waitForFunction(() => !document.querySelector('[data-workshop-action]').disabled);
                assert.equal(await page.locator('#player-view').textContent().then(text => text.includes('/sessions')), false);
                await page.locator('#workshop-quantity').fill('0');
                await page.locator('[data-workshop-action="supplies"]').click();
                assert.equal(operations, 0);
                await page.locator('#workshop-quantity').fill('10');
                for (const action of ['supplies', 'print', 'alloy', 'seal']) {
                    await page.locator(`[data-workshop-action="${action}"]`).click();
                    await page.waitForFunction(() => document.querySelector('#workshop-result').hidden === false && document.querySelector('#workshop-renew').disabled === false);
                    assert.equal(await page.locator('[data-workshop-action]:disabled').count(), 4);
                    assert.match(await page.locator('#workshop-msg').textContent(), /Não é permitido fazer mais requisições com essa sessão/);
                    assert.equal(await page.evaluate(() => window.injected), undefined);
                    assert.equal(await page.locator('#workshop-content script').count(), 0);
                    if (action === 'supplies') {
                        await page.screenshot({ path: path.join(artifacts, `workshop-locked-${width}.png`), fullPage: true });
                        const downloadEvent = page.waitForEvent('download');
                        await page.locator('#workshop-download').click();
                        const download = await downloadEvent;
                        assert.equal(download.suggestedFilename(), 'forja-1234ABCD.txt');
                        assert.match(fs.readFileSync(await download.path(), 'utf8'), /Aprendiz: Alice/);
                        await page.reload(); await ready();
                        await page.waitForFunction(() => document.querySelector('#workshop-renew').disabled === false);
                        assert.equal(await page.locator('[data-workshop-action]:disabled').count(), 4);
                    }
                    if (action === 'print') assert.equal(await page.evaluate(() => window.printCount), 1);
                    await page.locator('#workshop-renew').click();
                    await page.waitForFunction(() => !document.querySelector('[data-workshop-action]').disabled);
                    assert.equal((await context.cookies()).find(cookie => cookie.name === 'sessions').value, 'unchanged-player-token');
                }
                assert.equal(operations, 4);
                assert.equal(renewals.length, 4);
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
                role = 'guest'; await page.reload();
                await page.waitForFunction(() => !document.querySelector('#login-view').hidden);
                assert.equal(await page.locator('[data-workshop-action]:visible').count(), 0);
                assert.deepEqual(errors, []);
                console.log(`OK ${width}px: actions, lock, renewal, unchanged cookie, reload, download, printing and safe rendering`);
            } finally { await context.close(); }
        }
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
