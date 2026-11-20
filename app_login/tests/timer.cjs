const fs = require('node:fs');
const assert = require('node:assert/strict');
const puppeteer = require('puppeteer');

(async () => {
    const browser = await puppeteer.launch({headless: true, args: ['--no-sandbox']});
    try {
        const page = await browser.newPage();
        await page.setContent('<main>Teste do relógio</main><button data-admin-help hidden>?</button>');
        await page.evaluate(() => {
            window.testContest = {started_at: '2026-01-01', paused_at: null, closed_at: null, elapsed_seconds: 60};
            window.testAdmin = false;
            window.fetch = async () => ({ok: true, json: async () => ({contest: {...window.testContest}, authenticated: true, is_admin: window.testAdmin})});
        });
        await page.addScriptTag({content: fs.readFileSync('/tmp/oracle-runtime-test.js', 'utf8')
            .replaceAll('${PORTAL_HOST}', 'localhost').replaceAll('${CHALLENGE_HOST}', 'localhost').replaceAll('${MAP_HOST}', 'mapa.localhost')});
        await page.waitForFunction(() => document.getElementById('oracle-ctf-timer')?.textContent.includes('00:01:00'));
        const style = await page.$eval('#oracle-ctf-timer', el => ({position: getComputedStyle(el).position, z: getComputedStyle(el).zIndex}));
        assert.equal(style.position, 'fixed');
        assert.equal(style.z, '2147483647');
        assert.equal(await page.$eval('[data-admin-help]', el => el.hidden), true);
        await page.evaluate(() => { window.testAdmin = true; });
        await page.waitForFunction(() => !document.querySelector('[data-admin-help]').hidden);
        await page.click('[data-admin-help]');
        await page.waitForFunction(() => document.querySelector('dialog').open);
        assert.match(await page.$eval('dialog', el => el.textContent), /Resetar CTF/);
        await page.click('dialog button');
        await page.evaluate(() => { window.testAdmin = false; });
        await page.waitForFunction(() => document.querySelector('[data-admin-help]').hidden);
        await page.evaluate(() => { window.testContest.paused_at = '2026-01-01'; });
        await page.waitForFunction(() => document.getElementById('oracle-ctf-timer').textContent.startsWith('CTF pausado'));
        const paused = await page.$eval('#oracle-ctf-timer', el => el.textContent);
        await new Promise(resolve => setTimeout(resolve, 1200));
        assert.equal(await page.$eval('#oracle-ctf-timer', el => el.textContent), paused);
        await page.evaluate(() => { window.testContest.paused_at = null; });
        await page.waitForFunction(() => document.getElementById('oracle-ctf-timer').textContent.startsWith('CTF ·'));
        console.log('OK: contador fixo acima das telas, pausa e retomada no navegador.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
