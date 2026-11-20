/**
 * MYSTICAL ORACLE CTF — PORTÃO DO FOGO (Desafio 03 · fire)
 * Ambientação: brasas subindo.
 */
document.addEventListener('DOMContentLoaded', initEmbers);

function initEmbers() {
    const canvas = document.getElementById('ember-canvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    let w, h, embers;

    function resize() {
        w = canvas.width = innerWidth; h = canvas.height = innerHeight;
        embers = Array.from({ length: Math.min(80, Math.floor(w / 20)) }, spawn);
    }
    function spawn() {
        return {
            x: Math.random() * innerWidth,
            y: innerHeight + Math.random() * innerHeight,
            r: Math.random() * 2 + 0.6,
            speed: Math.random() * 0.9 + 0.3,
            drift: (Math.random() - 0.5) * 0.6,
            alpha: Math.random() * 0.6 + 0.15,
            hue: 24 + Math.random() * 24 // laranja/âmbar
        };
    }
    function tick() {
        ctx.clearRect(0, 0, w, h);
        for (const e of embers) {
            e.y -= e.speed; e.x += e.drift;
            e.alpha += (Math.random() - 0.5) * 0.04;
            e.alpha = Math.max(0.05, Math.min(0.8, e.alpha));
            if (e.y < -10) Object.assign(e, spawn(), { y: h + 10 });
            const g = ctx.createRadialGradient(e.x, e.y, 0, e.x, e.y, e.r * 4);
            g.addColorStop(0, `hsla(${e.hue},100%,62%,${e.alpha})`);
            g.addColorStop(1, 'hsla(24,100%,50%,0)');
            ctx.fillStyle = g;
            ctx.beginPath(); ctx.arc(e.x, e.y, e.r * 4, 0, Math.PI * 2); ctx.fill();
        }
        requestAnimationFrame(tick);
    }
    resize(); addEventListener('resize', resize); tick();
}
