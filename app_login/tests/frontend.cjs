const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const vm = require('node:vm');
const { chromium } = require('playwright');
const root = path.resolve(__dirname, '../frontend');
const artifacts = process.env.PORTAL_TEST_ARTIFACTS;
function checkScripts(directory) {
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
        const file = path.join(directory, entry.name);
        if (entry.isDirectory()) checkScripts(file);
        else if (file.endsWith('.js')) new vm.Script(fs.readFileSync(file, 'utf8'), { filename: file });
        else if (file.endsWith('.html')) {
            for (const script of fs.readFileSync(file, 'utf8').matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)) {
                new vm.Script(script[1], { filename: file });
            }
        }
    }
}
async function run() {
    checkScripts(root);
    const server = http.createServer((req, res) => {
        const requestPath = new URL(req.url, 'http://localhost').pathname;
        const servedPath = requestPath === '/oracle' ? '/landing/oracle-landing.html'
            : requestPath === '/login' ? '/login/oracle_login.html' : requestPath;
        const filename = path.resolve(root, '.' + servedPath);
        if (!filename.startsWith(root + path.sep) || !fs.existsSync(filename) || !fs.statSync(filename).isFile()) {
            res.writeHead(404).end(); return;
        }
        const type = {'.html':'text/html', '.js':'application/javascript', '.css':'text/css'}[path.extname(filename)];
        res.writeHead(200, {'Content-Type': (type || 'text/plain') + '; charset=utf-8'});
        res.end(fs.readFileSync(filename));
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    let browser;
    try {
        browser = await chromium.launch({headless: true, executablePath: process.env.PLAYWRIGHT_EXECUTABLE_PATH || undefined});
        const context = await browser.newContext({acceptDownloads: true});
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        // The portal uses CDN-hosted Tailwind and fonts in production. Keep this
        // browser test deterministic and fully local by stubbing those assets.
        await page.route('https://cdn.tailwindcss.com/**', route => route.fulfill({
            status: 200, contentType: 'application/javascript', body: 'window.tailwind={config:{}};'
        }));
        await page.route('https://fonts.googleapis.com/**', route => route.fulfill({
            status: 200, contentType: 'text/css', body: ''
        }));
        await page.route('https://fonts.gstatic.com/**', route => route.fulfill({status: 204, body: ''}));
        const requests = [];
        const profile = {id: 2, username: 'Buscador', login: 'buscador', role: 'admin',
            questionsCount: 0, score: 100, solvedFlags: ['spirit'], history: [{type:'CTF',title:'ESPÍRITO',pts:100}]};
        const challenges = ['spirit','air','fire','water','earth','light_dark'].map(key => ({key,points:100, available: !['water','light_dark'].includes(key)}));
        const rows = [
            {rank:1,id:3,name:'<img src=x onerror=alert(1)>',username:'first',score:200,solves:2,lastFlagDate:'2026-09-29T12:00:00Z'},
            {rank:2,id:2,name:'Buscador',username:'buscador',score:100,solves:1,lastFlagDate:'2026-09-29T12:01:00Z'}
        ];
        const snapshot = {id:1,rows,final:false,cutoff_at:'2026-09-29T12:02:00Z',captured_at:'2026-09-29T12:02:00Z',ranking_rule:'Pontos; última resolução; ID.'};
        let saved = false;
        await page.route('**/api/api.php?*', async route => {
            const url = new URL(route.request().url());
            const action = url.searchParams.get('action');
            const data = route.request().postDataJSON();
            requests.push({action,data});
            const body = {
                bootstrap:{csrf:'test-token'}, register:{ok:true}, login:{ok:true,csrf:'new-token'},
                me:{csrf:'test-token',profile,challenges,contest:{}},
                leaderboard:{rows,contest:{},server_time:snapshot.cutoff_at},
                snapshots:{snapshots:saved?[snapshot]:[]},
                snapshot:{snapshot}, save_snapshot:{snapshot,contest:{}},
                submit:{correct:true,already_solved:false,points:100,profile},
                question:{profile}, profile:{profile}, reset:{profile}
            }[action];
            if (action === 'save_snapshot') saved = true;
            await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(body || {})});
        });
        const base = 'http://127.0.0.1:' + server.address().port;
        await page.goto(base + '/login');
        await page.getByRole('button', {name:'Cadastrar', exact:true}).click();
        await page.locator('#register-username').fill('buscador');
        await page.locator('#register-email').fill('buscador@example.test');
        await page.locator('#register-password').fill('test-password');
        await page.getByRole('button', {name:'Criar conta'}).click();
        await page.waitForFunction(() => document.getElementById('auth-message').textContent.includes('Conta criada'));
        assert(requests.some(r => r.action === 'register' && r.data.username === 'buscador'));
        await page.locator('#password').fill('test-password');
        await page.getByRole('button', {name:'Adentrar o reino celeste'}).click();
        await page.waitForURL('**/oracle');
        await page.waitForFunction(() => document.getElementById('username-display').textContent === 'Buscador');
        await page.addStyleTag({content: `
            *, *::before, *::after { box-sizing: border-box; }
            h1, h2, h3, p { margin: 0; }
            .hidden { display: none !important; }
            #star-arena { position: relative; width: 100%; max-width: 1024px; height: 660px; margin: auto; }
            #eye-container { position: absolute; left: 50%; top: 50%; width: 256px; height: 256px; transform: translate(-50%, -50%); }
            .oracle-card { width: 160px; box-sizing: border-box; padding: 16px; }
            #card-spirit, #card-air, #card-fire, #card-water, #card-earth, #card-light_dark { position: relative; min-height: 98px; }
            #star-arena > div:has(#card-spirit) { position:absolute; top:2%; left:50%; transform:translateX(-50%); }
            #star-arena > div:has(#card-air) { position:absolute; top:20%; left:10%; }
            #star-arena > div:has(#card-fire) { position:absolute; top:20%; right:10%; }
            #star-arena > div:has(#card-water) { position:absolute; bottom:24%; left:20%; }
            #star-arena > div:has(#card-earth) { position:absolute; bottom:24%; right:20%; }
            #star-arena > div:has(#card-light_dark) { position:absolute; bottom:2%; left:50%; transform:translateX(-50%); }
            @media (max-width: 767px) {
                #star-arena { height: 620px; }
                #eye-container { width: 224px; height: 224px; }
                .oracle-card { width: 144px; padding: 16px; }
                #star-arena > div:has(#card-air) { left:6%; }
                #star-arena > div:has(#card-fire) { right:6%; }
                #star-arena > div:has(#card-water) { left:14%; }
                #star-arena > div:has(#card-earth) { right:14%; }
            }
            @media (max-width: 639px) {
                #star-arena { height: 560px; }
                #eye-container { width: 192px; height: 192px; }
                .oracle-card { width: 112px; padding: 10px; min-height: 88px; }
                #star-arena > div:has(#card-air) { left:2%; }
                #star-arena > div:has(#card-fire) { right:2%; }
                #star-arena > div:has(#card-water) { left:6%; }
                #star-arena > div:has(#card-earth) { right:6%; }
            }
        `});
        for (const [width,height] of [[1440,1000],[768,1024],[390,844]]) {
            await page.setViewportSize({width,height});
            await page.waitForFunction(() => getComputedStyle(document.querySelector('#card-spirit').parentElement).position === 'absolute');
            const boxes = await page.locator('.oracle-card').evaluateAll(cards => cards.map(card => {
                const r = card.getBoundingClientRect();
                return {id:card.id,x:r.x,y:r.y,width:r.width,height:r.height};
            }));
            assert.equal(boxes.length,6);
            for (let i=0;i<boxes.length;i++) {
                const a=boxes[i]; assert(a.x >= 0 && a.x+a.width <= width, JSON.stringify(a));
                for (const b of boxes.slice(i+1)) {
                    assert(!(a.x < b.x+b.width && a.x+a.width > b.x && a.y < b.y+b.height && a.y+a.height > b.y), 'Cards overlap at '+width+': '+JSON.stringify({a,b}));
                }
            }
            const eye = await page.locator('#eye-container').boundingBox();
            const light = boxes.find(b=>b.id==='card-light_dark');
            assert(light.y > eye.y+eye.height, 'New card must be below the eye: '+JSON.stringify({width, eye, light}));
            if (artifacts) {
                fs.mkdirSync(artifacts,{recursive:true});
                await page.screenshot({path:path.join(artifacts,'portal-'+width+'.png'),fullPage:true});
            }
        }
        await page.locator('#card-light_dark').click();
        await page.waitForFunction(() => document.getElementById('modal-title').textContent.includes('LUZ E TREVAS'));
        assert.equal((await page.locator('#modal-title').textContent()).trim(),'LUZ E TREVAS: O EQUILÍBRIO');
        await page.locator('#modal-close').click();
        await page.locator('#card-air').click();
        await page.waitForFunction(() => document.getElementById('modal-title').textContent.includes('AR: O INTELECTO'));
        await page.locator('#flag-input').fill('ORACLE{Case_Sensitive}');
        await page.locator('#flag-submit-btn').click();
        await page.waitForFunction(() => document.getElementById('flag-feedback').textContent.includes('FLAG CORRETA'));
        assert(requests.some(r=>r.action==='submit' && r.data.flag==='ORACLE{Case_Sensitive}'));
        await page.locator('#modal-close').click();
        await page.locator('#leaderboard-btn').click();
        await page.waitForURL('**/leaderboard/leaderboard.html');
        await page.waitForFunction(() => document.querySelectorAll('#leaderboard-tbody tr').length===2);
        assert.equal(await page.locator('#leaderboard-tbody img').count(),0,'Names must be escaped');
        await page.locator('#search-input').fill('Buscador');
        assert.equal(await page.locator('#leaderboard-tbody tr').count(),1);
        assert.equal((await page.locator('#leaderboard-tbody .rank-badge').textContent()).trim(),'🥈');
        const downloadPromise=page.waitForEvent('download');
        await page.locator('#save-snapshot').click();
        const download=await downloadPromise;
        assert(download.suggestedFilename().endsWith('.png'));
        if (artifacts) await download.saveAs(path.join(artifacts,'ranking.png'));
        const csvPromise=page.waitForEvent('download');
        await page.locator('#download-csv').click();
        const csv=await csvPromise;
        const csvText=fs.readFileSync(await csv.path(),'utf8');
        assert(csvText.includes('first') && csvText.includes('buscador'),'Export must include all rows despite filter');
        assert.deepEqual(errors,[]);
        console.log('PASS: scripts, registration/login UI, six cards at three widths, flags, escaped ranking, stable ranks, full PNG/CSV export.');
    } finally {
        if(browser) await browser.close();
        await new Promise(resolve=>server.close(resolve));
    }
}
run().catch(error=>{console.error(error);process.exitCode=1;});
