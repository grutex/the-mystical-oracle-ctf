/* Shared portal client. Authentication, flags and scores live on the server. */
window.Portal = (() => {
    const base = new URL('.', document.currentScript.src);
    let csrf = '';
    let refreshingCsrf = null;
    function page(path) { return new URL(path, base).href; }
    function refreshCsrf() {
        if (!refreshingCsrf) {
            refreshingCsrf = sendRequest('bootstrap').finally(() => { refreshingCsrf = null; });
        }
        return refreshingCsrf;
    }
    function request(action, data, params = {}) {
        return action === 'bootstrap' ? refreshCsrf() : sendRequest(action, data, params);
    }
    async function sendRequest(action, data, params = {}, retried = false) {
        if (data !== undefined && !csrf) await refreshCsrf();
        const sentCsrf = csrf;
        const url = new URL('api/api.php', base);
        url.searchParams.set('action', action);
        Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, value));
        const response = await fetch(url, {
            credentials: 'same-origin', cache: 'no-store',
            method: data === undefined ? 'GET' : 'POST',
            headers: data === undefined ? {} : { 'Content-Type': 'application/json', 'X-CSRF-Token': sentCsrf },
            body: data === undefined ? undefined : JSON.stringify(data)
        });
        let result;
        try { result = await response.json(); }
        catch { throw new Error('Não foi possível falar com o Oráculo. Tente novamente.'); }
        if (result.csrf) csrf = result.csrf;
        if (!response.ok) {
            if (response.status === 403 && result.code === 'csrf_expired') {
                csrf = '';
                if (!retried) {
                    await refreshCsrf();
                    return sendRequest(action, data, params, true);
                }
            }
            if (response.status === 401) csrf = '';
            if (response.status === 401 && action !== 'login') location.assign(page('login'));
            throw new Error(result.error || 'Não foi possível concluir a solicitação.');
        }
        return result;
    }
    const escape = value => String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    return { request, page, escape };
})();
