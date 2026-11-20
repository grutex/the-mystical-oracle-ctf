/**
 * MYSTICAL ORACLE CTF — CHALLENGE LANDING SCRIPT
 */

document.addEventListener('DOMContentLoaded', () => {
    applyRuntimeLinks();
    initDustCanvas();
    initConnectingLines();
    initAudioSynth();

    window.addEventListener('resize', () => {
        initConnectingLines();
    });
});

function applyRuntimeLinks() {
    const config = window.ORACLE_CONFIG || {};
    const withCurrentPort = (host) => `${location.protocol}//${host}${location.port ? `:${location.port}` : ''}`;
    const portal = withCurrentPort(config.portalHost || 'localhost');

    const oracle = document.querySelector('[data-portal-oracle]');
    if (oracle) oracle.href = `${portal}/oracle`;

    const hosts = {
        'spirit-node': { key: 'spirit', host: 'spirit' },
        'air-node': { key: 'air', host: 'air' },
        'fire-node': { key: 'fire', host: 'fire' },
        'water-node': { key: 'water', host: 'water' },
        'earth-node': { key: 'earth', host: 'earth' },
        'light-dark-node': { key: 'light_dark', host: 'light-dark' }
    };
    Object.entries(hosts).forEach(([className, challenge]) => {
        const node = document.querySelector(`.${className}`);
        const link = node && node.querySelector('.island-link');
        if (!link) return;
        link.href = `${portal}/api/api.php?action=launch&challenge=${encodeURIComponent(challenge.key)}`;
        node.dataset.subdomain = `${challenge.host}.${config.challengeHost || 'localhost'}`;
    });
}

/* -------------------------------------------------------------
 * 1. LINHAS DE CONEXÃO (SVG PATH)
 * Spirit -> Air -> Fire -> Water -> Earth -> Luz & Trevas
 * ------------------------------------------------------------- */
function initConnectingLines() {
    const container = document.getElementById('path-map-container');
    const pathLine = document.getElementById('connecting-line');
    
    if (!container || !pathLine) return;

    const nodes = [
        document.querySelector('.spirit-node'),
        document.querySelector('.air-node'),
        document.querySelector('.fire-node'),
        document.querySelector('.water-node'),
        document.querySelector('.earth-node'),
        document.querySelector('.light-dark-node')
    ];

    const containerRect = container.getBoundingClientRect();
    const colors = ['rgba(168, 85, 247, 0.4)', '#7dd3fc', '#f59e0b', '#1d4ed8', '#10b981'];
    const points = nodes.map((node) => {
        if (!node) return null;
        const circle = node.querySelector('.island-circle');
        const circleRect = circle.getBoundingClientRect();

        const x = circleRect.left + circleRect.width / 2 - containerRect.left;
        const y = circleRect.top + circleRect.height / 2 - containerRect.top;

        return { x, y };
    });

    const segments = colors.map((color, index) => {
        const start = points[index];
        const end = points[index + 1];
        if (!start || !end) return null;
        const segment = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        segment.setAttribute('d', `M ${start.x} ${start.y} L ${end.x} ${end.y}`);
        segment.setAttribute('stroke', color);
        segment.setAttribute('class', 'path-line-anim');
        return segment;
    }).filter(Boolean);
    pathLine.replaceChildren(...segments);
}

/* -------------------------------------------------------------
 * 2. CANVAS DE POEIRA CÓSMICA DE FUNDO
 * ------------------------------------------------------------- */
function initDustCanvas() {
    const canvas = document.getElementById('dust-canvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');

    let width = (canvas.width = window.innerWidth);
    let height = (canvas.height = window.innerHeight);

    window.addEventListener('resize', () => {
        width = canvas.width = window.innerWidth;
        height = canvas.height = window.innerHeight;
    });

    const numParticles = 65;
    const particles = Array.from({ length: numParticles }, () => ({
        x: Math.random() * width,
        y: Math.random() * height,
        radius: Math.random() * 1.8 + 0.3,
        color: Math.random() > 0.5 ? 'rgba(168, 85, 247, ' : 'rgba(6, 182, 212, ',
        alpha: Math.random() * 0.5 + 0.1,
        speedX: (Math.random() - 0.5) * 0.3,
        speedY: -Math.random() * 0.4 - 0.1
    }));

    function render() {
        ctx.clearRect(0, 0, width, height);

        particles.forEach(p => {
            p.x += p.speedX;
            p.y += p.speedY;

            if (p.y < 0) p.y = height;
            if (p.x < 0) p.x = width;
            if (p.x > width) p.x = 0;

            ctx.beginPath();
            ctx.arc(p.x, p.y, p.radius, 0, Math.PI * 2);
            ctx.fillStyle = `${p.color}${p.alpha})`;
            ctx.fill();
        });

        requestAnimationFrame(render);
    }

    render();
}

/* -------------------------------------------------------------
 * 3. ÁUDIO AMBIENTE E SINTETIZADOR WEB AUDIO API
 * ------------------------------------------------------------- */
function initAudioSynth() {
    let audioCtx = null;
    let isAudioEnabled = false;

    const audioBtn = document.getElementById('audio-toggle');
    const audioIcon = document.getElementById('audio-icon');
    const audioText = document.getElementById('audio-text');

    if (!audioBtn) return;

    audioBtn.addEventListener('click', () => {
        if (!audioCtx) {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            audioCtx = new AudioContext();
        }

        if (audioCtx.state === 'suspended') {
            audioCtx.resume();
        }

        isAudioEnabled = !isAudioEnabled;

        if (isAudioEnabled) {
            audioIcon.className = 'w-2 h-2 rounded-full bg-emerald-400 animate-pulse';
            audioText.innerText = 'SOM: LIGADO';
            playTone(440, 'sine', 0.1, 0.5);
        } else {
            audioIcon.className = 'w-2 h-2 rounded-full bg-red-500 animate-pulse';
            audioText.innerText = 'SOM: DESLIGADO';
        }
    });

    document.querySelectorAll('.island-link').forEach((link, idx) => {
        link.addEventListener('mouseenter', () => {
            if (isAudioEnabled && audioCtx) {
                const freqs = [220, 277.18, 329.63, 440, 554.37, 659.25];
                playTone(freqs[idx % freqs.length], 'sine', 0.05, 0.4);
            }
        });
    });

    function playTone(freq, type, gainValue, duration) {
        try {
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();

            osc.type = type;
            osc.frequency.setValueAtTime(freq, audioCtx.currentTime);

            gain.gain.setValueAtTime(gainValue, audioCtx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + duration);

            osc.connect(gain);
            gain.connect(audioCtx.destination);

            osc.start();
            osc.stop(audioCtx.currentTime + duration);
        } catch (e) {
            console.error(e);
        }
    }
}
