document.addEventListener('click', async function (event) {
    const button = event.target.closest('[data-scc-copy]');
    if (!button) return;
    const field = document.getElementById(button.dataset.sccCopy);
    const status = button.parentElement.querySelector('[data-scc-copy-status]');
    if (!field || !status) return;
    try {
        if (!navigator.clipboard || !window.isSecureContext) throw new Error('Clipboard unavailable');
        await navigator.clipboard.writeText(field.value);
        status.textContent = 'Prompt copiato.';
    } catch (_) {
        field.focus();
        field.select();
        status.textContent = 'Copia automatica non disponibile: premi Ctrl+C oppure ⌘C.';
    }
});
