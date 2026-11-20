// One worker group per account: revisors never race over the same player's data.
async function runAccountQueue(jobs, concurrency) {
    if (!Number.isInteger(concurrency) || concurrency < 1 || concurrency > 8) {
        throw new Error('GUARDIA_CONCURRENCY deve ser um inteiro entre 1 e 8.');
    }
    const groups = new Map();
    for (const job of jobs) {
        if (!groups.has(job.account)) groups.set(job.account, []);
        groups.get(job.account).push(job);
    }
    const pending = [...groups.values()];
    let cursor = 0;
    await Promise.all(Array.from({ length: Math.min(concurrency, pending.length) }, async () => {
        while (cursor < pending.length) {
            const group = pending[cursor++];
            for (const job of group) await job.run();
        }
    }));
}
module.exports = { runAccountQueue };
