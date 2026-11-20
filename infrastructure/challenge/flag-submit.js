(() => {
    async function copyFlag(flag) {
        try {
            await navigator.clipboard.writeText(flag);
            return;
        } catch (_) {
            // HTTP local pode não oferecer a API moderna de área de transferência.
        }
        const input = document.createElement('textarea');
        input.value = flag;
        input.style.position = 'fixed';
        input.style.opacity = '0';
        input.setAttribute('aria-hidden', 'true');
        const focused = document.activeElement;
        document.body.append(input);
        input.select();
        try {
            if (!document.execCommand('copy')) throw new Error('Clipboard unavailable');
        } finally {
            input.remove();
            focused?.focus();
        }
    }

    window.oracleCopyAndSubmitFlag = async (flag, button, status = button) => {
        if (button.disabled || !flag) return;
        const label = button.textContent;
        button.disabled = true;
        try {
            await copyFlag(flag);
            status.textContent = 'flag copiada! redirecionando...';
            if (status !== button) button.textContent = 'flag copiada! redirecionando...';
            const resolved = window.resolverDestino?.('oraculo');
            const portalHost = window.ORACLE_CONFIG?.portalHost;
            const destination = resolved && resolved !== '#' ? resolved : portalHost
                ? `${location.protocol}//${portalHost}${location.port ? `:${location.port}` : ''}/oracle` : '/oracle';
            setTimeout(() => location.assign(destination), 1000);
        } catch (_) {
            status.textContent = 'Não foi possível copiar. Copie a flag manualmente e tente novamente.';
            button.disabled = false;
            if (status === button) setTimeout(() => { button.textContent = label; }, 3000);
        }
    };
})();
