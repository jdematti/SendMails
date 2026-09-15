(() => {
    const filters = document.getElementById('purgeFilters');
    const review = document.getElementById('purgeReview');
    filters?.addEventListener('change', () => { if (review) review.hidden = true; });
    for (const form of [filters, document.getElementById('purgeConfirm')]) {
        form?.addEventListener('submit', event => {
            if (form.dataset.submitting) { event.preventDefault(); return; }
            form.dataset.submitting = '1';
            const button = form.querySelector('button[type=submit]');
            button.disabled = true; button.textContent = form.id === 'purgeConfirm' ? 'Eliminando registros…' : 'Calculando…';
        });
    }
})();
