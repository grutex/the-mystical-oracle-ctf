const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const { chromium } = require('playwright');

async function run() {
    const root = path.resolve(__dirname, '../frontend');
    const keys = ['spirit', 'air', 'fire', 'water', 'earth', 'light_dark'];
    let profile = {id: 999, username: 'Buscador', role: 'player', questionsCount: 0, history: [],
        solvedFlags: [], acceptedFlags: {}, score: 0, allFlagsSolved: false, hasCrown: false, crownedAt: null, oracleFinaleSeen: false};
    let finaleRequests = 0;
    let prepareRequests = 0;
    const server = http.createServer((request, response) => {
        const url = new URL(request.url, 'http://localhost');
        if (url.pathname === '/api/api.php') {
            const action = url.searchParams.get('action');
            let result;
            if (action === 'bootstrap') result = {csrf: 'test-csrf'};
            else if (action === 'whispers') result = {whispers: {messages: [], latestId: 0, seenId: 0, unreadCount: 0}};
            else if (action === 'me') result = {profile, challenges: keys.map(key => ({key, points: 100, available: true})), contest: {}};
            else if (action === 'oracle_finale') {
                finaleRequests++;
                profile = {...profile, hasCrown: true, crownedAt: profile.crownedAt || new Date().toISOString(), oracleFinaleSeen: true};
                result = {profile};
            } else if (action === 'prepare_oracle_finale') {
                prepareRequests++;
                profile = {...profile, oracleFinaleSeen: false};
                result = {profile};
            } else if (action === 'reset') {
                profile = {...profile, solvedFlags: [], score: 0, allFlagsSolved: false, oracleFinaleSeen: false};
                result = {profile};
            } else result = {profile};
            response.writeHead(200, {'Content-Type': 'application/json'}).end(JSON.stringify(result));
            return;
        }
        const filename = path.resolve(root, '.' + (url.pathname === '/oracle' ? '/landing/oracle-landing.html' : url.pathname));
        if (!filename.startsWith(root + path.sep) || !fs.existsSync(filename) || !fs.statSync(filename).isFile()) {
            response.writeHead(404).end(); return;
        }
        response.writeHead(200, {'Content-Type': ({'.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css'})[path.extname(filename)] || 'text/plain'});
        response.end(fs.readFileSync(filename));
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    let browser;
    try {
        browser = await chromium.launch({headless: true, executablePath: process.env.PLAYWRIGHT_EXECUTABLE_PATH || undefined});
        const page = await browser.newPage({viewport: {width: 1440, height: 1000}});
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        if (!process.env.FINALE_VISUAL) {
            await page.route('https://cdn.tailwindcss.com/**', route => route.fulfill({contentType: 'text/javascript', body: 'window.tailwind={config:{}};'}));
            await page.route('https://fonts.googleapis.com/**', route => route.fulfill({contentType: 'text/css', body: ''}));
            await page.route('https://fonts.gstatic.com/**', route => route.fulfill({status: 204, body: ''}));
        }
        const url = 'http://127.0.0.1:' + server.address().port + '/oracle';
        await page.goto(url);
        await page.waitForFunction(() => document.getElementById('username-display').textContent === 'Buscador');
        assert.equal(await page.locator('#completion-crown').isVisible(), false);
        assert.equal(await page.locator('#oracle-replay-button').isVisible(), false);
        assert.equal(await page.locator('#pupil').getAttribute('role'), null);
        profile = {...profile, solvedFlags: keys, allFlagsSolved: true, score: 1350};
        await page.reload();
        await page.waitForFunction(() => document.getElementById('pupil').classList.contains('oracle-complete'));
        assert.equal(await page.locator('#completion-crown').isVisible(), false);
        assert.equal(await page.locator('#oracle-replay-button').isVisible(), false);
        assert.equal(await page.locator('#pupil').evaluate(node => getComputedStyle(node).fill), 'rgb(255, 255, 255)');
        if (process.env.PORTAL_TEST_ARTIFACTS) await page.screenshot({path: path.join(process.env.PORTAL_TEST_ARTIFACTS, 'oracle-complete.png'), fullPage: true});
        await page.locator('#pupil').focus();
        await page.keyboard.press('Enter');
        await page.waitForFunction(() => document.querySelector('.oracle-finale-message')?.textContent === 'bene factum');
        const white = await page.locator('.oracle-finale-wash').evaluate(node => ({color: getComputedStyle(node).backgroundColor, opacity: getComputedStyle(node).opacity}));
        assert.deepEqual(white, {color: 'rgb(255, 255, 255)', opacity: '1'});
        assert.equal(await page.locator('body').evaluate(node => node.classList.contains('oracle-finished')), false);
        await page.waitForFunction(() => document.querySelector('.oracle-finale-message')?.getAnimations().some(animation => animation.playState === 'finished'));
        if (process.env.PORTAL_TEST_ARTIFACTS) await page.screenshot({path: path.join(process.env.PORTAL_TEST_ARTIFACTS, 'oracle-bene-factum.png')});
        await page.waitForFunction(() => document.querySelector('.oracle-finale-message')?.textContent === 'Abyss te resalutat');
        assert.equal(await page.locator('.oracle-finale-message').evaluate(node => getComputedStyle(node).color), 'rgb(123, 44, 191)');
        await page.waitForSelector('.oracle-finale', {state: 'detached'});
        assert.equal(finaleRequests, 1);
        assert(await page.locator('#completion-crown').isVisible());
        assert(await page.locator('#oracle-replay-button').isVisible());
        const replayPosition = await page.locator('#oracle-replay-button').evaluate(node => ({top: getComputedStyle(node).top, left: getComputedStyle(node).left, position: getComputedStyle(node).position}));
        assert.deepEqual(replayPosition, {top: '80px', left: '12px', position: 'fixed'});
        assert.equal(await page.locator('main').evaluate(node => getComputedStyle(node).filter), 'grayscale(1)');
        assert.equal(await page.locator('.oracle-welcome-copy').evaluate(node => getComputedStyle(node).filter), 'grayscale(1)');
        const crownStyle = await page.locator('#completion-crown').evaluate(node => {
            const filters = [];
            for (let ancestor = node; ancestor; ancestor = ancestor.parentElement) filters.push(getComputedStyle(ancestor).filter);
            return {filters, shadow: getComputedStyle(node).textShadow, aura: getComputedStyle(node, '::before').backgroundImage};
        });
        assert(crownStyle.filters.every(filter => filter === 'none'), 'The crown must be outside all grayscale filters');
        assert.match(crownStyle.shadow, /255, 215, 0/);
        assert.match(crownStyle.aura, /radial-gradient/);
        if (process.env.PORTAL_TEST_ARTIFACTS) await page.screenshot({path: path.join(process.env.PORTAL_TEST_ARTIFACTS, 'oracle-gray.png'), fullPage: true});
        await page.reload();
        await page.waitForFunction(() => document.body.classList.contains('oracle-finished'));
        assert(await page.locator('#completion-crown').isVisible());
        await page.emulateMedia({reducedMotion: 'reduce'});
        const crownedAt = profile.crownedAt;
        await page.locator('#oracle-replay-button').click();
        await page.waitForFunction(() => document.getElementById('pupil').classList.contains('oracle-complete'));
        assert.equal(prepareRequests, 1);
        assert.equal(finaleRequests, 1, 'Rever final must not start the animation automatically');
        assert.equal(await page.locator('.oracle-finale').count(), 0);
        assert.equal(await page.locator('main').evaluate(node => getComputedStyle(node).filter), 'none');
        assert(await page.locator('#completion-crown').isVisible());
        assert.equal(profile.crownedAt, crownedAt);
        if (process.env.PORTAL_TEST_ARTIFACTS) await page.screenshot({path: path.join(process.env.PORTAL_TEST_ARTIFACTS, 'oracle-prepared.png'), fullPage: true});
        assert.equal(await page.locator('body').evaluate(node => node.classList.contains('oracle-finished')), false);
        const eyeBounds = await page.locator('#mystic-eye-svg').boundingBox();
        await page.locator('#mystic-eye-svg').click({position: {x: eyeBounds.width * .3, y: eyeBounds.height * .5}});
        await page.waitForFunction(() => document.querySelector('.oracle-finale-message')?.textContent === 'bene factum');
        assert(await page.locator('#oracle-modal').evaluate(node => node.classList.contains('pointer-events-none')),
            'The completed eye must replay the finale instead of opening an ordinary consultation');
        assert.equal(await page.locator('.oracle-finale-message').evaluate(node => getComputedStyle(node).color), 'rgb(123, 44, 191)');
        assert(await page.locator('#oracle-replay-button').isDisabled());
        await page.waitForSelector('.oracle-finale', {state: 'detached'});
        assert.equal(finaleRequests, 2);
        assert.equal(profile.crownedAt, crownedAt);
        assert.equal(await page.locator('main').evaluate(node => getComputedStyle(node).filter), 'grayscale(1)');
        await page.locator('#oracle-replay-button').click();
        await page.waitForFunction(() => document.getElementById('pupil').classList.contains('oracle-complete'));
        await page.locator('#pupil').click();
        await page.waitForFunction(() => document.querySelector('.oracle-finale-message')?.textContent === 'bene factum');
        await page.waitForSelector('.oracle-finale', {state: 'detached'});
        assert.equal(finaleRequests, 3, 'The finale must work on consecutive replays without reloading');
        assert.equal(profile.crownedAt, crownedAt);
        if (process.env.FINALE_VISUAL) {
            await page.locator('#profile-btn').click();
            await page.locator('#reset-profile-btn').click();
            await page.locator('#reset-confirm-yes').click();
            await page.waitForFunction(() => !document.body.classList.contains('oracle-finished'));
            await page.locator('#profile-modal-close').click();
        } else {
            await page.evaluate(async () => {
                const result = await Portal.request('reset', {});
                document.dispatchEvent(new CustomEvent('oracle:profile', {detail: result.profile}));
            });
        }
        assert.equal(await page.locator('body').evaluate(node => getComputedStyle(node).filter), 'none');
        assert.equal(await page.locator('main').evaluate(node => getComputedStyle(node).filter), 'none');
        assert(await page.locator('#completion-crown').isVisible());
        assert.equal(await page.locator('#oracle-replay-button').isVisible(), false);
        await page.reload();
        await page.waitForFunction(() => document.getElementById('username-display').textContent === 'Buscador');
        assert(await page.locator('#completion-crown').isVisible());
        assert.equal(await page.locator('body').evaluate(node => node.classList.contains('oracle-finished')), false);
        // Verify the complete scene still works with reduced motion on a small screen.
        profile = {...profile, solvedFlags: keys, allFlagsSolved: true};
        await page.setViewportSize({width: 390, height: 844});
        await page.emulateMedia({reducedMotion: 'reduce'});
        await page.reload();
        await page.waitForFunction(() => document.getElementById('pupil').classList.contains('oracle-complete'));
        await page.locator('#pupil').dispatchEvent('click');
        await page.waitForFunction(() => document.querySelector('.oracle-finale-message')?.textContent === 'bene factum');
        await page.waitForSelector('.oracle-finale', {state: 'detached'});
        assert.equal(finaleRequests, 4);
        assert.deepEqual(errors, []);
        console.log('OK: crown on eye activation, replay restores colors and white pupil, persistent crown date, reset, mobile and reduced motion.');
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
}
run().catch(error => {console.error(error); process.exitCode = 1;});
