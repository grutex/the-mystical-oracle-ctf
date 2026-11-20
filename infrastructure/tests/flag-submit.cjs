const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../..');
const flag = 'ORACLE{clipboard_test_123}';
const games = [
    ['01. spirit', '0232a57db92c1c62161124a6ed855f96/veil', 'spirit'],
    ['02. air', 'arquivo', 'air'],
    ['03. fire', 'forja', 'fire'],
    ['04. water', 'espelho', 'water'],
    ['05. earth', 'catalogo', 'earth'],
    ['06. light&dark', 'oracle_light_dark', 'light'],
];

async function run() {
    const browser = await chromium.launch({headless:true, executablePath:process.env.PLAYWRIGHT_EXECUTABLE_PATH || undefined});
    try {
        for (const [directory, screen, kind] of games) {
            for (const fallback of [false, true]) {
                const context = await browser.newContext();
                const page = await context.newPage();
                const errors = [];
                page.on('pageerror', error => errors.push(error.message));
                const frontend = path.join(root, 'app_chall', directory, 'frontend');
                const html = fs.readFileSync(path.join(frontend, screen + '.html'), 'utf8')
                    .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '')
                    .replace(/<link\b[^>]*>/gi, '');
                await page.route('**/*', route => route.fulfill({contentType:'text/html', body:html}));
                await page.goto('http://game.localhost/test');
                await page.evaluate(({fallback}) => {
                    window.ORACLE_CONFIG = {portalHost:'portal.localhost', challengeHost:'ctf.localhost'};
                    Object.defineProperty(navigator, 'clipboard', {configurable:true, value:{writeText:async text => {
                        if (fallback) throw new Error('API indisponível em HTTP');
                        window.copied = text;
                    }}});
                    document.execCommand = action => {
                        if (action !== 'copy') return false;
                        window.copied = document.activeElement.value;
                        return true;
                    };
                }, {fallback});
                await page.addScriptTag({path:path.join(frontend, 'travessia.js')});
                await page.addScriptTag({path:path.join(root, 'infrastructure/challenge/flag-submit.js')});
                await page.addScriptTag({path:path.join(frontend, kind === 'light' ? 'equilibrio.js' : screen + '.js')});
                await page.evaluate(({kind, flag}) => {
                    if (kind === 'spirit') mostrarHit(document.getElementById('veil-result'), flag);
                    else if (kind === 'fire') mostrarFlag(flag);
                    else if (kind === 'light') {
                        document.getElementById('final-flag').value = flag;
                        document.getElementById('reward-result').hidden = false;
                    } else {
                        const out = document.createElement('div');
                        document.body.append(out);
                        out.innerHTML = ctaFlag();
                        ligarCopiar(out, flag);
                    }
                    let node = document.getElementById(kind === 'light' ? 'copy-flag' : 'copy-flag-btn');
                    while (node) { node.hidden = false; node = node.parentElement; }
                }, {kind, flag});
                const button = page.locator(kind === 'light' ? '#copy-flag' : '#copy-flag-btn');
                assert.equal(await button.textContent(), 'Copiar e Submeter Flag ->');
                await button.click();
                await page.waitForFunction(() => document.querySelector('#copy-flag, #copy-flag-btn').textContent === 'flag copiada! redirecionando...');
                assert.equal(await page.evaluate(() => window.copied), flag);
                assert.equal(await button.isDisabled(), true);
                await page.waitForURL('http://portal.localhost/oracle');
                assert.deepEqual(errors, []);
                await context.close();
            }
        }
        // Uma cópia recusada mantém o jogador na tela e permite tentar novamente.
        const page = await browser.newPage();
        await page.route('**/*', route => route.fulfill({contentType:'text/html', body:'<button id="copy">Copiar e Submeter Flag -&gt;</button>'}));
        await page.goto('http://game.localhost/test');
        await page.addScriptTag({path:path.join(root, 'infrastructure/challenge/flag-submit.js')});
        await page.evaluate(() => {
            Object.defineProperty(navigator, 'clipboard', {value:{writeText:async () => {throw new Error('negado');}}});
            document.execCommand = () => false;
            document.getElementById('copy').onclick = () => window.oracleCopyAndSubmitFlag('ORACLE{test}', document.getElementById('copy'));
        });
        await page.locator('#copy').click();
        await page.waitForFunction(() => document.getElementById('copy').textContent.includes('Não foi possível copiar'));
        assert.equal(await page.locator('#copy').isEnabled(), true);
        assert.equal(page.url(), 'http://game.localhost/test');
        console.log('PASS: seis desafios copiam a flag, mostram confirmação e redirecionam ao /oracle do portal; fallback HTTP e falha de cópia.');
    } finally {
        await browser.close();
    }
}
run().catch(error => {console.error(error); process.exitCode = 1;});
