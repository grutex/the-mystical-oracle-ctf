const accountsList = document.getElementById('accounts-list');
const accountsStatus = document.getElementById('accounts-status');
const confirmAccounts = document.getElementById('confirm-accounts');
let accountDefaults = new Map();
let pendingChanges = new Map();
let savingAccounts = false;

function renderAccounts(accounts) {
    accountDefaults = new Map(accounts.map(account => [account.id, account.ignore_leaderboard]));
    pendingChanges.clear();
    accountsList.replaceChildren();
    accounts.forEach(account => {
        const row = document.createElement('tr');
        const roleLabel = account.role === 'admin' ? 'Administrador' : account.role === 'logs' ? 'Leitor de logs' : 'Jogador';
        [account.username, account.name, account.email || '—', roleLabel].forEach(value => {
            const cell = document.createElement('td');
            cell.textContent = value;
            row.appendChild(cell);
        });
        const cell = document.createElement('td');
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.checked = account.ignore_leaderboard;
        checkbox.setAttribute('aria-label', 'Transformar conta ' + account.username + ' em teste');
        checkbox.addEventListener('change', () => {
            if (checkbox.checked === accountDefaults.get(account.id)) pendingChanges.delete(account.id);
            else pendingChanges.set(account.id, checkbox.checked);
            confirmAccounts.disabled = savingAccounts || !pendingChanges.size;
            accountsStatus.textContent = pendingChanges.size ? pendingChanges.size + ' alteração(ões) aguardando confirmação.' : 'Nenhuma alteração pendente.';
        });
        cell.appendChild(checkbox);
        row.appendChild(cell);
        const access = document.createElement('td');
        const ban = document.createElement('button');
        ban.type = 'button';
        ban.textContent = account.is_banned ? 'BANIDO' : 'BANIR JOGADOR';
        ban.disabled = account.is_banned || account.role === 'admin';
        ban.addEventListener('click', async () => {
            if (!confirm('Banir ' + account.username + '? Todas as sessões serão encerradas e a conta não poderá entrar novamente.')) return;
            if (savingAccounts) return;
            savingAccounts = true;
            ban.disabled = true;
            try {
                await Portal.request('ban_account', {user_id: account.id});
                account.is_banned = true;
                ban.textContent = 'BANIDO';
                accountsStatus.textContent = 'Conta banida. Acesso e sessões revogados.';
            } catch (error) { accountsStatus.textContent = error.message; ban.disabled = false; }
            finally { savingAccounts = false; }
        });
        access.appendChild(ban);
        row.appendChild(access);
        accountsList.appendChild(row);
    });
    confirmAccounts.disabled = true;
}

document.getElementById('accounts-form').addEventListener('submit', async event => {
    event.preventDefault();
    if (savingAccounts || !pendingChanges.size) return;
    savingAccounts = true;
    confirmAccounts.disabled = true;
    accountsList.querySelectorAll('input').forEach(input => { input.disabled = true; });
    accountsStatus.textContent = 'Salvando alterações...';
    try {
        const result = await Portal.request('update_accounts', {
            accounts: Array.from(pendingChanges, ([id, ignore_leaderboard]) => ({id, ignore_leaderboard}))
        });
        renderAccounts(result.accounts);
        accountsStatus.textContent = 'Alterações confirmadas.';
    } catch (error) { accountsStatus.textContent = error.message; }
    finally {
        savingAccounts = false;
        accountsList.querySelectorAll('input').forEach(input => { input.disabled = false; });
        confirmAccounts.disabled = !pendingChanges.size;
    }
});

document.getElementById('reset-application').addEventListener('click', async event => {
    const button = event.currentTarget;
    if (savingAccounts || button.disabled) return;
    if (!confirm('Confirmação 1 de 3: todas as outras contas e os dados da rodada serão apagados. Admin, Gandalf e TheFFZ permanecerão.')) return;
    const phrase = prompt('Confirmação 2 de 3: digite RESETAR CTF para continuar.');
    if (phrase !== 'RESETAR CTF') {
        accountsStatus.textContent = 'Reset cancelado. A frase de confirmação não corresponde.';
        return;
    }
    if (!confirm('Confirmação 3 de 3: esta ação não pode ser desfeita. Resetar agora?')) return;

    savingAccounts = true;
    button.disabled = true;
    confirmAccounts.disabled = true;
    accountsStatus.textContent = 'Limpando a rodada e preservando as três contas...';
    try {
        const result = await Portal.request('reset_application', {confirmation: 'RESETAR CTF'});
        renderAccounts(result.accounts);
        accountsStatus.textContent = `Reset concluído. ${result.removedAccounts} conta(s) removida(s); Admin, Gandalf e TheFFZ preservadas. O CTF está pronto para uma nova rodada.`;
    } catch (error) {
        accountsStatus.textContent = error.message;
    } finally {
        savingAccounts = false;
        button.disabled = false;
    }
});

Portal.request('accounts').then(result => {
    renderAccounts(result.accounts);
    accountsStatus.textContent = result.accounts.length + ' conta(s) cadastrada(s).';
}).catch(error => { accountsStatus.textContent = error.message; });
