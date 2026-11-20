(() => {
    const pupil = document.getElementById('pupil');
    const eye = document.getElementById('eye-container');
    const crown = document.getElementById('completion-crown');
    const replayButton = document.getElementById('oracle-replay-button');
    let profile = {};
    let busy = false;
    let overlay = null;

    const reducedMotion = () => document.documentElement.classList.contains('oracle-motion-disabled')
        || window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const wait = duration => new Promise(resolve => setTimeout(resolve, duration));
    async function animate(element, frames, duration) {
        await element.animate(frames, { duration, easing: 'ease-in-out', fill: 'forwards' }).finished;
    }

    function update(nextProfile) {
        profile = nextProfile;
        crown.hidden = !profile.hasCrown;
        replayButton.hidden = !(profile.role === 'player' && profile.hasCrown && profile.allFlagsSolved);
        replayButton.disabled = busy;
        document.body.classList.toggle('oracle-finished', Boolean(profile.oracleFinaleSeen) && !busy);
        const ready = profile.role === 'player' && profile.allFlagsSolved && !profile.oracleFinaleSeen;
        pupil.classList.toggle('oracle-complete', Boolean(ready));
        eye.title = ready ? 'Receber o último olhar do Oráculo' : 'Clique para comunhão com o Olho Abissal';
        if (ready) {
            pupil.setAttribute('role', 'button');
            pupil.setAttribute('tabindex', '0');
            pupil.setAttribute('aria-label', 'Receber o último olhar do Oráculo');
        } else {
            ['role', 'tabindex', 'aria-label'].forEach(attribute => pupil.removeAttribute(attribute));
        }
    }

    async function showMessage(message, text) {
        message.textContent = text;
        await animate(message, [{opacity: 0}, {opacity: 1}], reducedMotion() ? 150 : 650);
        await wait(reducedMotion() ? 1200 : 1900);
        await animate(message, [{opacity: 1}, {opacity: 0}], reducedMotion() ? 150 : 650);
    }

    async function reveal() {
        if (busy || profile.role !== 'player' || !profile.allFlagsSolved
            || profile.oracleFinaleSeen) return;
        busy = true;
        replayButton.disabled = true;
        const focusBefore = document.activeElement;
        const scrollBefore = document.body.style.overflow;
        let finalProfile;
        try {
            finalProfile = (await Portal.request('oracle_finale', {})).profile;
            const rect = pupil.getBoundingClientRect();
            const x = rect.left + rect.width / 2;
            const y = rect.top + rect.height / 2;
            const radius = Math.max(
                Math.hypot(x, y), Math.hypot(innerWidth - x, y),
                Math.hypot(x, innerHeight - y), Math.hypot(innerWidth - x, innerHeight - y)
            ) + 80;
            overlay = document.createElement('div');
            overlay.className = 'oracle-finale';
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');
            overlay.setAttribute('aria-label', 'O último olhar do Oráculo');
            overlay.tabIndex = -1;
            const wash = document.createElement('div');
            wash.className = 'oracle-finale-wash';
            wash.style.cssText = `width:${radius * 2}px;height:${radius * 2}px;left:${x - radius}px;top:${y - radius}px;`;
            const message = document.createElement('p');
            message.className = 'oracle-finale-message';
            message.setAttribute('role', 'status');
            message.setAttribute('aria-live', 'polite');
            message.setAttribute('aria-atomic', 'true');
            overlay.append(wash, message);
            overlay.addEventListener('keydown', event => { event.preventDefault(); });
            document.body.appendChild(overlay);
            document.body.style.overflow = 'hidden';
            overlay.focus();
            await animate(wash, [{transform: 'scale(0)', opacity: 0}, {transform: 'scale(1)', opacity: 1}], reducedMotion() ? 200 : 2400);
            await wait(300);
            await showMessage(message, 'bene factum');
            await showMessage(message, 'Abyss te resalutat');
            // Preserve the purple farewell until the white scene has ended.
            busy = false;
            document.dispatchEvent(new CustomEvent('oracle:profile', {detail: finalProfile}));
            await animate(overlay, [{opacity: 1}, {opacity: 0}], reducedMotion() ? 150 : 800);
        } catch (error) {
            document.getElementById('portal-status').textContent = error.message;
            if (finalProfile) document.dispatchEvent(new CustomEvent('oracle:profile', {detail: finalProfile}));
        } finally {
            overlay?.remove();
            overlay = null;
            busy = false;
            document.body.style.overflow = scrollBefore;
            update(profile);
            if (focusBefore?.isConnected) focusBefore.focus();
        }
    }

    eye.addEventListener('click', event => {
        if (!profile.allFlagsSolved || profile.oracleFinaleSeen || profile.role !== 'player') return;
        event.stopPropagation();
        reveal();
    }, {capture: true});
    pupil.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            reveal();
        }
    });
    replayButton.addEventListener('click', async () => {
        if (busy || profile.role !== 'player' || !profile.hasCrown || !profile.allFlagsSolved) return;
        busy = true;
        replayButton.disabled = true;
        try {
            const result = await Portal.request('prepare_oracle_finale', {});
            busy = false;
            document.dispatchEvent(new CustomEvent('oracle:profile', {detail: result.profile}));
            pupil.focus();
        } catch (error) {
            document.getElementById('portal-status').textContent = error.message;
        } finally {
            busy = false;
            update(profile);
        }
    });
    window.OracleFinale = { update, get busy() { return busy; } };
})();
