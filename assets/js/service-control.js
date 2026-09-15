(() => {
    const button = document.getElementById('serviceStatusButton');
    const dialog = document.getElementById('serviceDialog');
    if (!button || !dialog) return;
    const start = document.getElementById('startService'), stop = document.getElementById('stopService');
    const notice = document.getElementById('serviceNotice');
    let state = JSON.parse(document.getElementById('serviceInitialState').textContent), busy = false, invalidBranch = false;
    const render = () => {
        button.textContent = 'Proceso: ' + state.label;
        button.classList.toggle('is-ok', !!state.healthy);
        button.classList.toggle('is-error', !state.healthy);
        document.getElementById('serviceMessage').textContent = state.message;
        document.getElementById('serviceLastActivity').textContent = 'Última actividad: ' + (state.last_activity || 'Sin registro');
        const detail = document.getElementById('serviceDetail');
        detail.hidden = !state.detail; detail.textContent = state.detail || '';
        start.disabled = busy || invalidBranch || state.revision < 0 || (state.enabled && ['active','waiting'].includes(state.state));
        stop.disabled = busy || invalidBranch || state.revision < 0 || !state.enabled;
    };
    async function request(action) {
        const options = {headers: {'Accept':'application/json'}, cache:'no-store'};
        let url = 'worker_control.php?branch_id=' + encodeURIComponent(dialog.dataset.branch);
        if (action) {
            options.method = 'POST'; options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify({branch_id:Number(dialog.dataset.branch), csrf_token:dialog.dataset.csrf, revision:state.revision, action});
        }
        const response = await fetch(url, options);
        if (!(response.headers.get('Content-Type') || '').includes('application/json')) throw new Error('No se pudo consultar el proceso. Recargá la página.');
        const result = await response.json();
        if (response.status === 409 || response.status === 401 || response.status === 403) invalidBranch = true;
        if (!response.ok || !result.ok) throw new Error(result.error || 'No se pudo consultar el proceso.');
        state = result.service;
    }
    async function refresh(action) {
        if (busy || (!action && document.hidden)) return;
        busy = true; render();
        if (action) { notice.hidden = true; }
        try {
            await request(action);
            if (action) { notice.hidden = false; notice.className = 'alert success'; notice.textContent = action === 'stop' ? 'Detención solicitada para esta sucursal.' : 'Inicio solicitado para esta sucursal.'; }
        } catch (error) {
            notice.hidden = false; notice.className = 'alert error'; notice.textContent = error.message;
            state = {...state, healthy:false, label:'Sin conexión', message:'No se pudo obtener el estado actual. Revisá el aviso antes de continuar.'};
            if (action && !invalidBranch) { try { await request(); } catch (_) { /* Keep the original error visible. */ } }
        } finally { busy = false; render(); }
    }
    button.addEventListener('click', () => { dialog.showModal(); refresh(); });
    document.getElementById('closeService').addEventListener('click', () => dialog.close());
    start.addEventListener('click', () => refresh('start'));
    stop.addEventListener('click', () => refresh('stop'));
    render();
    setInterval(() => refresh(), 10000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
})();
