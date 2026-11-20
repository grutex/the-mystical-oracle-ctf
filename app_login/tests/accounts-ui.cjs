const puppeteer = require('puppeteer');
const http = require('node:http');
const assert = require('node:assert/strict');
const accounts = [{id:101,username:'player',name:'<img src=x onerror=alert(1)>',email:'player@example.test',role:'player',ignore_leaderboard:false}];
const serve = url => new Promise((resolve,reject) => {
    const request = http.get({hostname:'portal-web',path:url.pathname,headers:{Host:'localhost'}}, response => {
        const chunks = [];
        response.on('data', chunk => chunks.push(chunk));
        response.on('end', () => resolve({status:response.statusCode,headers:response.headers,body:Buffer.concat(chunks)}));
    });
    request.on('error',reject);
});
async function run() {
    const browser = await puppeteer.launch({headless:true,args:['--no-sandbox']});
    try {
        const page = await browser.newPage();
        const updates = [], errors = [];
        let role = 'admin';
        page.on('pageerror',error => errors.push(error.message));
        await page.setRequestInterception(true);
        page.on('request',async request => {
            try {
                const url = new URL(request.url());
                if (url.hostname === 'cdn.tailwindcss.com') return await request.respond({status:200,contentType:'application/javascript',body:'window.tailwind={config:{}};'});
                if (url.hostname !== 'localhost') return await request.abort();
                if (url.pathname === '/oracle-runtime.js') return await request.respond({status:200,contentType:'application/javascript',body:'window.ORACLE_CONFIG={portalHost:"localhost",challengeHost:"localhost"};'});
                if (url.pathname === '/accounts') return await request.respond({status:200,contentType:'text/html',body:Buffer.from(process.env.ACCOUNTS_HTML_BASE64,'base64')});
                if (url.pathname === '/api/api.php') {
                    const action = url.searchParams.get('action');
                    let result = {csrf:'test'};
                    if (action === 'accounts') result = {accounts};
                    if (action === 'update_accounts') {
                        const changes = JSON.parse(request.postData()).accounts;
                        updates.push(changes);
                        changes.forEach(change => { accounts.find(account => account.id === change.id).ignore_leaderboard = change.ignore_leaderboard; });
                        result = {ok:true,accounts};
                    }
                    if (action === 'me') result = {profile:{id:99,username:'Tester',role,questionsCount:0,score:0,solvedFlags:[],history:[]},challenges:[],contest:{closed_at:null},csrf:'test'};
                    return await request.respond({status:200,contentType:'application/json',body:JSON.stringify(result)});
                }
                await request.respond(await serve(url));
            } catch (error) { console.error(error.message); await request.abort().catch(() => {}); }
        });
        await page.goto('http://localhost:8080/accounts',{waitUntil:'networkidle0'});
        assert.equal(await page.$$eval('#accounts-list img',elements => elements.length),0);
        for (const value of [true,false]) {
            const count = updates.length;
            await page.click('#accounts-list input');
            assert.equal(updates.length,count);
            await page.click('#confirm-accounts');
            await page.waitForFunction(() => document.querySelector('#accounts-status').textContent.includes('confirmadas'));
            assert.deepEqual(updates[count],[{id:101,ignore_leaderboard:value}]);
        }
        for (role of ['admin','player']) {
            await page.goto('http://localhost:8080/oracle',{waitUntil:'networkidle0'});
            assert.equal(await page.$eval('#admin-accounts-link',link => getComputedStyle(link).display !== 'none' && !link.hidden),role === 'admin');
        }
        assert.deepEqual(errors,[]);
        console.log('Checkboxes só enviam após confirmar; conversão reversível, texto seguro e link exclusivo de admin validados.');
    } finally { await browser.close(); }
}
run().catch(error => { console.error(error); process.exit(1); });
