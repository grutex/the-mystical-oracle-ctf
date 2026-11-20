const fs = require('node:fs');
const assert = require('node:assert/strict');
const puppeteer = require('puppeteer');

// Real browser, controlled network and timers: no calls reach a running contest.
(async () => {
    const browser = await puppeteer.launch({headless: true, args: ['--no-sandbox']});
    try {
        for (const kind of ['runtime', 'water']) {
            const page = await browser.newPage();
            await page.setContent('<main></main><form id="reflexo-form"></form><div id="meus-reflexos"></div><div id="correnteza"></div>');
            await page.evaluate(() => {
                window.calls = [];
                window.intervals = [];
                window.timeouts = [];
                window.testHidden = false;
                Object.defineProperty(document, 'hidden', {get: () => window.testHidden});
                window.setInterval = (callback, ms) => { window.intervals.push({callback, ms}); return window.intervals.length; };
                window.setTimeout = (callback, ms) => { window.timeouts.push({callback, ms}); return window.timeouts.length; };
                window.clearTimeout = () => {};
                window.fetch = (url, options) => new Promise((resolve, reject) => {
                    window.calls.push({url, resolve: () => resolve({ok: true, json: async () => ({
                        contest: {started_at: '2026-01-01', elapsed_seconds: 60}, authenticated: true,
                        reflexos: [], capturas: []
                    })})});
                    options.signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
                });
            });
            const path = kind === 'runtime' ? '/tmp/oracle-runtime-test.js' : '/tmp/oracle-water-test.js';
            await page.addScriptTag({content: fs.readFileSync(path, 'utf8')});
            if (kind === 'water') await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
            const initial = kind === 'runtime' ? 1 : 2;
            assert.equal(await page.evaluate(() => window.calls.length), initial);
            await page.evaluate(() => { for (let i = 0; i < 3; i++) for (const timer of window.intervals) timer.callback(); });
            assert.equal(await page.evaluate(() => window.calls.length), initial, `${kind}: overlapping requests`);
            await page.evaluate(async () => { window.calls.forEach(call => call.resolve()); await new Promise(queueMicrotask); });
            await page.evaluate(() => { window.testHidden = true; window.intervals.forEach(timer => timer.callback()); });
            assert.equal(await page.evaluate(() => window.calls.length), initial, `${kind}: hidden tab polled`);
            await page.evaluate(() => { window.testHidden = false; document.dispatchEvent(new Event('visibilitychange')); });
            assert.equal(await page.evaluate(() => window.calls.length), initial * 2, `${kind}: no refresh on return`);
            await page.evaluate(async () => { window.timeouts.filter(timer => timer.ms === 10000).forEach(timer => timer.callback()); await new Promise(queueMicrotask); });
            await page.evaluate(() => window.intervals.forEach(timer => timer.callback()));
            assert.equal(await page.evaluate(() => window.calls.length), initial * 3, `${kind}: timeout kept request locked`);
            await page.close();
        }
        console.log('OK: runtime and Water avoid overlapping/hidden polls, refresh on return and recover from timeout.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
