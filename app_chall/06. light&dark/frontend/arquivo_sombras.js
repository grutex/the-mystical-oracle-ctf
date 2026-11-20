document.addEventListener('DOMContentLoaded', async () => {
    const list = document.getElementById('directory-list');
    try {
        const response = await fetch('/diretorio.php', { credentials: 'same-origin' });
        const data = await response.json();
        if (!response.ok) throw new Error(data.error);
        list.textContent = data.entries.join('\n');
    } catch (_) {
        list.textContent = 'Não foi possível consultar a listagem.';
    }
});

document.getElementById('cat-form').addEventListener('submit', (event) => {
    event.preventDefault();
    const path = document.getElementById('path').value;
    location.assign(`/leitor-sombras?path=${encodeURIComponent(path)}`);
});
