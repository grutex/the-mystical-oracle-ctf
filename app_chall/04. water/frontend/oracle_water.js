/**
 * MYSTICAL ORACLE CTF — PORTÃO DA ÁGUA (Desafio 04 · water)
 * Apenas ambientação: gotículas/bolhas subindo à deriva.
 */
document.addEventListener('DOMContentLoaded', initDrops);

function initDrops() {
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
            y: innerHeight + Math.random() * innerHeight,
            r: Math.random() * 1.8 + 0.5,
            speed: Math.random() * 0.5 + 0.15,
            drift: (Math.random() - 0.5) * 0.3,
            alpha: Math.random() * 0.45 + 0.1,
            hue: 195 + Math.random() * 20 // faixa ciano/azul
        };
    }
    function tick() {
        ctx.clearRect(0, 0, w, h);
        for (const m of motes) {
            m.y -= m.speed; m.x += m.drift;   // bolhas sobem
            if (m.y < -10) Object.assign(m, spawn(), { y: h + 10 });
            const g = ctx.createRadialGradient(m.x, m.y, 0, m.x, m.y, m.r * 4);
            g.addColorStop(0, `hsla(${m.hue},80%,70%,${m.alpha})`);
            g.addColorStop(1, 'hsla(200,80%,60%,0)');
            ctx.fillStyle = g;
            ctx.beginPath(); ctx.arc(m.x, m.y, m.r * 4, 0, Math.PI * 2); ctx.fill();
        }
        requestAnimationFrame(tick);
    }
    resize(); addEventListener('resize', resize); tick();
}
