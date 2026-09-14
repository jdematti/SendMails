(() => {
    let submitting = false, refresh = null;
    document.addEventListener('click', event => {
        if (event.target.closest('[data-confirm-stop]') && !confirm('Se omitirán los pendientes de este envío. Un mensaje que ya se está enviando puede completarse. ¿Detenerlo?')) event.preventDefault();
    });
    document.addEventListener('submit', () => { submitting = true; clearTimeout(refresh); });
    async function update() {
        if (!submitting && !document.hidden && !document.querySelector('#liveActivity details[open]')) {
            try {
                const url = new URL(location.href); url.searchParams.set('fragment', '1');
                const response = await fetch(url, {headers: {'Accept':'text/html'}, cache:'no-store'});
                if (!response.ok || response.redirected) throw new Error();
                const html = await response.text();
                if (html.includes('<html')) throw new Error();
                document.getElementById('liveActivity').innerHTML = html;
                document.getElementById('activityRefresh').textContent = 'Actualizado a las ' + new Date().toLocaleTimeString('es-AR');
            } catch (_) {
                document.getElementById('activityRefresh').textContent = 'No se pudo actualizar el estado. Reintentando; los envíos automáticos son independientes de esta pantalla.';
            }
        }
        refresh = setTimeout(update, 10000);
    }
    refresh = setTimeout(update, 10000);
})();
