/**
 * MYSTICAL ORACLE CTF — PORTÃO DA TERRA (Desafio 05 · earth)
 * Apenas ambientação: poeira mineral/cristais à deriva.
 */
document.addEventListener('DOMContentLoaded', initDust);

function initDust() {
    const canvas = document.getElementById('dust-canvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    let w, h, motes;

    function resize() {
        w = canvas.width = innerWidth; h = canvas.height = innerHeight;
        motes = Array.from({ length: Math.min(70, Math.floor(w / 24)) }, spawn);
    }
    function spawn() {
        return {
            x: Math.random() * innerWidth,
            y: -Math.random() * innerHeight,
            r: Math.random() * 1.8 + 0.5,
            speed: Math.random() * 0.5 + 0.15,
            drift: (Math.random() - 0.5) * 0.3,
            alpha: Math.random() * 0.45 + 0.1,
            hue: 150 + Math.random() * 20 // faixa esmeralda
        };
    }
    function tick() {
        ctx.clearRect(0, 0, w, h);
        for (const m of motes) {
            m.y += m.speed; m.x += m.drift;
            if (m.y > h + 10) Object.assign(m, spawn(), { y: -10 });
            const g = ctx.createRadialGradient(m.x, m.y, 0, m.x, m.y, m.r * 4);
            g.addColorStop(0, `hsla(${m.hue},70%,65%,${m.alpha})`);
            g.addColorStop(1, 'hsla(150,70%,55%,0)');
            ctx.fillStyle = g;
            ctx.beginPath(); ctx.arc(m.x, m.y, m.r * 4, 0, Math.PI * 2); ctx.fill();
        }
        requestAnimationFrame(tick);
    }
    resize(); addEventListener('resize', resize); tick();
}
