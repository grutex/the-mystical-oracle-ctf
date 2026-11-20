const assert = require('node:assert/strict');
const fs = require('node:fs');
const puppeteer = require('puppeteer');

(async () => {
    const browser = await puppeteer.launch({headless: true, args: ['--no-sandbox', '--disable-dev-shm-usage']});
    try {
        const page = await browser.newPage();
        await page.setRequestInterception(true);
        page.on('request', request => request.respond({contentType: 'text/html', body:
            '<form id="reflexo-form"></form><div id="meus-reflexos"></div><div id="correnteza"></div>'}));
        await page.goto('http://water.test/espelho');
        await page.evaluate(() => {
            window.captures = [{id: 1, dado: '/abismo.php?selo=8.' + 'a'.repeat(43), criado_em: '2026-10-08 12:00:00'}];
            window.reads = [];
            window.testHidden = false;
            Object.defineProperty(document, 'hidden', {get: () => window.testHidden});
            window.fetch = async url => {
                if (url === '/correnteza.php') window.reads.push(Date.now());
                return {ok: true, json: async () => ({capturas: [...window.captures], reflexos: []})};
            };
        });
        await page.addScriptTag({content: fs.readFileSync('/tmp/oracle-water-test.js', 'utf8')});
        await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
        await page.waitForSelector('.selo-form');
        await page.type('.selo-input', 'manual input');
        await page.evaluate(() => window.captures.unshift({id: 2, mensagem: 'captured message', recibo: '8.2.test-receipt', criado_em: '2026-10-08 12:00:03'}));
        await page.waitForFunction(() => document.querySelector('[data-capturas]')?.textContent.includes('8.2.test-receipt'), {timeout: 7000});
        assert.equal(await page.$eval('.selo-input', input => input.value), 'manual input');
        assert.equal(await page.$eval('.recibo-input', input => input.value), '');
        assert.match(await page.$eval('[data-capturas]', node => node.textContent), /captured message/);
        const delta = await page.evaluate(() => window.reads[1] - window.reads[0]);
        assert(delta >= 2500 && delta < 6000, `Expected a three-second poll, got ${delta} ms`);
        await page.evaluate(async () => { window.injected = false; window.captures.unshift({id: 3, dado: '<img src=x onerror="window.injected=true">'}); await pollCorrenteza(); });
        assert.equal(await page.$('[data-capturas] img'), null);
        assert.equal(await page.evaluate(() => window.injected), false);
        await page.evaluate(async () => { window.captures = []; await pollCorrenteza(); });
        assert.equal(await page.$('.selo-form'), null);
        await page.evaluate(async () => { window.captures = [{id: 4, dado: '/abismo.php?selo=8.' + 'b'.repeat(43)}]; await pollCorrenteza(); });
        await page.waitForSelector('.selo-form');
        await page.evaluate(() => { window.testHidden = true; window.captures.unshift({id: 5, mensagem: 'returned capture', recibo: '8.5.returned'}); });
        const before = await page.evaluate(() => window.reads.length);
        await new Promise(resolve => setTimeout(resolve, 3300));
        assert.equal(await page.evaluate(() => window.reads.length), before);
        await page.evaluate(() => { window.testHidden = false; document.dispatchEvent(new Event('visibilitychange')); });
        await page.waitForFunction(() => document.querySelector('[data-capturas]')?.textContent.includes('8.5.returned'));
        console.log('PASS: three-second polling, capture after entry link, manual proof preserved, safe rendering, cleared history and refresh on return.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
