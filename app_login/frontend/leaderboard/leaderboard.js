const statusLine = document.getElementById('snapshot-status');
const snapshotSelect = document.getElementById('snapshot-select');
const boardSelect = document.getElementById('board-select');
const boardLabel = document.getElementById('board-label');
const snapshotControls = document.getElementById('snapshot-controls');
let boardParams = {};
let isOrganizer = false;

boardSelect.addEventListener('change', () => {
    const url = new URL(location.href);
    if (boardSelect.value === 'testers') url.searchParams.set('board', 'testers');
    else url.searchParams.delete('board');
    location.assign(url.href);
});
let busy = false;
let refreshTimer;
let contestTimer;
const dateLabel = value => value ? new Date(value).toLocaleString('pt-BR') : 'Sem registros';
function elapsedLabel(startedAt) {
    const seconds = Math.max(0, Math.floor((latestContest.elapsed_seconds || 0)
        + (latestContest.paused_at || latestContest.closed_at ? 0 : (Date.now() - contestReceivedAt) / 1000)));
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const remainingSeconds = seconds % 60;
    return (days ? days + 'd ' : '') + String(hours).padStart(2, '0') + ':'
        + String(minutes).padStart(2, '0') + ':' + String(remainingSeconds).padStart(2, '0');
}
function updateContestStatus() {
    document.getElementById('contest-status').textContent = latestContest.closed_at
        ? 'CTF encerrado em ' + dateLabel(latestContest.closed_at) + ' · Classificação final preservada.'
        : latestContest.started_at ? (latestContest.paused_at ? 'CTF pausado · ' : 'Jogo aberto há ') + elapsedLabel(latestContest.started_at) + '.'
            + (latestContest.ends_at ? ' · Encerramento: ' + dateLabel(latestContest.ends_at) + ' (seu horário local).' : '')
        : 'Jogo ainda não começou.';
}
function showContest(contest) {
    latestContest = contest;
    contestReceivedAt = Date.now();
    clearInterval(contestTimer);
    updateContestStatus();
    if (contest.started_at && !contest.closed_at) contestTimer = setInterval(updateContestStatus, 1000);
    document.getElementById('start-contest').disabled = !!contest.started_at || !!contest.closed_at;
    document.getElementById('pause-contest').disabled = !contest.started_at || !!contest.closed_at;
    document.getElementById('pause-contest').textContent = contest.paused_at ? 'Retomar CTF' : 'Pausar CTF';
    document.getElementById('close-contest').disabled = !!contest.closed_at;
    document.querySelector('#schedule-form button').disabled = !!contest.closed_at;
}
function showRows(rows) {
    leaderboardData = rows.map(row => ({ ...row, isCurrentUser: row.id === currentUserId }));
    renderLeaderboard();
}
async function loadSnapshots() {
    const selected = snapshotSelect.value;
    const result = await Portal.request('snapshots');
    snapshotSelect.replaceChildren(new Option('Classificação atual', ''));
    result.snapshots.forEach(snapshot => {
        snapshotSelect.add(new Option(
            '#' + snapshot.id + ' · ' + (snapshot.final ? 'FINAL · ' : '') + dateLabel(snapshot.cutoff_at),
            String(snapshot.id)
        ));
    });
    snapshotSelect.value = selected;
}
function selectSnapshot(snapshot) {
    activeSnapshot = snapshot;
    showRows(snapshot.rows);
    document.getElementById('download-png').disabled = false;
    document.getElementById('download-csv').disabled = false;
    statusLine.textContent = 'Registro #' + snapshot.id + ' · Corte: ' + dateLabel(snapshot.cutoff_at)
        + ' · Salvo: ' + dateLabel(snapshot.captured_at);
}
async function refreshLeaderboard() {
    if (busy) return;
    busy = true;
    try {
        const result = await Portal.request('leaderboard', undefined, boardParams);
        const testBoard = result.board === 'testers';
        boardSelect.value = testBoard ? 'testers' : 'players';
        boardLabel.textContent = testBoard ? 'Classificação de testadores' : 'Classificação de jogadores';
        boardLabel.hidden = isOrganizer;
        snapshotControls.hidden = testBoard;
        if (testBoard) snapshotSelect.value = '';
        document.getElementById('save-snapshot').hidden = testBoard;
        document.getElementById('close-contest').hidden = testBoard;
        showContest(result.contest);
        lastServerTime = result.server_time;
        if (!snapshotSelect.value) {
            showRows(result.rows);
            if (result.snapshot_id) {
                const saved = await Portal.request('snapshot', undefined, { id: result.snapshot_id });
                selectSnapshot(saved.snapshot);
            } else {
                activeSnapshot = null;
                document.getElementById('download-png').disabled = true;
                document.getElementById('download-csv').disabled = true;
                statusLine.textContent = 'Atualizado em ' + dateLabel(lastServerTime);
            }
        }
        if (!testBoard) await loadSnapshots();
    } catch (error) { statusLine.textContent = error.message; }
    finally { busy = false; }
}
snapshotSelect.addEventListener('change', async () => {
    try {
        if (!snapshotSelect.value) return await refreshLeaderboard();
        const result = await Portal.request('snapshot', undefined, { id: snapshotSelect.value });
        selectSnapshot(result.snapshot);
    } catch (error) { statusLine.textContent = error.message; }
});
async function capture(final) {
    const buttons = [document.getElementById('save-snapshot'), document.getElementById('close-contest')];
    buttons.forEach(button => button.disabled = true);
    try {
        const result = await Portal.request(final ? 'close' : 'save_snapshot', {});
        showContest(result.contest);
        await loadSnapshots();
        snapshotSelect.value = String(result.snapshot.id);
        selectSnapshot(result.snapshot);
        await downloadPng();
    } catch (error) { statusLine.textContent = error.message; }
    finally {
        buttons[0].disabled = false;
        buttons[1].disabled = !!latestContest.closed_at;
    }
}
document.getElementById('save-snapshot').addEventListener('click', () => capture(false));
document.getElementById('start-contest').addEventListener('click', async () => {
    const button = document.getElementById('start-contest');
    button.disabled = true;
    try {
        const result = await Portal.request('start', {});
        showContest(result.contest);
    } catch (error) { statusLine.textContent = error.message; }
});
let contestReceivedAt = Date.now();
document.getElementById('reset-contest').addEventListener('click', async () => {
    if (!window.confirm('Resetar o CTF? As flags resolvidas e os pontos de todos serão zerados. O timer e o horário de encerramento serão limpos. Contas e histórico de classificação serão preservados.')) return;
    const button = document.getElementById('reset-contest');
    button.disabled = true;
    try {
        const result = await Portal.request('reset_contest', {});
        snapshotSelect.value = '';
        activeSnapshot = null;
        showContest(result.contest);
        document.getElementById('ends-at').value = '';
        await refreshLeaderboard();
        statusLine.textContent = 'CTF resetado. Pressione Iniciar CTF para abrir a nova rodada.';
    } catch (error) { statusLine.textContent = error.message; }
    finally { button.disabled = false; }
});
document.getElementById('pause-contest').addEventListener('click', async () => {
    const button = document.getElementById('pause-contest');
    button.disabled = true;
    try {
        const result = await Portal.request(latestContest.paused_at ? 'resume' : 'pause', {});
        showContest(result.contest);
    } catch (error) { statusLine.textContent = error.message; }
    finally { button.disabled = !latestContest.started_at || !!latestContest.closed_at; }
});
document.getElementById('close-contest').addEventListener('click', () => {
    if (window.confirm('Encerrar o CTF agora? Novas flags serão bloqueadas e a classificação final ficará salva.')) capture(true);
});
document.getElementById('schedule-form').addEventListener('submit', async event => {
    event.preventDefault();
    const input = document.getElementById('ends-at');
    const date = new Date(input.value);
    if (Number.isNaN(date.getTime())) return;
    try {
        const result = await Portal.request('schedule', { ends_at: date.toISOString().replace('.000Z', 'Z') });
        showContest(result.contest);
        statusLine.textContent = 'Horário de encerramento salvo.';
    } catch (error) { statusLine.textContent = error.message; }
});
function downloadBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url; link.download = filename;
    document.body.appendChild(link); link.click(); link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 30000);
}
function csvCell(value) {
    let text = String(value ?? '');
    if (/^[=+@\-\t\r\n]/.test(text)) text = "'" + text;
    return '"' + text.replaceAll('"', '""') + '"';
}
document.getElementById('download-csv').addEventListener('click', () => {
    if (!activeSnapshot) return;
    const rows = [
        ['Registro', activeSnapshot.id, 'Corte UTC', activeSnapshot.cutoff_at],
        ['Colocação', 'Buscador', 'Usuário', 'E-mail', 'Pontuação', 'Flags', 'Última flag UTC'],
        ...activeSnapshot.rows.map(row => [row.rank, row.name, row.username, row.email, row.score, row.solves, row.lastFlagDate])
    ];
    downloadBlob(new Blob(['\uFEFF' + rows.map(row => row.map(csvCell).join(';')).join('\r\n')], {type: 'text/csv;charset=utf-8'}),
        'oracle-leaderboard-' + activeSnapshot.id + '.csv');
});
// Export the COMPLETE persisted ranking, regardless of search or display ordering.
// Paginate large events to avoid browser canvas dimension limits.
async function downloadPng() {
    if (!activeSnapshot) return;
    const snapshot = activeSnapshot;
    const pageSize = 100;
    const pages = Math.max(1, Math.ceil(snapshot.rows.length / pageSize));
    for (let page = 0; page < pages; page++) {
        const rows = snapshot.rows.slice(page * pageSize, (page + 1) * pageSize);
        const canvas = document.createElement('canvas');
        canvas.width = 1400; canvas.height = 250 + rows.length * 54;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#050508'; ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#ffd700'; ctx.font = 'bold 32px Georgia';
        ctx.fillText('TABULA GLORIAE · ' + (snapshot.final ? 'FINAL' : 'REGISTRO #' + snapshot.id), 40, 55);
        ctx.font = '18px sans-serif'; ctx.fillStyle = '#c4b5fd';
        ctx.fillText('Corte UTC: ' + snapshot.cutoff_at + ' · Salvo UTC: ' + snapshot.captured_at, 40, 92);
        ctx.fillText('Página ' + (page + 1) + '/' + pages + ' · ' + snapshot.ranking_rule, 40, 125);
        ctx.fillStyle = '#00f3ff'; ctx.font = 'bold 18px sans-serif';
        ctx.fillText('POS.', 40, 174); ctx.fillText('BUSCADOR', 140, 174);
        ctx.fillText('PONTOS', 790, 174); ctx.fillText('FLAGS', 920, 174); ctx.fillText('ÚLTIMA FLAG (UTC)', 1040, 174);
        rows.forEach((row, index) => {
            const y = 205 + index * 54;
            ctx.fillStyle = index % 2 ? '#0b0814' : '#170f28';
            ctx.fillRect(30, y - 20, 1340, 50);
            ctx.fillStyle = '#ffd700'; ctx.font = 'bold 20px sans-serif';
            ctx.fillText(String(row.rank), 40, y + 10);
            ctx.fillStyle = '#e9d5ff'; ctx.font = '19px sans-serif';
            let name = row.name;
            while (ctx.measureText(name).width > 610) name = name.slice(0, -2) + '…';
            ctx.fillText(name, 140, y); ctx.fillText(String(row.score), 790, y + 10);
            ctx.font = '13px sans-serif';
            ctx.fillText([row.username, row.email].filter(Boolean).join(' · '), 140, y + 20, 610);
            ctx.font = '19px sans-serif';
            ctx.fillText(String(row.solves), 920, y + 10);
            ctx.font = '15px monospace';
            ctx.fillText(row.lastFlagDate ? row.lastFlagDate.slice(0, 19).replace('T', ' ') : 'Sem registros', 1040, y + 10);
        });
        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
        if (!blob) throw new Error('Não foi possível gerar o print. O registro continua salvo no histórico.');
        downloadBlob(blob, 'oracle-leaderboard-' + snapshot.id + '-' + (page + 1) + '.png');
    }
}
document.getElementById('download-png').addEventListener('click', () => downloadPng().catch(error => statusLine.textContent = error.message));
Portal.request('me').then(async result => {
    currentUserId = result.profile.id;
    localUsername = result.profile.username;
    localScore = result.profile.score;
    document.getElementById('current-user-display').textContent = localUsername;
    document.getElementById('current-score-badge').textContent = localScore + ' pts';
    isOrganizer = result.profile.role === 'admin';
    if (isOrganizer && new URLSearchParams(location.search).get('board') === 'testers') {
        boardParams = { board: 'testers' };
    }
    document.getElementById('organizer-controls').hidden = !isOrganizer;
    document.getElementById('board-controls').hidden = !isOrganizer;
    await refreshLeaderboard();
    refreshTimer = setInterval(refreshLeaderboard, 10000);
}).catch(error => statusLine.textContent = error.message);
window.addEventListener('pagehide', () => { clearInterval(refreshTimer); clearInterval(contestTimer); });
