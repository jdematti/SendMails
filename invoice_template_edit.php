<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$draftId = max(0, (int) ($_POST['draft_id'] ?? query_string('draft', '0')));
$draftContext = null;
$draftKind = 'invoice_template';
$draftEditor = 'invoice_template_edit.php';
$id = normalize_int(query_string('id'), 0, 0);
$sourceId = $id > 0 ? 0 : normalize_int(query_string('source_id'), 0, 0);
$pageTitle = $id > 0 ? 'Editar plantilla de facturas' : 'Nueva plantilla de facturas';
$pageSubtitle = 'Editá el mensaje que acompaña a tus facturas.';
$error = '';
$previewEmail = post_string('preview_email');
$template = [
    'id' => null,
    'name' => '',
    'subject' => 'Factura Electronica',
    'html_body' => <<<'HTML'
<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Factura Electronica</title>
</head>
<body style="margin:0;padding:0;background:#eef2f7;font-family:Arial,sans-serif;color:#102033;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#eef2f7;padding:24px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #d9e2ec;">
          <tr>
            <td style="background:#0f172a;color:#ffffff;padding:24px 28px;text-align:center;">
              <h1 style="margin:0;font-size:24px;line-height:1.2;">Factura Electronica</h1>
              <p style="margin:8px 0 0;color:#cbd5e1;font-size:14px;">{{web}}</p>
            </td>
          </tr>
          <tr>
            <td style="padding:26px 28px;text-align:center;">
              <p style="margin:0 0 12px;font-size:17px;">Hola <strong>{{nombre}}</strong></p>
              <p style="margin:0 0 22px;color:#475569;font-size:15px;line-height:1.55;">Ya podes descargar tu factura desde el siguiente acceso.</p>
              <a href="{{url_factura}}" target="_blank" style="display:inline-block;background:#14b8a6;color:#ffffff;text-decoration:none;font-weight:bold;padding:13px 22px;border-radius:8px;">Descargar factura</a>
              <p style="margin:18px 0 0;color:#475569;font-size:14px;">Servicio {{snb}} | Vencimiento {{vencimiento}} | Importe {{importe}}</p>
            </td>
          </tr>
          <tr>
            <td style="padding:18px 28px;background:#0f172a;color:#cbd5e1;text-align:center;font-size:12px;line-height:1.5;">
              <p style="margin:0 0 6px;">{{domicilio}}</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML,
    'from_email' => '',
    'from_name' => '',
    'reply_to' => '',
    'bcc' => '',
    'web' => 'infracomcoopelectric.com.ar',
    'loc_prefix' => 'ola',
    'domicilio' => 'Belgrano 2800 - Olavarria',
    'facebook' => '',
    'instagram' => '',
    'whatsapp' => '',
    'test_mode' => 1,
    'test_email' => INITIAL_ADMIN_EMAIL,
    'is_active' => 1,
];
$sourceTemplates = [];
$sourceTemplateName = '';

