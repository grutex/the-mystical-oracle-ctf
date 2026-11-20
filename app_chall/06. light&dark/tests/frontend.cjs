const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../frontend');
const output = process.env.LIGHT_TEST_ARTIFACTS;
const flag = 'ORACLE{l1ght_d4rk_01234567890123456789}';
const dark = 'UMBRA-0123456789ABCDEF';
const light = 'LUMEN-FEDCBA9876543210';
let sourceOverride = '';
let networkFailure = false;
const visited = new Set();
function constellation() {
    return {domains:{celeste:{label:'Celeste'},fragmento:{label:visited.has('dominio') || visited.has('fragmento') ? 'origem' : null, discovered:visited.has('dominio') || visited.has('fragmento')}}, nodes:Object.fromEntries(['aurora','zenite','crepusculo','manifesto','selo','fragmento'].map(star => [star, {
        label: star === 'manifesto' ? (visited.has('zenite') || visited.has('manifesto') ? 'pegaso' : null)
            : star === 'selo' ? (visited.has('manifesto') || visited.has('selo') ? 'argento' : null)
            : star === 'fragmento' ? (visited.has('dominio') || visited.has('fragmento') ? 'Fragmento' : null)
            : {aurora:'Aurora',zenite:'Zênite',crepusculo:'Crepúsculo'}[star],
        discovered:visited.has(star),
    }]))};
}

