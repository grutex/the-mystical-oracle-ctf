/**
 * MYSTICAL ORACLE CTF — ALÉM DO VÉU (Desafio 01 · spirit)
 * ---------------------------------------------------------------
 * Recebe um Nome pela ENTRADA (campo de busca) e decide se o véu se abre.
 *
 */

document.addEventListener('DOMContentLoaded', () => {
    const input  = document.getElementById('q');
    const result = document.getElementById('veil-result');

    const urlQ = new URLSearchParams(window.location.search).get('q');
    if (urlQ !== null) {
        input.value = urlQ;
        julgarNome(urlQ, result);
    }
});

/* ------------------------------------------------------------- */
async function julgarNome(q, result) {
    const nome = (q || '').trim();
    if (!nome) { mostrarMiss(result, 'A fenda escuta o vazio… nenhum Nome foi sussurrado.'); return; }

    try {
        const response = await fetch('/reward.php', {
            method: 'POST',
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name: nome })
        });
        const data = await response.json();
        if (response.ok && data.flag) {
            mostrarHit(result, data.flag);
            return;
        }
        if (response.status !== 403) {
            throw new Error(data.error || 'O Oráculo não respondeu ao sussurro.');
        }
        mostrarMiss(result, `A névoa engole “${escaparHtml(nome)}”. O véu permanece fechado.`);
    } catch (error) {
        mostrarMiss(result, escaparHtml(error.message));
    }
}

function mostrarMiss(result, msg) {
    result.className = 'veil-result miss';
    result.innerHTML = msg;
}

function mostrarHit(result, flag) {
    // Usa o resolvedor de travessia.js (localhost <-> produção).
    const resolver = window.resolverDestino || (() => '#');
    const PROXIMO = resolver('proximo');   // próximo elemento: Ar

    result.className = 'veil-result hit';
    result.innerHTML =
        'O véu se rasga. O Espírito reconhece o seu Nome e te entrega a primeira dádiva:' +
        `<br><span class="flag-box" id="flag-value">${escaparHtml(flag)}</span>` +
        '<div class="flag-cta">' +
            '<button type="button" class="cta-btn cta-primary" id="copy-flag-btn">Copiar e Submeter Flag -&gt;</button>' +
            `<a class="cta-btn cta-ghost" href="${PROXIMO}" title="Seguir para o Portão do Ar">` +
                'Próximo: Ar ⟶</a>' +
        '</div>';

    const copyBtn = document.getElementById('copy-flag-btn');
    if (copyBtn) {
        copyBtn.addEventListener('click', () => window.oracleCopyAndSubmitFlag(flag, copyBtn));
    }
}

function escaparHtml(s) {
    return s.replace(/[&<>"']/g, c => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    ));
}
