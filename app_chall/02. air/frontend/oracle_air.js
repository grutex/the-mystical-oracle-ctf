/**
 * MYSTICAL ORACLE CTF — PORTÃO DO AR (Desafio 02 · air)
 * ---------------------------------------------------------------
 * Apenas ambientação: correntes de vento soprando na horizontal.
 */

document.addEventListener('DOMContentLoaded', () => {
    initWind();
});

/* -------------------------------------------------------------
 * CORRENTES DE VENTO (partículas soprando na horizontal)
 * ------------------------------------------------------------- */
function initWind() {
    const canvas = document.getElementById('wind-canvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');

    let width, height, streaks;

    function resize() {
        width = canvas.width = window.innerWidth;
        height = canvas.height = window.innerHeight;
        const count = Math.min(60, Math.floor(width / 26));
        streaks = Array.from({ length: count }, spawn);
    }

    function spawn() {
        return {
            x: -Math.random() * window.innerWidth,
            y: Math.random() * window.innerHeight,
            len: Math.random() * 90 + 30,
            speed: Math.random() * 2.4 + 0.8,
            alpha: Math.random() * 0.4 + 0.08,
            hue: 186 + Math.random() * 20 // faixa do ciano do Ar
        };
    }

    function tick() {
        ctx.clearRect(0, 0, width, height);
        for (const s of streaks) {
            s.x += s.speed;
            if (s.x > width + s.len) Object.assign(s, spawn(), { x: -s.len });

            const grad = ctx.createLinearGradient(s.x, s.y, s.x + s.len, s.y);
            grad.addColorStop(0, `hsla(${s.hue}, 90%, 70%, 0)`);
            grad.addColorStop(0.5, `hsla(${s.hue}, 90%, 70%, ${s.alpha})`);
            grad.addColorStop(1, `hsla(${s.hue}, 90%, 70%, 0)`);
            ctx.strokeStyle = grad;
            ctx.lineWidth = 1.4;
            ctx.beginPath();
            ctx.moveTo(s.x, s.y);
            ctx.lineTo(s.x + s.len, s.y);
            ctx.stroke();
        }
        requestAnimationFrame(tick);
    }

    resize();
    window.addEventListener('resize', resize);
    tick();
}
