<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$draftId = max(0, (int) ($_POST['draft_id'] ?? query_string('draft', '0')));
$draftContext = null;
$draftKind = 'template';
$draftEditor = 'template_edit.php';
$id = normalize_int(query_string('id'), 0, 0);
$sourceId = $id > 0 ? 0 : normalize_int(query_string('source_id'), 0, 0);
$template = [
    'id' => null,
    'name' => '',
    'subject' => '',
    'html_body' => '',
    'is_active' => 1,
];
$sourceTemplates = [];
$sourceTemplateName = '';
$error = '';
$previewEmail = post_string('preview_email');
$attachments = [];
$removeAttachments = [];
$templateLoaded = false;
$uploadNotice = '';

try {
    Schema::ensure();
    if ($id > 0) {
        $found = TemplateRepository::find($id);
        if (!$found) {
            throw new RuntimeException('Plantilla no encontrada.');
        }
        $template = $found;
    } else {
        $sourceTemplates = TemplateRepository::all(false);
        if ($sourceId > 0) {
            $sourceTemplate = TemplateRepository::find($sourceId);
            if (!$sourceTemplate) {
                throw new RuntimeException('Plantilla base no encontrada.');
            }

            $sourceTemplateName = (string) $sourceTemplate['name'];
            $template = [
                'id' => null,
                'name' => 'Copia de ' . (string) $sourceTemplate['name'],
                'subject' => (string) $sourceTemplate['subject'],
                'html_body' => (string) $sourceTemplate['html_body'],
                'is_active' => 1,
                'attachments_json' => $sourceTemplate['attachments_json'] ?? null,
            ];
        } else {
            $template['html_body'] = TemplateRepository::blankHtml();
        }
    }
    $draftContext = TemplateDraft::load($draftKind, $draftId, $id, $template);
    $template = $draftContext['template'];
    $id = (int) $draftContext['source_id'];
    $attachments = CampaignAttachments::fromJson($template['attachments_json'] ?? null);
    $templateLoaded = true;
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $error = 'No se recibio el formulario. La carga puede superar el limite del servidor (' . ini_get('post_max_size') . '). Vuelve a seleccionar archivos mas pequeños.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $template = [
        'id' => $id ?: null,
        'name' => post_string('name'),
        'subject' => post_string('subject'),
        'html_body' => (string) ($_POST['html_body'] ?? ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];
    $postedRemovals = $_POST['remove_attachments'] ?? [];
    $removeAttachments = array_values(array_filter(is_array($postedRemovals) ? $postedRemovals : [], 'is_string'));
    $uploads = $_FILES['attachments'] ?? [];
    if (is_array($uploads) && !empty($uploads['name']) && array_filter((array) $uploads['name'])) {
        $uploadNotice = '';
    }

    try {
        if (!$templateLoaded) {
            throw new RuntimeException('No se pudo cargar la plantilla. Recarga la pagina antes de guardar cambios.');
        }
        $selectedAttachments = CampaignAttachments::withUploads($attachments, is_array($uploads) ? $uploads : [], $removeAttachments);
        $attachments = $selectedAttachments;
        $removeAttachments = [];
        $template['attachments_json'] = CampaignAttachments::toJson($attachments);
        if (!$draftContext) throw new RuntimeException('No se pudo cargar el borrador.');
        if (!$draftContext['id']) $draftContext['base_version'] = post_string('base_version');
        if (post_string('action') === 'save_copy') {
            $id = 0;
            $draftContext = TemplateDraft::load($draftKind, 0, 0, $template);
            TemplateDraft::store($draftContext, $template, 0);
        } else {
            TemplateDraft::store($draftContext, $template, (int) post_string('draft_revision'));
        }
        $uploadNotice = 'Contenido y adjuntos guardados en el borrador compartido #' . $draftContext['id'] . '.';
        if (post_string('action') === 'save_draft') redirect('template_edit.php?draft=' . $draftContext['id']);
        if (post_string('action', 'save') === 'send_preview') {
            MailerService::sendTemplatePreview($previewEmail, (string) $template['subject'], (string) $template['html_body'], $id > 0 ? $id : null, $selectedAttachments);
            flash('success', 'Correo de prueba enviado correctamente a ' . $previewEmail . '.');
        } else {
            $savedId = TemplateDraft::publish($draftContext, static fn(): int => TemplateRepository::save(
                $id > 0 ? $id : null,
                (string) $template['name'],
                (string) $template['subject'],
                (string) $template['html_body'],
                (bool) $template['is_active'],
                $selectedAttachments
            ));
            flash('success', 'Plantilla guardada.');
            redirect('template_edit.php?id=' . $savedId);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = $id > 0 ? 'Editar plantilla' : 'Nueva plantilla';
$pageSubtitle = $id > 0 ? 'Edita el contenido de la plantilla y reemplaza imágenes sin tocar el HTML.' : 'Crea una plantilla en blanco o partiendo de una ya existente.';
require __DIR__ . '/app/layout/header.php';
?>

<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<?php if ($uploadNotice): ?><div class="alert warning"><?= e($uploadNotice) ?></div><?php endif; ?>

<?php require __DIR__ . '/app/layout/template_drafts.php'; ?>
<p class="hint" data-editor-status><?= !empty($draftContext['id']) ? 'Borrador compartido #' . (int) $draftContext['id'] . ' · guardado' : 'Plantilla publicada / nuevo documento' ?></p>
<section class="split template-editor-layout">
    <div class="card template-editor-card">
        <form method="post" enctype="multipart/form-data" class="form-grid" data-wait-form>
            <?= csrf_field() ?>
            <input type="hidden" name="draft_id" value="<?= (int) ($draftContext['id'] ?? 0) ?>">
            <input type="hidden" name="draft_revision" value="<?= (int) ($draftContext['revision'] ?? 0) ?>">
            <input type="hidden" name="base_version" value="<?= e((string) ($draftContext['base_version'] ?? '')) ?>">
            <?php if ($id === 0): ?>
                <div class="field full template-source-field">
                    <label for="source_template_id">Crear desde plantilla existente</label>
                    <select id="source_template_id" data-template-source-select>
                        <option value="">Plantilla en blanco</option>
                        <?php foreach ($sourceTemplates as $sourceTemplate): ?>
                            <option value="<?= e((string) $sourceTemplate['id']) ?>"<?= selected((string) $sourceTemplate['id'], (string) $sourceId) ?>>
                                <?= e($sourceTemplate['name']) ?> - <?= e($sourceTemplate['subject']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($sourceTemplateName !== ''): ?>
                        <p class="hint">Se cargo una copia de <?= e($sourceTemplateName) ?>. Al guardar se crea una plantilla nueva.</p>
                    <?php else: ?>
                        <p class="hint">Elegir una plantilla base copia nombre, asunto y HTML para modificar solo lo necesario.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="field">
                <label for="name">Nombre</label>
                <input id="name" name="name" value="<?= e($template['name']) ?>" required>
            </div>
            <div class="field">
                <label for="subject">Asunto</label>
                <input id="subject" name="subject" value="<?= e($template['subject']) ?>" required>
            </div>
            <div class="field full">
                <label>Variables disponibles</label>
                <p class="hint"><?= e(implode(' ', TEMPLATE_VARIABLES)) ?></p>
            </div>
            <div class="field full template-visual-builder" data-template-builder>
                <div class="builder-head">
                    <div>
                        <label>Editor visual</label>
                        <span class="hint">Armá la plantilla con campos simples; el HTML se genera automáticamente.</span>
                    </div>
                </div>
                <div class="visual-builder-grid">
                    <div class="field">
                        <label for="builder_title">Título principal</label>
                        <input id="builder_title" data-builder-field="title">
                    </div>
                    <div class="field">
                        <label for="builder_subtitle">Subtítulo</label>
                        <input id="builder_subtitle" data-builder-field="subtitle">
                    </div>
                    <div class="field full">
                        <label for="builder_header_kicker">Texto destacado superior</label>
                        <input id="builder_header_kicker" data-builder-field="headerKicker">
                    </div>
                    <div class="field full">
                        <label for="builder_intro">Texto inicial</label>
                        <textarea id="builder_intro" rows="3" data-builder-field="intro"></textarea>
                    </div>
                    <div class="field">
                        <label for="builder_image_label">Título de imágenes</label>
                        <input id="builder_image_label" data-builder-field="imageLabel">
                    </div>
                    <div class="field">
                        <label for="builder_layout">Distribución</label>
                        <select id="builder_layout" data-builder-field="layout">
                            <option value="auto">Automática</option>
                            <option value="1">Una por fila</option>
                            <option value="2">Dos columnas</option>
                            <option value="3">Tres columnas</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="builder_channels_lead">Banda canales: inicio</label>
                        <input id="builder_channels_lead" data-builder-field="channelsLead">
                    </div>
                    <div class="field">
                        <label for="builder_channels_connector">Banda canales: conector</label>
                        <input id="builder_channels_connector" data-builder-field="channelsConnector">
                    </div>
                    <div class="field">
                        <label for="builder_channels_count">Banda canales: número</label>
                        <input id="builder_channels_count" data-builder-field="channelsCount">
                    </div>
                    <div class="field">
                        <label for="builder_channels_label">Banda canales: etiqueta</label>
                        <input id="builder_channels_label" data-builder-field="channelsLabel">
                    </div>
                    <div class="field">
                        <label for="builder_channels_text">Banda canales: texto</label>
                        <input id="builder_channels_text" data-builder-field="channelsText">
                    </div>
                    <div class="field">
                        <label for="builder_divider_icon">Divisor</label>
                        <input id="builder_divider_icon" data-builder-field="dividerIcon">
                    </div>
                    <div class="field full">
                        <label for="builder_cta_eyebrow">Texto previo final</label>
                        <input id="builder_cta_eyebrow" data-builder-field="ctaEyebrow">
                    </div>
                    <div class="field">
                        <label for="builder_cta_title">Título final</label>
                        <input id="builder_cta_title" data-builder-field="ctaTitle">
                    </div>
                    <div class="field">
                        <label for="builder_button_text">Texto del botón</label>
                        <input id="builder_button_text" data-builder-field="buttonText">
                    </div>
                    <div class="field full">
                        <label for="builder_cta_text">Texto final</label>
                        <textarea id="builder_cta_text" rows="2" data-builder-field="ctaText"></textarea>
                    </div>
                    <div class="field full">
                        <label for="builder_button_url">Link del botón</label>
                        <input id="builder_button_url" data-builder-field="buttonUrl" placeholder="https://wa.me/...">
                    </div>
                    <div class="field full">
                        <label for="builder_footer">Pie del mail</label>
                        <input id="builder_footer" data-builder-field="footer">
                    </div>
                    <div class="field">
                        <label for="builder_footer_brand">Marca del pie</label>
                        <input id="builder_footer_brand" data-builder-field="footerBrand">
                    </div>
                    <div class="field">
                        <label for="builder_footer_subtitle">Subtítulo del pie</label>
                        <input id="builder_footer_subtitle" data-builder-field="footerSubtitle">
                    </div>
                </div>
                <div class="template-image-assistant" data-template-image-assistant>
                    <div class="template-image-head">
                        <div class="template-image-title">
                            <label>Imágenes</label>
                            <span class="hint" data-image-count>0 imágenes cargadas.</span>
                        </div>
                        <label class="btn secondary template-image-upload template-image-add">
                            Agregar imágenes
                            <input type="file" accept="image/*" multiple data-image-add>
                        </label>
                    </div>
                    <div class="template-image-list" data-image-list>
                        <div class="empty">No hay imágenes. Usá Agregar imágenes para cargar la primera.</div>
                    </div>
                </div>
            </div>
            <div class="field full" id="campaignAttachments">
                <label for="attachments">Archivos adjuntos</label>
                <p class="hint">Se adjuntan a cada email de campaña que use esta plantilla. Hasta 5 archivos y 10 MB en total. Limite del servidor por archivo: <?= e((string) ini_get('upload_max_filesize')) ?>.</p>
                <?php foreach ($attachments as $attachmentIndex => $attachment): ?>
                    <?php $attachmentUrl = 'attachment_preview.php?' . http_build_query([
                        'branch_id'=>(int) BranchRepository::currentId(), 'draft_id'=>(int) ($draftContext['id'] ?? 0),
                        'template_id'=>$id ?: $sourceId, 'index'=>$attachmentIndex,
                        'fingerprint'=>hash('sha256', $attachment['name'] . "\0" . $attachment['content']),
                    ]); ?>
                    <div class="attachment-row" data-saved-attachment>
                        <div class="attachment-meta"><strong><?= e($attachment['name']) ?></strong><span class="hint"><?= e(number_format($attachment['size'] / 1024, 1, ',', '.')) ?> KB · <span data-attachment-state>Incluido</span></span></div>
                        <div class="attachment-actions">
                            <button type="button" class="btn secondary small" data-preview-attachment data-url="<?= e($attachmentUrl) ?>" data-name="<?= e($attachment['name']) ?>">Vista previa</button>
                            <label class="hint"><input type="checkbox" name="remove_attachments[]" value="<?= $attachmentIndex ?>" data-attachment-size="<?= (int) $attachment['size'] ?>"<?= checked(in_array((string) $attachmentIndex, $removeAttachments, true)) ?>> Quitar al guardar</label>
                        </div>
                    </div>
                <?php endforeach; ?>
                <input id="attachments" name="attachments[]" type="file" multiple>
                <div id="selectedAttachments" aria-live="polite"></div>
                <p class="hint">Podés revisar los archivos antes de guardar. Enviar prueba incluye los adjuntos seleccionados. Publicar aplica los cambios a las campañas nuevas; los envíos ya confirmados conservan sus adjuntos.</p>
            </div>
            <div class="field full advanced-html-field">
                <details class="advanced-html-editor">
                    <summary>Editor HTML avanzado</summary>
                    <div class="advanced-html-body">
                        <label for="html_body">Código HTML</label>
                        <textarea id="html_body" name="html_body" class="code" data-html-editor required><?= e($template['html_body']) ?></textarea>
                        <p class="hint">Usalo solo si necesitás pegar o corregir el código.</p>
                    </div>
                </details>
            </div>
            <div class="field full">
                <label class="hint"><input type="checkbox" name="is_active" value="1"<?= checked((bool) $template['is_active']) ?>> Plantilla activa</label>
            </div>
            <div class="field full">
                <label for="preview_email">Email para prueba de plantilla</label>
                <div class="inline-test">
                    <input id="preview_email" name="preview_email" type="email" value="<?= e($previewEmail) ?>" placeholder="destino@dominio.com">
                    <button type="submit" name="action" value="send_preview">Enviar prueba</button>
                </div>
                <p class="hint">Envía el asunto, HTML y adjuntos actuales con datos de ejemplo, sin guardar la plantilla.</p>
            </div>
            <div class="field full actions">
                <button type="submit" name="action" value="save_draft" class="btn secondary" formnovalidate>Guardar borrador</button>
                <button type="submit" name="action" value="save">Publicar plantilla</button>
                <?php if ($id > 0): ?><button type="submit" name="action" value="save_copy" class="btn secondary">Publicar como nueva</button><?php endif; ?>
                <a class="btn secondary" href="templates.php" data-wait>Volver</a>
            </div>
        </form>
    </div>
    <div class="card template-preview-card">
        <h2>Vista previa</h2>
        <iframe sandbox="" class="preview-frame" data-template-preview srcdoc="<?= e(MailerService::renderCampaignHtml((string) $template['html_body'], [
            'codigo_cliente' => '0001',
            'razon_social' => 'Cliente de ejemplo',
            'plan_contratado' => 'Plan Premium',
            'plan' => 'Plan Premium',
            'email' => 'cliente@ejemplo.com',
            'telefono_movil' => '2284 000000',
            'localidad' => 'OLAVARRIA',
            'provincia' => 'BUENOS AIRES',
            'url_baja' => 'https://sendmails.local/unsubscribe.php?token=ejemplo',
            'unsubscribe_url' => 'https://sendmails.local/unsubscribe.php?token=ejemplo',
        ])) ?>"></iframe>
    </div>
</section>

<dialog id="attachmentDialog" class="attachment-dialog" aria-labelledby="attachmentTitle">
    <div class="attachment-dialog-head"><h2 id="attachmentTitle">Vista previa del adjunto</h2><button type="button" class="btn secondary" id="closeAttachmentPreview">Cerrar</button></div>
    <p id="attachmentName" class="attachment-filename"></p>
    <p id="attachmentPreviewStatus" role="status"></p>
    <div id="attachmentPreviewContent"></div>
    <div class="actions"><a id="downloadAttachment" class="btn secondary" hidden>Descargar archivo</a></div>
</dialog>
<script src="assets/js/attachment-preview.js" defer></script>

<script>
(() => {
    const input = document.getElementById('attachments');
    const saved = Array.from(document.querySelectorAll('[data-attachment-size]'));
    const validateAttachments = () => {
        const retained = saved.filter((checkbox) => !checkbox.checked);
        const uploaded = Array.from(input.files || []);
        const bytes = retained.reduce((total, checkbox) => total + Number(checkbox.dataset.attachmentSize), 0)
            + uploaded.reduce((total, file) => total + file.size, 0);
        input.setCustomValidity(retained.length + uploaded.length > 5
            ? 'La plantilla admite hasta 5 archivos adjuntos.'
            : bytes > 10 * 1024 * 1024 ? 'Los adjuntos no pueden superar 10 MB en total.' : '');
    };
    input.addEventListener('change', validateAttachments);
    saved.forEach((checkbox) => checkbox.addEventListener('change', validateAttachments));
})();
(() => {
    const editor = document.querySelector('[data-html-editor]');
    const sourceSelect = document.querySelector('[data-template-source-select]');
    const list = document.querySelector('[data-image-list]');
    const count = document.querySelector('[data-image-count]');
    const preview = document.querySelector('[data-template-preview]');
    const addInput = document.querySelector('[data-image-add]');
    const builderFields = Array.from(document.querySelectorAll('[data-builder-field]'));
    if (sourceSelect) {
        sourceSelect.addEventListener('change', () => {
            const value = sourceSelect.value || '';
            window.location.href = value === '' ? 'template_edit.php' : 'template_edit.php?source_id=' + encodeURIComponent(value);
        });
    }

    if (!editor || !list || !count || !preview || !window.DOMParser || !window.FileReader) return;

    const fields = {};
    builderFields.forEach((field) => {
        fields[field.getAttribute('data-builder-field')] = field;
    });

    const sampleValues = {
        codigo_cliente: '0001',
        razon_social: 'Cliente de ejemplo',
        plan_contratado: 'Plan Premium',
        plan: 'Plan Premium',
        email: 'cliente@ejemplo.com',
        telefono_movil: '2284 000000',
        localidad: 'OLAVARRIA',
        provincia: 'BUENOS AIRES',
        url_baja: 'https://sendmails.local/unsubscribe.php?token=ejemplo',
        unsubscribe_url: 'https://sendmails.local/unsubscribe.php?token=ejemplo'
    };

    const defaults = {
        title: 'ESPACIAL',
        subtitle: 'La Fibra Óptica de INFRACOM',
        headerKicker: '✦ TE OFRECE CONTENIDO PREMIUM ✦',
        intro: 'Con la fibra óptica de Infracom disfrutás la mejor señal para ver los eventos más grandes del deporte y el entretenimiento. ¡Mirá lo que está llegando!',
        imageLabel: '📺 PRÓXIMOS EVENTOS',
        layout: 'auto',
        channelsLead: 'ADEMÁS',
        channelsConnector: 'DE LOS',
        channelsCount: '75',
        channelsLabel: 'CANALES',
        channelsText: 'incluidos en tu paquete',
        dividerIcon: '⚡',
        ctaEyebrow: '¿Todavía no lo tenés?',
        ctaTitle: '¡SOLICITÁ TU CONEXIÓN HOY!',
        ctaText: 'Pedilo por este medio o escribinos por WhatsApp:',
        buttonText: '📱  WhatsApp: 2284 599523',
        buttonUrl: 'https://wa.me/5492284599523',
        footer: '© 2026 Infracom. Todos los derechos reservados.',
        footerBrand: 'ESPACIAL',
        footerSubtitle: 'La Fibra Óptica de Infracom'
    };

    let visualImages = [];
    let usingGeneratedTemplate = false;

    const escapeRegExp = (value) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const isFullDocument = (html) => /<!doctype|<html[\s>]/i.test(html);
    const normalizeText = (value) => (value || '').replace(/\s+/g, ' ').trim();
    const escapeHtml = (value) => String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    const lineBreaks = (value) => escapeHtml(value).replace(/\r?\n/g, '<br>');
    const shortSource = (source) => {
        if (!source) return 'Sin origen';
        if (source.startsWith('data:image/')) return 'Imagen cargada en la plantilla';
        const clean = source.split('?')[0].split('#')[0];
        return clean.length > 82 ? clean.slice(0, 38) + '...' + clean.slice(-34) : clean;
    };

    const renderVariables = (html) => {
        const hasUnsubscribePlaceholder = /{{\s*(url_baja|unsubscribe_url)\s*}}/i.test(html);
        let output = html;
        Object.entries(sampleValues).forEach(([key, value]) => {
            output = output.replace(new RegExp('{{\\s*' + escapeRegExp(key) + '\\s*}}', 'gi'), value);
        });
        if (!hasUnsubscribePlaceholder) {
            const footer = '<div style="margin:24px auto 0;padding:16px 12px;text-align:center;font-family:Arial,sans-serif;font-size:12px;line-height:1.5;color:#64748b;">'
                + 'Si no queres recibir mas estos correos, '
                + '<a href="' + escapeHtml(sampleValues.url_baja) + '" target="_blank" style="color:#0f766e;text-decoration:underline;">cancela tu suscripcion</a>.'
                + '</div>';
            output = /<\/body>/i.test(output) ? output.replace(/<\/body>/i, footer + '</body>') : output + footer;
        }
        return output;
    };

    const fieldValue = (name) => fields[name] ? fields[name].value.trim() : '';
    const setFieldValue = (name, value) => {
        if (fields[name]) fields[name].value = value || '';
    };

    const parseEditor = () => {
        const html = editor.value || '';
        return {
            doc: new DOMParser().parseFromString(html, 'text/html'),
            full: isFullDocument(html)
        };
    };

    const readGeneratedField = (doc, name) => normalizeText(doc.querySelector('[data-sm-field="' + name + '"]')?.textContent || '');
    const firstLink = (doc) => doc.querySelector('a[href]');
    const paragraphTexts = (doc) => Array.from(doc.querySelectorAll('p'))
        .map((node) => normalizeText(node.textContent || ''))
        .filter(Boolean);

    const guessFields = (doc) => {
        const paragraphs = paragraphTexts(doc);
        const link = firstLink(doc);
        const findText = (matcher) => paragraphs.find((text) => matcher.test(text)) || '';
        const generated = !!doc.querySelector('[data-sm-builder]');

        if (generated) {
            return {
                title: readGeneratedField(doc, 'title') || defaults.title,
                subtitle: readGeneratedField(doc, 'subtitle') || defaults.subtitle,
                headerKicker: readGeneratedField(doc, 'headerKicker') || defaults.headerKicker,
                intro: readGeneratedField(doc, 'intro') || defaults.intro,
                imageLabel: readGeneratedField(doc, 'imageLabel') || defaults.imageLabel,
                layout: doc.querySelector('[data-sm-images]')?.getAttribute('data-sm-layout') || defaults.layout,
                channelsLead: readGeneratedField(doc, 'channelsLead') || defaults.channelsLead,
                channelsConnector: readGeneratedField(doc, 'channelsConnector') || defaults.channelsConnector,
                channelsCount: readGeneratedField(doc, 'channelsCount') || defaults.channelsCount,
                channelsLabel: readGeneratedField(doc, 'channelsLabel') || defaults.channelsLabel,
                channelsText: readGeneratedField(doc, 'channelsText') || defaults.channelsText,
                dividerIcon: readGeneratedField(doc, 'dividerIcon') || defaults.dividerIcon,
                ctaEyebrow: readGeneratedField(doc, 'ctaEyebrow') || defaults.ctaEyebrow,
                ctaTitle: readGeneratedField(doc, 'ctaTitle') || defaults.ctaTitle,
                ctaText: readGeneratedField(doc, 'ctaText') || defaults.ctaText,
                buttonText: readGeneratedField(doc, 'buttonText') || normalizeText(link?.textContent || '') || defaults.buttonText,
                buttonUrl: link?.getAttribute('href') || defaults.buttonUrl,
                footer: readGeneratedField(doc, 'footer') || defaults.footer,
                footerBrand: readGeneratedField(doc, 'footerBrand') || defaults.footerBrand,
                footerSubtitle: readGeneratedField(doc, 'footerSubtitle') || defaults.footerSubtitle
            };
        }

        const intro = paragraphs.find((text) => text.length > 55 && !/derechos|reservados/i.test(text)) || '';
        const channelsLine = findText(/canales/i);
        const channelsNumber = (channelsLine.match(/\b\d+\b/) || [''])[0];
        return {
            title: paragraphs[0] || defaults.title,
            subtitle: findText(/fibra|infracom|premium/i) || paragraphs[1] || defaults.subtitle,
            headerKicker: findText(/contenido premium|ofrece/i) || defaults.headerKicker,
            intro: intro || defaults.intro,
            imageLabel: findText(/eventos|imagenes|imágenes|promos|contenido/i) || defaults.imageLabel,
            layout: defaults.layout,
            channelsLead: channelsLine ? channelsLine.split(/\s+/)[0] : defaults.channelsLead,
            channelsConnector: defaults.channelsConnector,
            channelsCount: channelsNumber || defaults.channelsCount,
            channelsLabel: channelsLine && /canales/i.test(channelsLine) ? 'CANALES' : defaults.channelsLabel,
            channelsText: findText(/paquete|incluidos/i) || defaults.channelsText,
            dividerIcon: defaults.dividerIcon,
            ctaEyebrow: findText(/todav|tenés|tenes/i) || defaults.ctaEyebrow,
            ctaTitle: findText(/solicit|conexi|contact|ped/i) || defaults.ctaTitle,
            ctaText: findText(/whatsapp|medio|escrib/i) || defaults.ctaText,
            buttonText: normalizeText(link?.textContent || '') || defaults.buttonText,
            buttonUrl: link?.getAttribute('href') || defaults.buttonUrl,
            footer: paragraphs.slice().reverse().find((text) => /©|derechos|reservados/i.test(text)) || defaults.footer,
            footerBrand: paragraphs.slice().reverse().find((text) => /^espacial$/i.test(text)) || defaults.footerBrand,
            footerSubtitle: paragraphs.slice().reverse().find((text) => /fibra|infracom/i.test(text)) || defaults.footerSubtitle
        };
    };

    const serializeEditor = (doc, full) => {
        if (full) {
            const doctype = doc.doctype ? '<!DOCTYPE ' + doc.doctype.name + '>\n' : '';
            return doctype + doc.documentElement.outerHTML;
        }
        return doc.body.innerHTML;
    };

    const collectImages = (doc) => {
        const imageNodes = doc.querySelectorAll('[data-sm-image][src]').length > 0
            ? doc.querySelectorAll('[data-sm-image][src]')
            : doc.querySelectorAll('img[src]');
        const images = Array.from(imageNodes).map((node, index) => ({
            node,
            attr: 'src',
            src: node.getAttribute('src') || '',
            alt: node.getAttribute('alt') || 'Imagen ' + (index + 1),
            width: parseInt(node.getAttribute('data-sm-width') || '0', 10) || 0,
            height: parseInt(node.getAttribute('data-sm-height') || '0', 10) || 0
        }));
        doc.querySelectorAll('[background]').forEach((node) => {
            if (node.tagName.toLowerCase() !== 'img') images.push({ node, attr: 'background' });
        });
        return images;
    };

    const syncPreview = () => {
        preview.setAttribute('srcdoc', renderVariables(editor.value || ''));
    };

    const isLandscape = (image) => !image.width || !image.height || image.width >= image.height * 1.18;
    const imageRows = () => {
        const layout = fieldValue('layout') || defaults.layout;
        const rows = [];
        if (layout !== 'auto') {
            const columns = Math.max(1, Math.min(parseInt(layout, 10) || 1, 3));
            for (let index = 0; index < visualImages.length; index += columns) {
                rows.push(visualImages.slice(index, index + columns));
            }
            return rows;
        }

        let index = 0;
        while (index < visualImages.length) {
            const current = visualImages[index];
            if (isLandscape(current)) {
                rows.push([current]);
                index++;
                continue;
            }

            const row = [current];
            index++;
            while (index < visualImages.length && row.length < 2 && !isLandscape(visualImages[index])) {
                row.push(visualImages[index]);
                index++;
            }
            rows.push(row);
        }
        return rows;
    };

    const buildImageRows = () => imageRows().map((row) => {
        const width = Math.floor(100 / row.length);
        const cells = row.map((image) => {
            const displayWidth = row.length === 1 ? '560' : row.length === 2 ? '262' : '170';
            return '<td class="sm-col" width="' + width + '%" align="center" style="padding:8px;vertical-align:top;text-align:center;">'
                + '<img data-sm-image="1" data-sm-width="' + String(image.width || '') + '" data-sm-height="' + String(image.height || '') + '" src="' + escapeHtml(image.src) + '" alt="' + escapeHtml(image.alt || 'Imagen de campaña') + '" width="' + displayWidth + '" style="max-width:100%;width:100%;height:auto;display:block;margin:0 auto;border-radius:12px;border:1px solid #1d3658;">'
                + '</td>';
        }).join('');
        return '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>' + cells + '</tr></table>';
    }).join('');

    const block = (html, padding, extraStyle) => html
        ? '<tr><td style="' + padding + ';text-align:center;' + (extraStyle || '') + '">' + html + '</td></tr>'
        : '';

    const buildVisualHtml = () => {
        const title = fieldValue('title');
        const subtitle = fieldValue('subtitle');
        const headerKicker = fieldValue('headerKicker');
        const intro = fieldValue('intro');
        const imageLabel = fieldValue('imageLabel');
        const channelsLead = fieldValue('channelsLead');
        const channelsConnector = fieldValue('channelsConnector');
        const channelsCount = fieldValue('channelsCount');
        const channelsLabel = fieldValue('channelsLabel');
        const channelsText = fieldValue('channelsText');
        const dividerIcon = fieldValue('dividerIcon');
        const ctaEyebrow = fieldValue('ctaEyebrow');
        const ctaTitle = fieldValue('ctaTitle');
        const ctaText = fieldValue('ctaText');
        const buttonText = fieldValue('buttonText');
        const buttonUrl = fieldValue('buttonUrl');
        const footer = fieldValue('footer');
        const footerBrand = fieldValue('footerBrand');
        const footerSubtitle = fieldValue('footerSubtitle');
        const layout = fieldValue('layout') || defaults.layout;
        const documentTitle = fieldValue('title') || fieldValue('subtitle') || 'Plantilla';
        const hasChannels = channelsLead || channelsConnector || channelsCount || channelsLabel || channelsText;
        const buttonHtml = buttonText && buttonUrl
            ? '<a data-sm-field="buttonText" href="' + escapeHtml(buttonUrl) + '" target="_blank" style="display:inline-block;background:#25D366;color:#ffffff;font-family:Arial,sans-serif;font-size:19px;font-weight:bold;text-decoration:none;padding:14px 34px;border-radius:50px;">' + escapeHtml(buttonText) + '</a>'
            : '';
        const channelsHtml = hasChannels
            ? '<tr><td style="padding:0 20px 10px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#071830;border-radius:12px;border:1px solid rgba(0,200,255,0.3);"><tr><td style="padding:20px;text-align:center;">'
                + '<p style="margin:0 0 4px;font-family:Impact,Arial,sans-serif;font-size:42px;letter-spacing:2px;line-height:1;color:#ffffff;">'
                + (channelsLead ? '<span data-sm-field="channelsLead" style="color:#00c8ff;">' + escapeHtml(channelsLead) + '</span>' : '')
                + (channelsConnector ? ' <span data-sm-field="channelsConnector">' + escapeHtml(channelsConnector) + '</span>' : '')
                + (channelsCount ? ' <span data-sm-field="channelsCount" style="color:#00c8ff;">' + escapeHtml(channelsCount) + '</span>' : '')
                + (channelsLabel ? ' <span data-sm-field="channelsLabel">' + escapeHtml(channelsLabel) + '</span>' : '')
                + '</p>'
                + (channelsText ? '<p data-sm-field="channelsText" style="margin:6px 0 0;font-family:Arial,sans-serif;font-size:12px;color:#5577aa;letter-spacing:2px;text-transform:uppercase;">' + escapeHtml(channelsText) + '</p>' : '')
                + '</td></tr></table></td></tr>'
            : '';
        const dividerHtml = dividerIcon
            ? '<tr><td style="padding:18px 40px;text-align:center;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td style="border-top:1px solid rgba(0,200,255,0.2);"></td><td data-sm-field="dividerIcon" style="width:40px;text-align:center;padding:0 10px;font-size:18px;color:#00c8ff;">' + escapeHtml(dividerIcon) + '</td><td style="border-top:1px solid rgba(0,200,255,0.2);"></td></tr></table></td></tr>'
            : '';

        return '<!DOCTYPE html>'
            + '<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta http-equiv="X-UA-Compatible" content="IE=edge">'
            + '<title>' + escapeHtml(documentTitle) + '</title>'
            + '<style>@media only screen and (max-width:620px){.sm-container{width:100%!important}.sm-col{display:block!important;width:100%!important}.sm-col img{width:100%!important;height:auto!important}}</style>'
            + '</head><body style="margin:0;padding:0;background:#0a0a1a;font-family:Arial,sans-serif;">'
            + '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#0a0a1a;"><tr><td align="center" style="padding:20px 10px;">'
            + '<table role="presentation" class="sm-container" data-sm-builder="1" width="600" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;width:100%;background:#0d0d2b;border-radius:16px;overflow:hidden;">'
            + '<tr><td style="background:linear-gradient(135deg,#0d0d2b 0%,#0a1a3a 100%);padding:32px 30px 24px;text-align:center;border-bottom:3px solid #00c8ff;">'
            + (title ? '<p data-sm-field="title" style="margin:0 0 4px;font-family:Impact,Arial,sans-serif;font-size:52px;letter-spacing:6px;color:#00c8ff;line-height:1;">' + escapeHtml(title) + '</p>' : '')
            + (subtitle ? '<p data-sm-field="subtitle" style="margin:0 0 14px;font-family:Arial,sans-serif;font-size:11px;letter-spacing:3px;color:#8899bb;text-transform:uppercase;">' + escapeHtml(subtitle) + '</p>' : '')
            + (headerKicker ? '<p data-sm-field="headerKicker" style="margin:0;font-family:Arial,sans-serif;font-size:16px;font-weight:bold;color:#ffffff;letter-spacing:1px;">' + escapeHtml(headerKicker) + '</p>' : '')
            + '</td></tr>'
            + block(intro ? '<p data-sm-field="intro" style="margin:0;font-family:Arial,sans-serif;font-size:15px;color:#aabbdd;line-height:1.7;">' + lineBreaks(intro) + '</p>' : '', 'padding:28px 40px 10px')
            + block(imageLabel ? '<p data-sm-field="imageLabel" style="margin:0;font-family:Impact,Arial,sans-serif;font-size:22px;letter-spacing:5px;color:#00c8ff;">' + escapeHtml(imageLabel) + '</p>' : '', 'padding:22px 40px 14px')
            + '<tr><td data-sm-images="1" data-sm-layout="' + escapeHtml(layout) + '" style="padding:0 20px 24px;text-align:center;">' + buildImageRows() + '</td></tr>'
            + channelsHtml
            + dividerHtml
            + ((ctaEyebrow || ctaTitle || ctaText || buttonHtml) ? '<tr><td style="padding:0 20px 30px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#071428;border-radius:14px;border:2px solid rgba(0,200,255,0.4);"><tr><td style="padding:26px 28px;text-align:center;">'
                + (ctaEyebrow ? '<p data-sm-field="ctaEyebrow" style="margin:0 0 6px;font-family:Arial,sans-serif;font-size:11px;letter-spacing:4px;color:#00c8ff;text-transform:uppercase;font-weight:bold;">' + escapeHtml(ctaEyebrow) + '</p>' : '')
                + (ctaTitle ? '<p data-sm-field="ctaTitle" style="margin:0 0 8px;font-family:Impact,Arial,sans-serif;font-size:28px;letter-spacing:2px;color:#ffffff;">' + escapeHtml(ctaTitle) + '</p>' : '')
                + (ctaText ? '<p data-sm-field="ctaText" style="margin:0 0 20px;font-family:Arial,sans-serif;font-size:14px;color:#aabbdd;line-height:1.6;">' + lineBreaks(ctaText) + '</p>' : '')
                + buttonHtml
                + '</td></tr></table></td></tr>' : '')
            + ((footer || footerBrand || footerSubtitle) ? '<tr><td style="background:#070714;padding:20px 30px;text-align:center;border-top:1px solid rgba(0,200,255,0.15);">'
                + (footer ? '<p data-sm-field="footer" style="margin:0 0 10px;font-family:Arial,sans-serif;font-size:12px;color:#556688;">' + escapeHtml(footer) + '</p>' : '')
                + (footerBrand ? '<p data-sm-field="footerBrand" style="margin:0 0 5px;font-family:Impact,Arial,sans-serif;font-size:20px;letter-spacing:5px;color:#00c8ff;">' + escapeHtml(footerBrand) + '</p>' : '')
                + (footerSubtitle ? '<p data-sm-field="footerSubtitle" style="margin:0 0 8px;font-family:Arial,sans-serif;font-size:11px;color:#445566;letter-spacing:2px;text-transform:uppercase;">' + escapeHtml(footerSubtitle) + '</p>' : '')
                + '</td></tr>' : '')
            + '</table></td></tr></table></body></html>';
    };

    const generateVisualTemplate = () => {
        usingGeneratedTemplate = true;
        editor.value = buildVisualHtml();
        syncPreview();
        renderImageList();
    };

    const loadImageMeta = (src, fallback, onReady) => {
        const image = new Image();
        image.onload = () => onReady({
            src,
            alt: fallback.alt || 'Imagen de campaña',
            width: image.naturalWidth || fallback.width || 0,
            height: image.naturalHeight || fallback.height || 0
        });
        image.onerror = () => onReady({
            src,
            alt: fallback.alt || 'Imagen de campaña',
            width: fallback.width || 0,
            height: fallback.height || 0
        });
        image.src = src;
    };

    const readImageFile = (file, onReady) => {
        if (!file || !file.type || !file.type.startsWith('image/')) {
            window.alert('Seleccioná un archivo de imagen válido.');
            return false;
        }
        if (file.size > 4 * 1024 * 1024) {
            window.alert('La imagen no debe superar los 4 MB.');
            return false;
        }

        const reader = new FileReader();
        reader.onload = () => {
            if (typeof reader.result !== 'string') return;
            loadImageMeta(reader.result, { alt: file.name }, onReady);
        };
        reader.readAsDataURL(file);
        return true;
    };

    const appendImage = (image) => {
        visualImages.push(image);
        generateVisualTemplate();
    };

    const replaceImage = (index, file) => {
        readImageFile(file, (image) => {
            if (!visualImages[index]) return;
            visualImages[index] = image;
            generateVisualTemplate();
        });
    };

    const moveImage = (index, direction) => {
        const target = index + direction;
        if (target < 0 || target >= visualImages.length) return;
        const current = visualImages[index];
        visualImages[index] = visualImages[target];
        visualImages[target] = current;
        generateVisualTemplate();
    };

    const removeImage = (index) => {
        visualImages.splice(index, 1);
        generateVisualTemplate();
    };

    const renderImageList = () => {
        const images = visualImages;
        count.textContent = images.length === 1 ? '1 imagen cargada.' : images.length + ' imágenes cargadas.';
        list.innerHTML = '';

        if (images.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'empty';
            empty.textContent = 'No hay imágenes. Usá Agregar imágenes para cargar la primera.';
            list.appendChild(empty);
            return;
        }

        images.forEach((image, index) => {
            const row = document.createElement('div');
            row.className = 'template-image-row';

            const thumbWrap = document.createElement('div');
            thumbWrap.className = 'template-image-thumb';
            const thumb = document.createElement('img');
            thumb.alt = 'Imagen ' + (index + 1);
            thumb.src = image.src;
            thumb.addEventListener('error', () => {
                thumb.removeAttribute('src');
                thumbWrap.classList.add('empty-thumb');
            });
            thumbWrap.appendChild(thumb);

            const meta = document.createElement('div');
            meta.className = 'template-image-meta';
            const title = document.createElement('strong');
            title.textContent = 'Imagen ' + (index + 1);
            const detail = document.createElement('span');
            detail.textContent = image.alt || shortSource(image.src);
            meta.append(title, detail);

            const actions = document.createElement('div');
            actions.className = 'template-image-actions';

            const upload = document.createElement('label');
            upload.className = 'btn secondary small template-image-upload';
            upload.textContent = 'Cambiar';
            const input = document.createElement('input');
            input.type = 'file';
            input.accept = 'image/*';
            input.addEventListener('change', () => replaceImage(index, input.files && input.files[0]));
            upload.appendChild(input);

            const up = document.createElement('button');
            up.type = 'button';
            up.className = 'btn secondary small';
            up.textContent = 'Subir';
            up.disabled = index === 0;
            up.addEventListener('click', () => moveImage(index, -1));

            const down = document.createElement('button');
            down.type = 'button';
            down.className = 'btn secondary small';
            down.textContent = 'Bajar';
            down.disabled = index === images.length - 1;
            down.addEventListener('click', () => moveImage(index, 1));

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn danger small template-image-remove';
            remove.textContent = 'Quitar';
            remove.addEventListener('click', () => removeImage(index));

            actions.append(upload, up, down, remove);
            row.append(thumbWrap, meta, actions);
            list.appendChild(row);
        });
    };

    const appendFiles = (files, index) => {
        if (index >= files.length) return;
        const started = readImageFile(files[index], (source) => {
            appendImage(source);
            appendFiles(files, index + 1);
        });
        if (!started) appendFiles(files, index + 1);
    };

    if (addInput) {
        addInput.addEventListener('change', () => {
            const files = Array.from(addInput.files || []);
            addInput.value = '';
            appendFiles(files, 0);
        });
    }

    builderFields.forEach((field) => {
        field.addEventListener('input', generateVisualTemplate);
        field.addEventListener('change', generateVisualTemplate);
    });

    let timer = 0;
    editor.addEventListener('input', () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(() => {
            usingGeneratedTemplate = false;
            const parsed = parseEditor();
            visualImages = collectImages(parsed.doc).map((image, index) => ({
                src: image.src || image.node?.getAttribute(image.attr) || '',
                alt: image.alt || 'Imagen ' + (index + 1),
                width: image.width || 0,
                height: image.height || 0
            })).filter((image) => image.src);
            syncPreview();
            renderImageList();
        }, 250);
    });

    const initialParsed = parseEditor();
    const initialFields = guessFields(initialParsed.doc);
    Object.keys(defaults).forEach((name) => setFieldValue(name, initialFields[name] || defaults[name]));
    visualImages = collectImages(initialParsed.doc).map((image, index) => ({
        src: image.src || image.node?.getAttribute(image.attr) || '',
        alt: image.alt || 'Imagen ' + (index + 1),
        width: image.width || 0,
        height: image.height || 0
    })).filter((image) => image.src);

    usingGeneratedTemplate = (editor.value || '').trim() === '' || !!initialParsed.doc.querySelector('[data-sm-builder]');
    if (usingGeneratedTemplate) {
        generateVisualTemplate();
    } else {
        syncPreview();
        renderImageList();
    }
})();
</script>

<script src="assets/js/template-state.js" defer></script>
<?php require __DIR__ . '/app/layout/footer.php'; ?>
