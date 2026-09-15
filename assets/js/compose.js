(() => {
    'use strict';
    const config = JSON.parse(document.getElementById('composeConfig').textContent);
    const form = document.getElementById('composeForm');
    const $ = id => document.getElementById(id);
    const key = ['sendmails', config.user, config.branch, config.kind].join(':');
    let ids = new Set(), rows = [], page = 1, total = 0, draftId = config.draft, revision = 0, busy = false, dirty = false;
    const input = () => {
        const value = Object.fromEntries(new FormData(form));
        value.plans = [...form.querySelectorAll('[name="plans[]"]:checked')].map(box => box.value);
        delete value['plans[]'];
        value.ids = [...ids]; value.include_sent = !!form.elements.include_sent?.checked;
        return value;
    };
    const notice = (message, error = false) => {
        $('composeNotice').textContent = message; $('composeNotice').className = 'alert ' + (error ? 'error' : 'success');
        $('composeNotice').hidden = !message;
    };
    const channels = () => {
        document.querySelectorAll('[data-email-field]').forEach(node => node.hidden = form.elements.channel.value === 'whatsapp');
        document.querySelectorAll('[data-wa-field]').forEach(node => node.hidden = form.elements.channel.value === 'email');
    };
    const persist = () => {
        try {
            const values = input();
            sessionStorage.setItem(key, JSON.stringify(values));
            const filters = {};
            for (const name of ['q','plans','due_date','status','snb','email','phone','client_name','include_sent','channel','template_id','whatsapp_template_id']) filters[name] = values[name];
            localStorage.setItem(key + ':filters', JSON.stringify(filters));
        } catch (_) {}
    };
    const extraFilters = (reveal = false) => {
        const values = input();
        const count = (values.plans || []).length + ['status','snb','email','phone','client_name','include_sent'].filter(name => !!values[name]).length;
        $('extraFilterCount').textContent = count ? '· ' + count + ' seleccionados' : '';
        if (reveal && count) $('extraFilters').open = true;
        if (reveal && values.manual_emails?.trim() && $('manualRecipients')) $('manualRecipients').open = true;
    };
    const changed = () => { dirty = true; $('saveStatus').textContent = 'Cambios sin guardar'; extraFilters(); persist(); };
    const fill = value => {
        for (const [name, fieldValue] of Object.entries(value)) {
            if (name === 'plans') { form.querySelectorAll('[name="plans[]"]').forEach(box => box.checked = (fieldValue || []).includes(box.value)); continue; }
            const field = form.elements.namedItem(name);
            if (!field) continue;
            if (field.type === 'checkbox') field.checked = !!fieldValue;
            else field.value = fieldValue ?? '';
        }
        ids = new Set((value.ids || []).map(String)); channels(); selection(); extraFilters(true);
    };
    async function api(action, extra = {}) {
        const response = await fetch('compose_api.php', {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
            body: JSON.stringify({action, kind: config.kind, csrf_token: config.csrf, id: draftId, revision, input: input(), ...extra})});
        if (!(response.headers.get('Content-Type') || '').includes('application/json')) throw new Error('La sesión venció o el sistema se está actualizando. Recargá la página.');
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || 'No se pudo completar la operación.');
        return data;
    }
    async function run(task) {
        if (busy) return;
        busy = true; form.inert = true; form.setAttribute('aria-busy', 'true');
        const controls = [...document.querySelectorAll('.compose-steps button, #composeForm input, #composeForm select, #composeForm textarea, #composeForm button, #saveDraft, #loadDraft')];
        const states = controls.map(control => control.disabled);
        $('loadDraft').disabled = true;
        // Read values before disabling only buttons: disabled fields disappear from FormData.
        controls.filter(control => control.tagName === 'BUTTON').forEach(control => control.disabled = true);
        try { notice(''); await task(); } catch (error) { notice(error.message, true); }
        finally {
            busy = false; form.inert = false; form.removeAttribute('aria-busy');
            controls.forEach((control, i) => control.disabled = states[i]);
            $('previousPage').disabled = page <= 1; $('nextPage').disabled = page * 50 >= total;
        }
    }
    function selection() {
        $('selectionCount').textContent = ids.size.toLocaleString('es-AR') + ' seleccionados';
        $('recipientRows').querySelectorAll('input[type=checkbox]').forEach(box => box.checked = ids.has(box.value));
    }
    async function search(target = 1) {
        const result = await api('search', {page: target});
        rows = result.rows; page = result.page; total = result.total;
        $('recipientRows').replaceChildren();
        for (const row of rows) {
            const tr = document.createElement('tr'), td = document.createElement('td'), box = document.createElement('input');
            box.type = 'checkbox'; box.value = String(row.oid ?? row.invoice_id);
            box.setAttribute('aria-label', 'Seleccionar ' + (row.razon_social ?? row.client_name));
            box.addEventListener('change', () => { if (box.checked) ids.add(box.value); else ids.delete(box.value); selection(); changed(); });
            td.append(box); tr.append(td);
            const fields = [row.razon_social ?? row.client_name,
                config.kind === 'invoice' ? row.snb + ' · $ ' + Number(row.amount).toLocaleString('es-AR') : row.codigo_cliente + ' · ' + (row.plan_contratado || ''),
                row.email || 'Sin email', row.telefono_movil || 'Sin teléfono'];
            for (const text of fields) { const cell = document.createElement('td'); cell.textContent = text; tr.append(cell); }
            $('recipientRows').append(tr);
        }
        if (!rows.length) {
            const tr = document.createElement('tr'), td = document.createElement('td'); td.colSpan = 5;
            td.textContent = config.kind === 'invoice' && !input().due_date ? 'Elegí un vencimiento para buscar facturas.' : 'No hay resultados para estos filtros.';
            tr.append(td); $('recipientRows').append(tr);
        }
        $('pageInfo').textContent = 'Página ' + page + ' · ' + total.toLocaleString('es-AR') + ' resultados';
        document.dispatchEvent(new Event('sendmails:tables'));
        selection(); persist();
    }
    function step(number) {
        document.querySelectorAll('[data-panel]').forEach(panel => panel.hidden = panel.dataset.panel !== String(number));
        document.querySelectorAll('[data-step]').forEach(button => button.setAttribute('aria-current', button.dataset.step === String(number) ? 'step' : 'false'));
    }
    const saved = result => {
        draftId = result.id; revision = result.revision; dirty = false;
        $('saveStatus').textContent = 'Borrador #' + draftId + ' guardado · compartido con la sucursal';
        const url = new URL(location.href); url.searchParams.set('draft', draftId); history.replaceState(null, '', url);
    };
    async function review() {
        const result = await api('review'); saved(result);
        $('reviewName').textContent = input().name; $('reviewCounts').replaceChildren();
        for (const [label, value] of [['Seleccionados', result.counts.selected], ['Emails', result.counts.email], ['WhatsApp', result.counts.whatsapp]]) {
            const card = document.createElement('div'); card.className = 'stat-card'; const strong = document.createElement('strong');
            strong.textContent = value.toLocaleString('es-AR'); card.append(strong, document.createTextNode(label)); $('reviewCounts').append(card);
        }
        const c = result.counts;
        $('reviewOmissions').textContent = 'Email: ' + c.email_invalid + ' sin dirección válida, ' + c.email_excluded + ' excluidos o dados de baja, ' + c.email_duplicates + ' duplicados. WhatsApp: ' + c.phone_invalid + ' sin celular válido, ' + c.phone_excluded + ' bajas, ' + c.phone_duplicates + ' duplicados.';
        $('reviewTest').hidden = !result.preview.test;
        $('reviewTest').textContent = 'Modo prueba: los emails irán a ' + result.preview.test_email + '. Las facturas reales no se marcarán como enviadas. WhatsApp, si está seleccionado, usa los destinatarios reales.';
        $('reviewSchedule').textContent = 'Inicio: ' + result.scheduled_at;
        $('reviewSubject').textContent = 'Asunto: ' + result.preview.subject + ' · Ejemplo: ' + result.preview.example;
        $('reviewFrame').srcdoc = result.preview.html; $('reviewFrame').hidden = input().channel === 'whatsapp';
        $('reviewAttachments').replaceChildren();
        for (const attachment of result.preview.attachments) {
            const li = document.createElement('li'); li.textContent = 'Adjunto: ' + attachment.name + ' (' + Math.ceil(attachment.size / 1024) + ' KB)'; $('reviewAttachments').append(li);
        }
        const wa = result.preview.whatsapp;
        $('reviewWhatsApp').textContent = wa ? 'WhatsApp · ' + wa.name + '\n' + (wa.components || []).filter(item => item.type === 'BODY').map(item => item.text || '').join('\n') : '';
        step(3);
    }
    document.querySelectorAll('[data-step]').forEach(button => button.addEventListener('click', () => { if (!busy) step(button.dataset.step); }));
    $('searchRows').onclick = () => run(() => search(1));
    $('previousPage').onclick = () => run(() => search(page - 1));
    $('nextPage').onclick = () => run(() => search(page + 1));
    $('selectPage').onclick = () => { rows.forEach(row => ids.add(String(row.oid ?? row.invoice_id))); selection(); changed(); };
    $('selectAll').onclick = () => run(async () => { const data = await api('select_all'); data.ids.forEach(id => ids.add(String(id))); selection(); changed(); });
    $('clearSelection').onclick = () => { ids.clear(); selection(); changed(); };
    $('saveDraft').onclick = () => run(async () => { saved(await api('save')); notice('Borrador guardado. Otro usuario de esta sucursal puede continuarlo.'); });
    $('reviewSend').onclick = $('reviewTop').onclick = () => run(review);
    $('confirmSend').onclick = () => run(async () => {
        if (dirty) throw new Error('Hay cambios posteriores a la revisión. Volvé a revisar el envío.');
        const result = await api('confirm'); dirty = false;
        try { sessionStorage.removeItem(key); } catch (_) {}
        location.href = result.url;
    });
    $('loadDraft').onchange = () => run(async () => {
        if (!$('loadDraft').value) return;
        if (dirty && !confirm('Hay cambios sin guardar. ¿Cargar el otro borrador y descartarlos?')) return;
        const loaded = await api('load', {id: Number($('loadDraft').value)});
        fill(loaded.input); saved(loaded); step(1); await search(1);
        $('loadDraft').closest('details').open = false;
    });
    $('clearFilters').onclick = () => run(async () => {
        for (const name of ['q', 'due_date', 'status', 'snb', 'email', 'phone', 'client_name']) if (form.elements[name]) form.elements[name].value = '';
        form.querySelectorAll('[name="plans[]"]').forEach(box => box.checked = false);
        if (form.elements.include_sent) form.elements.include_sent.checked = false;
        changed(); await search(1);
    });
    $('exportInvoices')?.addEventListener('click', () => {
        const data = input(), query = new URLSearchParams({...data, venc: data.due_date, name: data.client_name, include_sent: data.include_sent ? '1' : '0', limit: '0'});
        query.delete('ids'); location.href = 'invoice_export.php?' + query;
    });
    form.addEventListener('submit', event => { event.preventDefault(); run(() => search(1)); });
    form.addEventListener('input', changed);
    form.elements.channel.addEventListener('change', () => { channels(); run(() => search(1)); });
    form.elements.due_date?.addEventListener('change', () => {
        if (ids.size) { ids.clear(); selection(); changed(); notice('Al cambiar el vencimiento se vació la selección de facturas. Seleccioná las del nuevo vencimiento.'); }
    });
    window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
    form.elements.template_id.value = config.template;
    form.elements.name.value = (config.kind === 'invoice' ? 'Facturas ' : 'Campaña ') + config.now.slice(0,10);
    channels();
    run(async () => {
        if (draftId) { const loaded = await api('load'); fill(loaded.input); saved(loaded); }
        else {
            try {
                const remembered = JSON.parse(sessionStorage.getItem(key) || localStorage.getItem(key + ':filters') || 'null');
                if (remembered) fill(remembered);
            } catch (_) {}
            if (!form.elements.scheduled_at.value) form.elements.scheduled_at.value = config.now;
        }
        await search(1);
    });
})();