try {
    Schema::ensure();
    if ($id > 0) {
        $found = InvoiceRepository::findTemplate($id);
        if (!$found) {
            throw new RuntimeException('Plantilla de facturas no encontrada.');
        }
        $template = $found;
    } else {
        $sourceTemplates = InvoiceRepository::templates();
        if ($sourceId > 0) {
            $sourceTemplate = InvoiceRepository::findTemplate($sourceId);
            if (!$sourceTemplate) {
                throw new RuntimeException('Plantilla base de facturas no encontrada.');
            }

            $sourceTemplateName = (string) $sourceTemplate['name'];
            $template = $sourceTemplate;
            $template['id'] = null;
            $template['name'] = 'Copia de ' . (string) $sourceTemplate['name'];
            $template['is_active'] = 1;
        }
    }
    $draftContext = TemplateDraft::load($draftKind, $draftId, $id, $template);
    $template = $draftContext['template'];
    $id = (int) $draftContext['source_id'];
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$requestMethod = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($requestMethod === 'POST') {
    verify_csrf();
    $template = [
        'id' => $id ?: null,
        'name' => post_string('name'),
        'subject' => post_string('subject'),
        'html_body' => (string) ($_POST['html_body'] ?? ''),
        'from_email' => post_string('from_email'),
        'from_name' => post_string('from_name'),
        'reply_to' => post_string('reply_to'),
        'bcc' => post_string('bcc'),
        'web' => post_string('web'),
        'loc_prefix' => post_string('loc_prefix'),
        'domicilio' => post_string('domicilio'),
        'facebook' => post_string('facebook'),
        'instagram' => post_string('instagram'),
        'whatsapp' => post_string('whatsapp'),
        'test_mode' => isset($_POST['test_mode']) ? 1 : 0,
        'test_email' => post_string('test_email'),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];

    try {
        if (!$draftContext) throw new RuntimeException('No se pudo cargar el borrador.');
        if (!$draftContext['id']) $draftContext['base_version'] = post_string('base_version');
        if (post_string('action') === 'save_copy') {
            $id = 0;
            $draftContext = TemplateDraft::load($draftKind, 0, 0, $template);
            TemplateDraft::store($draftContext, $template, 0);
        } else {
            TemplateDraft::store($draftContext, $template, (int) post_string('draft_revision'));
        }
        if (post_string('action') === 'save_draft') redirect('invoice_template_edit.php?draft=' . $draftContext['id']);
        if (post_string('action', 'save') === 'send_preview') {
            InvoiceMailerService::sendTemplatePreview($previewEmail, $template, $id > 0 ? $id : null);
            flash('success', 'Correo de prueba enviado correctamente a ' . $previewEmail . '.');
        } else {
            $savedId = TemplateDraft::publish($draftContext, static fn(): int => InvoiceRepository::saveTemplate($id > 0 ? $id : null, $template));
            flash('success', 'Plantilla de facturas guardada.');
            redirect('invoice_template_edit.php?id=' . $savedId);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$sample = InvoiceMailerService::previewContext($template);

require __DIR__ . '/app/layout/header.php';
?>

<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

<?php require __DIR__ . '/app/layout/template_drafts.php'; ?>
<p class="hint" data-editor-status><?= !empty($draftContext['id']) ? 'Borrador compartido #' . (int) $draftContext['id'] . ' · guardado' : 'Plantilla publicada / nuevo documento' ?></p>
<section class="split template-editor-layout">
    <div class="card template-editor-card">
        <form method="post" class="form-grid" data-wait-form>
            <?= csrf_field() ?>
            <input type="hidden" name="draft_id" value="<?= (int) ($draftContext['id'] ?? 0) ?>">
            <input type="hidden" name="draft_revision" value="<?= (int) ($draftContext['revision'] ?? 0) ?>">
            <input type="hidden" name="base_version" value="<?= e((string) ($draftContext['base_version'] ?? '')) ?>">
            <?php if ($id === 0): ?>
                <div class="field full template-source-field">
                    <label for="source_template_id">Crear desde plantilla existente</label>
                    <select id="source_template_id" data-template-source-select>
                        <option value="">Plantilla nueva</option>
                        <?php foreach ($sourceTemplates as $sourceTemplate): ?>
                            <option value="<?= e((string) $sourceTemplate['id']) ?>"<?= selected((string) $sourceTemplate['id'], (string) $sourceId) ?>>
                                <?= e((string) $sourceTemplate['name']) ?> - <?= e((string) $sourceTemplate['subject']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($sourceTemplateName !== ''): ?>
                        <p class="hint">Se cargo una copia de <?= e($sourceTemplateName) ?>. Al guardar se crea una plantilla nueva.</p>
                    <?php else: ?>
                        <p class="hint">Elegir una plantilla base copia remitente, configuracion y HTML para modificar solo lo necesario.</p>
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
            <details class="disclosure full"><summary>Remitente y respuestas</summary><div class="form-grid">
            <div class="field">
                <label for="from_email">Email remitente</label>
                <input id="from_email" name="from_email" type="email" value="<?= e($template['from_email']) ?>" placeholder="usa SMTP si queda vacio">
            </div>
            <div class="field">
                <label for="from_name">Nombre remitente</label>
                <input id="from_name" name="from_name" value="<?= e($template['from_name']) ?>">
            </div>
            <div class="field">
                <label for="reply_to">Responder a</label>
                <input id="reply_to" name="reply_to" type="email" value="<?= e($template['reply_to']) ?>">
            </div>
            <div class="field">
                <label for="bcc">Copia oculta (BCC)</label>
                <input id="bcc" name="bcc" type="email" value="<?= e($template['bcc']) ?>">
            </div>
            </div></details>
            <div class="field">
                <label for="web">Dominio web</label>
                <input id="web" name="web" value="<?= e($template['web']) ?>" required>
            </div>
            <div class="field">
                <label for="loc_prefix">Prefijo localidad</label>
                <input id="loc_prefix" name="loc_prefix" value="<?= e($template['loc_prefix']) ?>" required>
            </div>
            <details class="disclosure full"><summary>Datos de contacto y redes</summary><div class="form-grid">
            <div class="field full">
                <label for="domicilio">Domicilio</label>
                <input id="domicilio" name="domicilio" value="<?= e($template['domicilio']) ?>">
            </div>
            <div class="field">
                <label for="facebook">Facebook</label>
                <input id="facebook" name="facebook" value="<?= e($template['facebook']) ?>">
            </div>
            <div class="field">
                <label for="instagram">Instagram</label>
                <input id="instagram" name="instagram" value="<?= e($template['instagram']) ?>">
            </div>
            <div class="field">
                <label for="whatsapp">WhatsApp</label>
                <input id="whatsapp" name="whatsapp" value="<?= e($template['whatsapp']) ?>">
            </div>
            </div></details>
            <div class="field">
                <label for="test_email">Email modo test</label>
                <input id="test_email" name="test_email" type="email" value="<?= e($template['test_email']) ?>">
            </div>
            <div class="field full">
                <details class="disclosure"><summary>Personalizar con datos de la factura</summary>
                <p class="hint"><?= e('{{nombre}} {{email}} {{importe}} {{snb}} {{vencimiento}} {{url_factura}} {{web}} {{domicilio}} {{facebook}} {{instagram}} {{whatsapp}}') ?></p>
                </details>
            </div>
            <div class="field full">
                <details class="disclosure advanced-html-editor"><summary>Editar contenido HTML (avanzado)</summary>
                <label for="html_body">Contenido HTML</label>
                <textarea id="html_body" name="html_body" class="code" required><?= e($template['html_body']) ?></textarea>
                </details>
            </div>
            <div class="field full">
                <label class="hint"><input type="checkbox" name="test_mode" value="1"<?= checked((bool) $template['test_mode']) ?>> Modo prueba activo (solo email)</label>
                <label class="hint"><input type="checkbox" name="is_active" value="1"<?= checked((bool) $template['is_active']) ?>> Plantilla activa</label>
            </div>
            <div class="field full">
                <details class="disclosure"><summary>Enviar una prueba (opcional)</summary>
                <label for="preview_email">Email para prueba de plantilla</label>
                <div class="inline-test">
                    <input id="preview_email" name="preview_email" type="email" value="<?= e($previewEmail) ?>" placeholder="destino@dominio.com">
                    <button type="submit" name="action" value="send_preview">Enviar prueba</button>
                </div>
                <p class="hint">Envia el asunto, remitente y HTML actuales con datos de factura de ejemplo, sin guardar la plantilla.</p>
                </details>
            </div>
            <div class="field full actions editor-actions">
                <button type="submit" name="action" value="save_draft" class="btn secondary" formnovalidate>Guardar borrador</button>
                <button type="submit" name="action" value="save">Publicar plantilla</button>
                <?php if ($id > 0): ?><button type="submit" name="action" value="save_copy" class="btn secondary">Publicar como nueva</button><?php endif; ?>
                <a class="btn secondary" href="invoice_templates.php" data-wait>Volver</a>
            </div>
        </form>
    </div>
    <div class="card template-preview-card">
        <h2>Vista previa</h2>
        <iframe sandbox="" class="preview-frame" srcdoc="<?= e(InvoiceMailerService::render((string) $template['html_body'], $sample)) ?>"></iframe>
    </div>
</section>

<script>
(() => {
    const sourceSelect = document.querySelector('[data-template-source-select]');
    if (!sourceSelect) return;

    sourceSelect.addEventListener('change', () => {
        const value = sourceSelect.value || '';
        window.location.href = value === '' ? 'invoice_template_edit.php' : 'invoice_template_edit.php?source_id=' + encodeURIComponent(value);
    });
})();
</script>

<script src="assets/js/template-state.js" defer></script>
<?php require __DIR__ . '/app/layout/footer.php'; ?>
