(() => {
    const form = document.querySelector('.template-editor-card form');
    if (!form) return;
    const status = document.querySelector('[data-editor-status]');
    let dirty = false;
    form.addEventListener('input', () => { dirty = true; if (status) status.textContent = 'Cambios sin guardar'; });
    form.addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
    if (location.pathname.endsWith('invoice_template_edit.php')) {
        const html = form.querySelector('[name="html_body"]'), frame = document.querySelector('iframe.preview-frame');
        let timer;
        html?.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(() => { frame.srcdoc = html.value; }, 250);
        });
    }
})();
