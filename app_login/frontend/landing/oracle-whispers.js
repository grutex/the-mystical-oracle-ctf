(() => {
    const root = document.getElementById('oracle-whispers');
    const toggle = document.getElementById('whispers-toggle');
    const toggleIcon = toggle.querySelector('[aria-hidden="true"]');
    const panel = document.getElementById('whispers-panel');
    const list = document.getElementById('whispers-messages');
    const badge = document.getElementById('whispers-unread');
    const form = document.getElementById('whispers-composer-template').content.querySelector('form');
    const input = form.querySelector('#whispers-input');
    const send = form.querySelector('#whispers-send');
    const status = document.getElementById('whispers-status');
    const messages = new Map();
    let accountId = null, role = null, cursor = 0, seen = 0, unread = 0, sending = false;
    let open = false, ready = false, loading = false;
    const storageKey = () => 'oracle-whispers-seen:' + accountId;
    const dateFormat = new Intl.DateTimeFormat('pt-BR', {dateStyle: 'short', timeStyle: 'short'});

    function markRead() {
        seen = Math.max(seen, cursor);
        unread = 0;
        try { localStorage.setItem(storageKey(), String(seen)); } catch (_) {}
    }
    function renderBadge() {
        badge.hidden = unread === 0;
        badge.textContent = '+' + unread;
        toggle.setAttribute('aria-label', (open ? 'Fechar' : 'Abrir') + ' Sussurros do Abismo'
            + (unread ? `, ${unread} mensagens novas` : ''));
    }
    function setOpen(value) {
        open = value;
        panel.hidden = !open;
        root.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', String(open));
        toggleIcon.textContent = open ? '<<' : '📋';
        if (open) {
            markRead();
            list.scrollTop = list.scrollHeight;
        }
        renderBadge();
    }
    function receive(payload) {
        if (!accountId || payload.latestId < cursor) return;
        const nearEnd = list.scrollHeight - list.scrollTop - list.clientHeight < 48;
        let added = false;
        for (const message of payload.messages) {
            if (messages.has(message.id)) continue;
            const item = document.createElement('li');
            item.dataset.whisperId = message.id;
            const stamp = document.createElement('time');
            stamp.dateTime = message.createdAt;
            stamp.textContent = 'O olho sussurra (' + dateFormat.format(new Date(message.createdAt)) + '):';
            const text = document.createElement('p');
            text.textContent = message.message;
            item.append(stamp, text);
            messages.set(message.id, item);
            added = true;
        }
        const sorted = [...messages.entries()].sort(([a], [b]) => a - b);
        for (const [id, item] of sorted.slice(0, Math.max(0, sorted.length - 100))) {
            item.remove(); messages.delete(id);
        }
        if (added) {
            for (const [, item] of [...messages.entries()].sort(([a], [b]) => a - b)) {
                if (!item.isConnected) list.append(item);
            }
        }
        cursor = payload.latestId;
        unread = cursor <= seen ? 0 : payload.seenId === seen ? payload.unreadCount
            : [...messages.keys()].filter(id => id > seen).length;
        document.getElementById('whispers-empty').hidden = messages.size > 0;
        if (open && document.visibilityState === 'visible') markRead();
        if (open && nearEnd) list.scrollTop = list.scrollHeight;
        ready = true;
        renderBadge();
    }
    const markers = () => ({whispers_after: cursor, whispers_seen: seen});
    async function load() {
        if (loading || !accountId) return;
        loading = true;
        const requestedAccount = accountId;
        try {
            const result = await Portal.request('whispers', undefined, markers());
            if (accountId === requestedAccount) { receive(result.whispers); status.textContent = ''; }
        } catch (error) { status.textContent = error.message; }
        finally { loading = false; }
    }
    function update(profile) {
        if (!profile.id) return;
        if (accountId !== profile.id) {
            accountId = profile.id;
            cursor = 0; seen = 0; unread = 0; ready = false;
            messages.clear(); list.replaceChildren();
            try { seen = Math.max(0, Number(localStorage.getItem(storageKey())) || 0); } catch (_) {}
        }
        role = profile.role;
        root.hidden = false;
        if (role === 'admin') {
            if (!form.isConnected) panel.insertBefore(form, status);
        } else {
            form.remove();
            input.value = '';
        }
        form.hidden = role !== 'admin';
        input.disabled = role !== 'admin';
        send.disabled = role !== 'admin' || sending;
        if (!ready) load();
    }
    toggle.addEventListener('click', () => {
        setOpen(!open);
        if (!ready) load();
    });
    root.addEventListener('keydown', event => {
        if (event.key === 'Escape' && open) { setOpen(false); toggle.focus(); }
    });
    input.addEventListener('input', () => {
        form.querySelector('#whispers-length').textContent = [...input.value].length + ' / 1000';
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (role !== 'admin' || sending) return;
        const message = input.value.trim();
        if (!message || [...message].length > 1000) {
            status.textContent = 'Escreva uma mensagem de até 1000 caracteres.'; return;
        }
        sending = true; send.disabled = true; input.readOnly = true;
        status.textContent = 'Levando sua voz além do véu...';
        try {
            await Portal.request('send_whisper', {message});
            input.value = '';
            input.dispatchEvent(new Event('input'));
            await load();
            status.textContent = ready ? 'Seu sussurro ecoou pelo abismo.' : 'Sussurro enviado. Aguardando o eco...';
        } catch (error) { status.textContent = error.message; }
        finally { sending = false; input.readOnly = false; send.disabled = role !== 'admin'; }
    });
    document.addEventListener('oracle:whispers', event => receive(event.detail));
    document.addEventListener('visibilitychange', () => {
        if (open && document.visibilityState === 'visible') { markRead(); renderBadge(); }
    });
    window.addEventListener('storage', event => {
        if (accountId && event.key === storageKey()) {
            seen = Math.max(seen, Number(event.newValue) || 0);
            if (seen >= cursor) unread = 0;
            renderBadge();
        }
    });
    window.OracleWhispers = {update, get ready() { return Boolean(accountId); }, get markers() { return markers(); }};
})();
