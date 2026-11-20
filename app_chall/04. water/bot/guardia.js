/** Guardiã da superfície (Stored XSS) e Arquivista das profundezas (Reflected XSS). */
const puppeteer = require('puppeteer');
const crypto = require('node:crypto');
const { runAccountQueue } = require('./scheduler');
const WEB = (process.env.WATER_WEB_URL || 'http://water-web').replace(/\/+$/, '');
const DEPTH = (process.env.WATER_DEPTH_URL || 'http://water-depth-web').replace(/\/+$/, '');
const TOKEN = process.env.WATER_BOT_TOKEN || '';
const INTERVAL = parseInt(process.env.GUARDIA_INTERVAL_MS || '4000', 10);
const CONCURRENCY = Number(process.env.GUARDIA_CONCURRENCY || '2');
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const authorization = scope => crypto.createHmac('sha256', TOKEN).update(scope).digest('hex');

async function queue(origin, scope, field) {
    const response = await fetch(`${origin}/pendentes.php`, {
        headers: { 'X-Oracle-Bot': authorization(scope) }, cache: 'no-store',
        signal: AbortSignal.timeout(15000),
    });
    if (!response.ok) throw new Error(`Fila ${scope}: HTTP ${response.status}`);
    const data = await response.json();
    return Array.isArray(data[field]) ? data[field] : [];
}

async function visit(browser, origin, url, label) {
    // Cada visita tem cookies e armazenamento próprios, sem sessão de jogador.
    const context = await browser.createBrowserContext();
    try {
        const page = await context.newPage();
        page.setDefaultNavigationTimeout(15000);
        page.setDefaultTimeout(5000);
        page.on('dialog', dialog => dialog.dismiss().catch(() => {}));
        await page.setRequestInterception(true);
        page.on('request', request => {
            // Os coletores compartilham o volume; cada revisora fica em sua origem.
            let permitted = false;
            try { permitted = new URL(request.url()).origin === origin; } catch {}
            (permitted ? request.continue() : request.abort()).catch(() => {});
        });
        await page.goto(url, { waitUntil: 'networkidle2' });
        for (const button of await page.$$('button')) {
            try { await button.click({ delay: 15 }); } catch {}
        }
        await sleep(1500);
        console.log(`[guardia] ${label} revisado`);
    } catch (error) {
        console.error(`[guardia] ${label}: ${error.message}`);
    } finally { await context.close().catch(() => {}); }
}

async function main() {
    if (TOKEN.length < 16) throw new Error('WATER_BOT_TOKEN ausente ou muito curto.');
    await runAccountQueue([], CONCURRENCY);
    const browser = await puppeteer.launch({ headless: 'new', args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'] });
    for (const signal of ['SIGINT', 'SIGTERM']) {
        process.on(signal, async () => { await browser.close().catch(() => {}); process.exit(0); });
    }
    console.log('[guardia] superfície e profundezas prontas');
    for (;;) {
        const jobs = [];
        try {
            for (const acct of await queue(WEB, 'queue:surface', 'contas')) {
                const token = authorization(`review:surface:${acct}`);
                jobs.push({ account: Number(acct), run: () => visit(browser, WEB,
                    `${WEB}/revisar.php?acct=${acct}&token=${token}`, `conta #${acct}`) });
            }
        } catch (error) { console.error('[guardia] superfície:', error.message); }
        try {
            for (const request of await queue(DEPTH, 'queue:depth', 'solicitacoes')) {
                const token = authorization(`review:depth:${request.id}`);
                const query = new URLSearchParams({ id: request.id, q: request.termo, token });
                jobs.push({ account: Number(request.acct), run: () => visit(browser, DEPTH,
                    `${DEPTH}/consulta.php?${query}`, `consulta #${request.id}`) });
            }
        } catch (error) { console.error('[guardia] profundezas:', error.message); }
        await runAccountQueue(jobs, CONCURRENCY);
        await sleep(INTERVAL);
    }
}
main().catch(error => { console.error('[guardia]', error.message); process.exit(1); });
