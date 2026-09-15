(() => {
    const input = document.getElementById('attachments');
    const dialog = document.getElementById('attachmentDialog');
    if (!input || !dialog) return;
    const content = document.getElementById('attachmentPreviewContent');
    const status = document.getElementById('attachmentPreviewStatus');
    const download = document.getElementById('downloadAttachment');
    let controller, objectUrl, requestId = 0;
    function clearPreview() {
        requestId++;
        controller?.abort(); controller = null;
        content.replaceChildren();
        download.hidden = true; download.removeAttribute('href');
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = null;
    }
    async function openPreview(name, getFile) {
        clearPreview();
        const current = requestId;
        document.getElementById('attachmentName').textContent = name;
        status.textContent = 'Cargando vista previa…';
        dialog.showModal();
        controller = new AbortController();
        try {
            const file = await getFile(controller.signal);
            if (current !== requestId) return;
            if (file.size > 10 * 1024 * 1024) throw new Error('El archivo supera el límite de 10 MB para adjuntos.');
            const head = new Uint8Array(await file.slice(0, 32).arrayBuffer());
            if (current !== requestId) return;
            const signature = String.fromCharCode(...head);
            let type = 'application/octet-stream';
            if (signature.startsWith('%PDF-')) type = 'application/pdf';
            else if (signature.startsWith('\x89PNG\r\n\x1a\n')) type = 'image/png';
            else if (head[0] === 255 && head[1] === 216 && head[2] === 255) type = 'image/jpeg';
            else if (/^GIF8[79]a/.test(signature)) type = 'image/gif';
            else if (signature.startsWith('RIFF') && signature.slice(8,12) === 'WEBP') type = 'image/webp';
            else if (signature.startsWith('BM')) type = 'image/bmp';
            else if (signature.slice(4,12) === 'ftypavif') type = 'image/avif';
            else if (['text/plain','text/csv','text/tab-separated-values','application/json'].includes(file.type.split(';')[0]) || /\.(txt|csv|tsv|json|log|md)$/i.test(name)) type = 'text/plain';
            // Only known images/PDF are embedded. Other files are downloaded or rendered as literal text.
            objectUrl = URL.createObjectURL(new Blob([file], {type}));
            download.href = objectUrl; download.download = name; download.hidden = false;
            status.textContent = '';
            if (type.startsWith('image/')) {
                const image = document.createElement('img'); image.alt = name; image.src = objectUrl;
                image.addEventListener('error', () => { if (current === requestId) status.textContent = 'No se pudo mostrar la imagen. Podés descargar el archivo.'; });
                content.append(image);
            } else if (type === 'application/pdf') {
                const frame = document.createElement('iframe'); frame.title = 'PDF: ' + name; frame.src = objectUrl;
                content.append(frame);
                status.textContent = 'Si tu navegador no muestra el PDF, podés descargarlo para abrirlo.';
            } else if (type === 'text/plain') {
                const buffer = await file.slice(0, 200000).arrayBuffer();
                if (current !== requestId) return;
                const bytes = new Uint8Array(buffer);
                const encoding = bytes[0]===255 && bytes[1]===254 ? 'utf-16le' : bytes[0]===254 && bytes[1]===255 ? 'utf-16be' : 'utf-8';
                const text = document.createElement('pre'); text.textContent = new TextDecoder(encoding).decode(buffer); content.append(text);
                if (file.size > 200000) status.textContent = 'Se muestran los primeros 200 KB. La descarga contiene el archivo completo.';
            } else {
                status.textContent = 'Este formato no tiene vista previa en el navegador. Descargá el archivo para abrirlo.';
            }
        } catch (error) {
            if (current !== requestId || error.name === 'AbortError') return;
            status.textContent = error.message || 'No se pudo cargar el adjunto.';
        }
    }
    document.getElementById('closeAttachmentPreview').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', clearPreview);
    document.querySelectorAll('[data-saved-attachment]').forEach(row => {
        const button = row.querySelector('[data-preview-attachment]');
        const remove = row.querySelector('[name="remove_attachments[]"]');
        function updateRemoval() {
            row.classList.toggle('is-removed', remove.checked);
            row.querySelector('[data-attachment-state]').textContent = remove.checked ? 'Se quitará al guardar' : 'Incluido';
            button.disabled = remove.checked;
        }
        remove.addEventListener('change', updateRemoval); updateRemoval();
        button.addEventListener('click', () => openPreview(button.dataset.name, async signal => {
            const response = await fetch(button.dataset.url, {signal, cache:'no-store', headers:{Accept:'application/octet-stream'}});
            if (response.redirected) throw new Error('La sesión o la sucursal cambió. Recargá la página.');
            if (!response.ok) {
                const error = await response.json().catch(() => ({}));
                throw new Error(error.error || 'No se pudo cargar el adjunto. Recargá la plantilla.');
            }
            return response.blob();
        }));
    });
    function renderSelected() {
        const list = document.getElementById('selectedAttachments'); list.replaceChildren();
        Array.from(input.files || []).forEach((file, index) => {
            const row = document.createElement('div'); row.className = 'attachment-row';
            const meta = document.createElement('div'); meta.className = 'attachment-meta';
            const title = document.createElement('strong'); title.textContent = file.name;
            const detail = document.createElement('span'); detail.className = 'hint'; detail.textContent = Math.ceil(file.size/1024) + ' KB · Sin guardar';
            meta.append(title, detail);
            const actions = document.createElement('div'); actions.className = 'attachment-actions';
            const preview = document.createElement('button'); preview.type = 'button'; preview.className = 'btn secondary small'; preview.textContent = 'Vista previa';
            preview.addEventListener('click', () => openPreview(file.name, async () => file));
            const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn secondary small'; remove.textContent = 'Quitar';
            remove.setAttribute('aria-label', 'Quitar ' + file.name);
            remove.addEventListener('click', () => {
                const transfer = new DataTransfer();
                Array.from(input.files).forEach((selected, position) => { if (position !== index) transfer.items.add(selected); });
                input.files = transfer.files;
                input.dispatchEvent(new Event('input', {bubbles:true}));
                input.dispatchEvent(new Event('change', {bubbles:true}));
            });
            actions.append(preview, remove); row.append(meta, actions); list.append(row);
        });
    }
    input.addEventListener('change', renderSelected); renderSelected();
    window.addEventListener('pagehide', clearPreview);
})();
