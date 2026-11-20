document.addEventListener('DOMContentLoaded', async () => {
    const path = new URLSearchParams(location.search).get('path');
    const output = document.getElementById('output');
    if (!path) {
        output.textContent = 'Nenhum arquivo foi indicado ao leitor.';
        return;
    }
    try {
        const response = await fetch(`/shadow.php?path=${encodeURIComponent(path)}`, { credentials: 'same-origin' });
        const data = await response.json();
        output.textContent = data.content || data.error || 'Falha ao ler o arquivo.';
    } catch (_) {
        output.textContent = 'A consulta se perdeu entre as sombras.';
    }
});