async function run() {
    const server = http.createServer((req, res) => {
        const url = new URL(req.url, 'http://localhost');
        const routes = {'/observatorio':'observatorio.html', '/oracle-light-dark':'oracle_light_dark.html'};
        if (url.pathname === '/oracle') {
            res.writeHead(200, {'Content-Type':'text/html'}).end('<h1>Oráculo</h1>'); return;
        }
        if (url.pathname === '/constelacao.php') {
            res.writeHead(200, {'Content-Type':'application/json'}).end(JSON.stringify(constellation())); return;
        }
        if (url.pathname === '/oracle-runtime.js') {
            res.writeHead(200, {'Content-Type':'application/javascript'}).end('window.ORACLE_CONFIG={};'); return;
        }
        if (url.pathname === '/flag-submit.js') {
            res.writeHead(200, {'Content-Type':'application/javascript'}).end(fs.readFileSync(path.resolve(__dirname, '../../../infrastructure/challenge/flag-submit.js'))); return;
        }
        if (url.pathname === '/prisma.php' || url.pathname === '/reward.php') {
            let body = '';
            req.on('data', chunk => { body += chunk; });
            req.on('end', () => {
                if (networkFailure) { req.socket.destroy(); return; }
                const data = JSON.parse(body);
                let result, status = 200;
                if (url.pathname === '/prisma.php') {
                    const source = sourceOverride || data.url;
                    const beacon = new URL(source).pathname;
                    if (beacon === '/pegaso/manifesto') result = {source, transmission: {stage:'manifesto', farol:'argento', seal:'selos', pista:'A primeira metade de seu nome é "ori".'}};
                    else if (beacon === '/argento/selos') result = {source, transmission: {stage:'selo', seal:'REDACTED', pista:'A segunda metade de seu nome é "gem".'}};
                    else if (beacon === '/') result = {source, transmission: {stage:'dominio', name:'origem', seal:'fragmento'}};
                    else if (beacon === '/fragmento') result = {source, transmission: {stage:'fragmento', fragmento_luz:light}};
                    else result = {source, transmission: {stage:'farol', beacon, message:'Registro da torre <img src=x onerror="window.injected=true">'}};
                    visited.add(result.transmission.stage === 'farol' ? beacon.slice(1) : result.transmission.stage === 'manifesto' ? 'manifesto' : result.transmission.stage);
                    result.constellation = constellation();
                } else if (data.dark === dark && data.light === light) result = {flag};
                else { status = 403; result = {error:'O equilíbrio exige os dois fragmentos da sua travessia.'}; }
                res.writeHead(status, {'Content-Type':'application/json'}).end(JSON.stringify(result));
            });
            return;
        }
        const filename = path.resolve(root, routes[url.pathname] || '.' + url.pathname);
        if (!filename.startsWith(root + path.sep) || !fs.existsSync(filename) || !fs.statSync(filename).isFile()) {
            res.writeHead(404).end(); return;
        }
        const type = {'.html':'text/html', '.css':'text/css', '.js':'application/javascript'}[path.extname(filename)];
        const content = fs.readFileSync(filename, 'utf8').replaceAll('{{LIGHT_BEACON_HOST}}', 'celeste.luz').replace('</head>', '<script src="/flag-submit.js"></script></head>');
        res.writeHead(200, {'Content-Type':`${type || 'text/plain'}; charset=utf-8`}).end(content);
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    let browser;
    try {
        browser = await chromium.launch({headless:true, executablePath:process.env.PLAYWRIGHT_EXECUTABLE_PATH || undefined});
        const context = await browser.newContext({viewport:{width:1440, height:1100}});
        await context.route('https://fonts.googleapis.com/**', route => route.fulfill({contentType:'text/css',body:''}));
        await context.route('https://fonts.gstatic.com/**', route => route.fulfill({status:204,body:''}));
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const base = `http://127.0.0.1:${server.address().port}`;
        await page.goto(base + '/observatorio');
        assert.equal(await page.locator('#star-manifesto text').textContent(), '…');
        assert.equal(await page.locator('#star-selo text').textContent(), '…');
        assert.equal(await page.locator('svg > .prism-label').textContent(), 'Celeste');
        assert.equal(await page.locator('#tower-fragmento text').textContent(), '…');
        assert.equal(await page.locator('#tower-fragmento').evaluate(node => node.classList.contains('discovered')), false);
        assert.equal(await page.locator('#star-fragmento text').textContent(), '');
        assert.equal(await page.locator('#star-fragmento').evaluate(node => getComputedStyle(node).opacity), '1');
        assert.equal(await page.locator('#star-fragmento').evaluate(node => node.classList.contains('discovered')), false);
        if (output) { fs.mkdirSync(output, {recursive:true}); await page.screenshot({path:path.join(output,'observatorio-inicial.png'),fullPage:true}); }
        await page.locator('#beacon').selectOption('http://celeste.luz/zenite');
        await page.locator('#prism-submit').click();
        await page.waitForFunction(() => document.getElementById('star-zenite').classList.contains('discovered'));
        assert.equal(await page.locator('#transmission-output img').count(), 0);
        assert.equal(await page.evaluate(() => window.injected), undefined);
        assert.equal(await page.locator('#star-manifesto text').textContent(), 'pegaso');
        // Simula o Repeater: a requisição sai fora da página aberta.
        await context.request.post(base + '/prisma.php', {data:{url:'http://celeste.luz/pegaso/manifesto'}});
        await page.waitForFunction(() => document.getElementById('star-manifesto').classList.contains('discovered'));
        assert.equal(await page.locator('#star-selo text').textContent(), 'argento');
        assert.equal(await page.locator('#star-selo').evaluate(node => node.classList.contains('discovered')), false);
        await context.request.post(base + '/prisma.php', {data:{url:'http://celeste.luz/argento/selos'}});
        await page.waitForFunction(() => document.getElementById('star-selo').classList.contains('discovered'));
        assert.equal(await page.locator('#star-fragmento text').textContent(), '');
        assert.equal(await page.locator('#star-fragmento').evaluate(node => getComputedStyle(node).opacity), '1');
        assert.equal(await page.locator('#star-fragmento').evaluate(node => node.classList.contains('discovered')), false);
        assert.ok(!(await page.locator('#discovery').textContent()).includes('foi encontrada'));
        await context.request.post(base + '/prisma.php', {data:{url:'http://origem.luz/'}});
        await page.waitForFunction(() => document.getElementById('tower-fragmento').classList.contains('discovered'));
        assert.equal(await page.locator('#tower-fragmento text').textContent(), 'origem');
        assert.equal(await page.locator('#star-fragmento text').textContent(), 'Fragmento');
        assert.equal(await page.locator('#star-fragmento').evaluate(node => node.classList.contains('discovered')), false);
        assert.equal(await page.locator('.isolated-link').count(), 1);
        assert.equal(await page.locator('.sky-map').evaluate(node => node.classList.contains('domain-revealed')), true);
        await context.request.post(base + '/prisma.php', {data:{url:'http://origem.luz/fragmento'}});
        await page.waitForFunction(() => document.getElementById('star-fragmento').classList.contains('discovered'));
        assert.equal(await page.locator('#star-fragmento text').textContent(), 'Fragmento');
        assert.equal(await page.locator('#tower-fragmento text').textContent(), 'origem');
        assert.equal(await page.locator('#tower-fragmento').evaluate(node => node.classList.contains('discovered')), true);
        await page.waitForFunction(() => getComputedStyle(document.getElementById('star-fragmento')).opacity === '1');
        await page.reload();
        await page.waitForFunction(() => document.getElementById('star-selo').classList.contains('discovered'));
        assert.equal(await page.locator('#star-manifesto text').textContent(), 'pegaso');
        assert.equal(await page.locator('#star-selo text').textContent(), 'argento');
        await page.waitForFunction(() => document.getElementById('star-fragmento').classList.contains('discovered'));
        sourceOverride = 'http://origem.luz/fragmento';
        await page.locator('#prism-submit').click();
        await page.waitForFunction(() => document.getElementById('transmission-output').textContent.includes('LUMEN-'));
        await page.waitForFunction(() => getComputedStyle(document.getElementById('star-selo')).opacity === '1');
        assert.ok((await page.locator('#transmission-output').textContent()).includes(light));
        if (output) { fs.mkdirSync(output, {recursive:true}); await page.screenshot({path:path.join(output,'observatorio-desktop.png'),fullPage:true}); }
        await page.setViewportSize({width:390,height:844});
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Observatório transborda no celular.');
        if (output) await page.screenshot({path:path.join(output,'observatorio-mobile.png'),fullPage:true});
        networkFailure = true;
        await page.locator('#prism-submit').click();
        await page.waitForFunction(() => document.getElementById('signal-status').textContent === 'Sinal interrompido');
        assert.equal(await page.locator('#prism-submit').isEnabled(), true);
        networkFailure = false;
        await page.goto(base + '/oracle-light-dark');
        assert.equal(await page.locator('a[href="/observatorio"]').count(), 1);
        await page.locator('#dark-fragment').fill(dark);
        await page.locator('#light-fragment').fill('LUMEN-0000000000000000');
        await page.locator('#equilibrium-submit').click();
        await page.waitForFunction(() => document.getElementById('equilibrium-status').textContent.includes('exige os dois'));
        assert.equal(await page.locator('#reward-result').isVisible(), false);
        await page.locator('#light-fragment').fill(light);
        await page.locator('#equilibrium-submit').click();
        await page.waitForFunction(() => !document.getElementById('reward-result').hidden);
        assert.equal(await page.locator('#final-flag').inputValue(), flag);
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Limiar transborda no celular.');
        if (output) await page.screenshot({path:path.join(output,'equilibrio-mobile.png'),fullPage:true});
        await page.setViewportSize({width:1440,height:1100});
        if (output) await page.screenshot({path:path.join(output,'equilibrio-desktop.png'),fullPage:true});
        await page.locator('#copy-flag').click();
        await page.waitForFunction(() => document.getElementById('equilibrium-status').textContent === 'flag copiada! redirecionando...');
        await page.waitForURL(base + '/oracle');
        assert.deepEqual(errors, []);
        console.log('PASS: constelação, transmissões em texto, erros, recompensa, cópia e telas de 1440/390 px.');
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
}
run().catch(error => { console.error(error); process.exitCode=1; });
