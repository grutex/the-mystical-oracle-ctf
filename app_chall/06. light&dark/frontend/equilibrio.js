(() => {
    const form = document.getElementById('equilibrium-form');
    const button = document.getElementById('equilibrium-submit');
    const status = document.getElementById('equilibrium-status');
    const result = document.getElementById('reward-result');
    const flag = document.getElementById('final-flag');
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        button.disabled = true;
        result.hidden = true;
        flag.value = '';
        status.textContent = 'Os fragmentos se aproximam…';
        try {
            const response = await fetch('/reward.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    dark: document.getElementById('dark-fragment').value.trim(),
                    light: document.getElementById('light-fragment').value.trim(),
                }),
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'O Limiar não reconheceu os fragmentos.');
            flag.value = data.flag;
            result.hidden = false;
            status.textContent = 'O equilíbrio foi restaurado. Apresente o selo final no Oráculo.';
        } catch (error) {
            status.textContent = error.message || 'O Limiar não respondeu. Tente novamente.';
        } finally {
            button.disabled = false;
        }
    });
    const copyButton = document.getElementById('copy-flag');
    copyButton.addEventListener('click', () => window.oracleCopyAndSubmitFlag(flag.value, copyButton, status));
})();
