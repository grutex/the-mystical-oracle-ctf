const assert = require('node:assert/strict');
const { runAccountQueue } = require('./scheduler');

(async () => {
    let active = 0;
    let peak = 0;
    const accounts = new Set();
    const visited = [];
    const jobs = Array.from({ length: 120 }, (_, account) => ['surface', 'depth'].map(stage => ({
        account, run: async () => {
            assert(!accounts.has(account), 'Two revisors accessed the same account');
            accounts.add(account);
            peak = Math.max(peak, ++active);
            await new Promise(resolve => setTimeout(resolve, 2));
            visited.push(`${account}:${stage}`);
            active--;
            accounts.delete(account);
        }
    }))).flat();
    await runAccountQueue(jobs, 4);
    assert.equal(peak, 4);
    assert.equal(new Set(visited).size, 240);
    for (let account = 0; account < 120; account++) {
        assert(visited.indexOf(`${account}:surface`) < visited.indexOf(`${account}:depth`));
    }
    await assert.rejects(runAccountQueue([], 0));
    await assert.rejects(runAccountQueue([], 9));
    let serialActive = 0;
    await runAccountQueue(jobs.slice(0, 4).map(job => ({ account: job.account, run: async () => {
        assert.equal(++serialActive, 1);
        await Promise.resolve();
        serialActive--;
    } })), 1);
    console.log('OK: 120 accounts, bounded concurrency, serial per-account order and serial fallback.');
})().catch(error => { console.error(error); process.exitCode = 1; });
