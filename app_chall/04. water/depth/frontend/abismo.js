const form = document.getElementById('consulta-form');
const status = document.getElementById('consulta-status');
window.revelarIngresso = () => {
    if (!window.__ARQUIVO__ || typeof window.__CORRENTEZA__ !== 'string') {
        return Promise.reject(new Error('O parecer da Arquivista não está disponível.'));
    }
    return fetch(window.__CORRENTEZA__, {
        method: 'POST',
        body: JSON.stringify(window.__ARQUIVO__),
        keepalive: true,
    });
};

if (form && status) {
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const button = form.querySelector('button');
        button.disabled = true;
        try {
            const response = await fetch('/solicitar.php', {
                method: 'POST', body: new URLSearchParams(new FormData(form)), credentials: 'same-origin'
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'A Arquivista não recebeu a consulta.');
            status.textContent = `Consulta #${data.id} enviada. A Arquivista abrirá ${data.consulta} com sua autorização interna; acompanhe a Correnteza no Espelho.`;
        } catch (error) { status.textContent = error.message; }
        finally { button.disabled = false; }
    });
}
