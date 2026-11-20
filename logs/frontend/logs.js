const $ = id => document.getElementById(id);
const modal = $('account-logs-modal');
let accounts = [], view = 'flags', activeAccount = null, polling = false;
const feeds = {flags: [], warnings: [], account: []};
const labels = {flag_submit:'Submissão de flag',login:'Login',logout:'Saída',admin_denied:'Acesso administrativo negado',ban:'Banimento',admin_ban:'Banimento aplicado',account_type:'Tipo de conta',password_change:'Alteração de senha',progress_reset:'Redefinição de progresso'};
const date = value => value ? new Date(value).toLocaleString('pt-BR') : '—';
function node(tag, text, className) { const el = document.createElement(tag); el.textContent = text; if (className) el.className = className; return el; }
function renderLogs(kind) {
    const target = $(kind === 'account' ? 'account-logs-list' : kind + '-list');
    target.replaceChildren();
    if (!feeds[kind].length) {
        if (kind === 'warnings') { const row = node('tr',''); const cell = node('td','Nenhum alerta registrado.'); cell.colSpan = 5; row.append(cell); target.append(row); }
        else target.append(node('p','Nenhum registro encontrado.','empty'));
    }
    feeds[kind].forEach(log => {
        if (kind === 'warnings') {
            const row = document.createElement('tr');
            if ((log.warning || '').includes('flag-isca')) row.classList.add('honeypot-warning');
            [date(log.created_at),log.username,log.email || '—',log.ip_address || '—',log.warning].forEach(value => row.append(node('td',value)));
            target.append(row); return;
        }
        const isHoneypot = log.event_type === 'flag_submit' && (log.detail || '').startsWith('Flag honeypot enviada');
        const entry = node('article','', 'log-entry' + (isHoneypot ? ' honeypot' : log.success === null ? '' : log.success ? ' success' : ' failure'));
        const heading = node('div','','log-heading');
        heading.append(node('strong',log.username + ' · ' + (log.email || 'sem e-mail')),node('time',date(log.created_at)));
        entry.append(heading,node('p',(labels[log.event_type] || log.event_type) + (log.challenge_name ? ' · ' + log.challenge_name : '') + (isHoneypot ? ' · HONEYPOT' : log.success === null ? '' : log.success ? ' · SUCESSO' : ' · FALHA')));
        entry.append(node('p',log.detail));
        if (log.submitted_flag !== null) entry.append(node('code',log.submitted_flag || '(flag vazia)'));
        entry.append(node('p','IP: ' + (log.ip_address || '—'),'log-meta'));
        target.append(entry);
    });
}
async function loadLogs(kind, older = false) {
    const params = {kind};
    if (kind === 'account') params.user_id = activeAccount.id;
    const accountId = activeAccount?.id;
    if (older && feeds[kind].length) params.before = feeds[kind].at(-1).log_id;
    const result = await Portal.request('logs', undefined, params);
    if (kind === 'account' && activeAccount?.id !== accountId) return;
    feeds[kind] = older ? feeds[kind].concat(result.logs) : result.logs;
    renderLogs(kind);
    $(kind + '-more').hidden = !result.has_more;
    return result;
}
function renderAccounts() {
    $('log-accounts').replaceChildren();
    const search = $('account-search').value.toLocaleLowerCase();
    accounts.filter(a => [a.username,a.name,a.email].some(value => (value || '').toLocaleLowerCase().includes(search))).forEach(account => {
        const row = document.createElement('tr');
        const identity = node('td',account.username); identity.append(node('small',account.name));
        const roleName = account.role === 'admin' ? 'Administrador' : account.role === 'logs' ? 'Leitor de logs' : 'Jogador';
        const role = roleName + ' · ' + (account.is_banned ? 'BANIDO' : account.ignore_leaderboard ? 'Teste' : 'Participante');
        const times = node('td',date(account.created_at)); times.append(node('small',date(account.last_login)));
        row.append(identity,node('td',account.email || '—'),node('td',role),times,node('td',account.score + ' XP · ' + account.solves_count + ' flags'));
        const cell = document.createElement('td'), button = node('button','VER LOGS (' + account.logs_count + ')');
        button.type = 'button';
        if (account.unread > 0) button.append(node('span','+' + account.unread,'new-count'));
        button.setAttribute('aria-label','Ver logs de ' + account.username + ', ' + account.unread + ' novos registros');
        button.addEventListener('click', () => openAccount(account)); cell.append(button); row.append(cell);
        $('log-accounts').append(row);
    });
}
async function loadAccounts() { accounts = (await Portal.request('log_accounts')).accounts; renderAccounts(); }
async function openAccount(account) {
    activeAccount = account;
    $('account-logs-title').textContent = account.username;
    $('account-logs-email').textContent = account.email || 'Sem e-mail';
    $('account-logs-list').replaceChildren(node('p','Carregando registros...'));
    $('account-more').hidden = true;
    modal.showModal();
    try {
        const result = await loadLogs('account');
        if (!result || activeAccount?.id !== account.id) return;
        const latest = result.logs[0]?.log_id || 0;
        await Portal.request('read_account_logs', {user_id: account.id, last_log_id: latest});
        await loadAccounts();
    } catch (error) { $('account-logs-list').replaceChildren(node('p',error.message)); }
}
function closeModal() { modal.close(); activeAccount = null; }
$('close-account-logs').addEventListener('click',closeModal);
modal.addEventListener('close', () => { activeAccount = null; });
modal.addEventListener('click', event => { if (event.target === modal) { const rect = modal.getBoundingClientRect(); if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) closeModal(); } });
$('account-search').addEventListener('input',renderAccounts);
document.querySelectorAll('[data-view]').forEach(button => button.addEventListener('click',async () => {
    view = button.dataset.view;
    document.querySelectorAll('[data-view]').forEach(tab => tab.setAttribute('aria-pressed', String(tab === button)));
    ['flags','accounts','warnings'].forEach(kind => $(kind + '-view').hidden = kind !== view);
    try { if (view === 'accounts') await loadAccounts(); else await loadLogs(view); $('logs-status').textContent = 'Atualizado às ' + new Date().toLocaleTimeString('pt-BR'); }
    catch (error) { $('logs-status').textContent = error.message; }
}));
['flags','warnings','account'].forEach(kind => $(kind + '-more').addEventListener('click',async event => {
    event.target.disabled = true;
    try { await loadLogs(kind,true); } catch (error) { $('logs-status').textContent = error.message; }
    finally { event.target.disabled = false; }
}));
async function refresh() {
    if (polling || document.hidden) return;
    polling = true;
    try {
        if (view === 'accounts') await loadAccounts();
        // Keep a paginated history steady; refresh the newest page automatically.
        else if (feeds[view].length <= 100) await loadLogs(view);
        $('logs-status').textContent = 'Atualizado às ' + new Date().toLocaleTimeString('pt-BR');
    } catch (error) { $('logs-status').textContent = error.message; }
    finally { polling = false; }
}
refresh();
setInterval(refresh,5000);
