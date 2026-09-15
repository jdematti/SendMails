<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
$composeKind = $composeKind ?? 'campaign';
$isInvoice = $composeKind === 'invoice';
$pageTitle = $isInvoice ? 'Preparar facturas' : 'Crear campaña';
$pageSubtitle = 'Seleccioná destinatarios, revisá el mensaje y confirmá el envío.';
$templates = $isInvoice ? InvoiceRepository::templates(true) : TemplateRepository::all(true);
$templates = array_values(array_filter($templates, static fn(array $t): bool => !empty($t['is_active'])));
$waTemplates = WhatsAppRepository::readyTemplates($composeKind);
$plans = $isInvoice ? [] : ClientRepository::plans();
$dueDates = $isInvoice ? InvoiceRepository::recentDueDates(12) : [];
$drafts = DraftRepository::all($composeKind);
$initial = ['kind' => $composeKind, 'csrf' => csrf_token(), 'branch' => BranchRepository::currentId(),
    'user' => (int) Auth::currentUser()['id'], 'draft' => (int) query_string('draft', '0'),
    'template' => (int) query_string('template_id', (string) ($templates[0]['id'] ?? 0)), 'now' => date('Y-m-d\TH:i')];
require __DIR__ . '/app/layout/header.php';
?>
<div id="composeNotice" role="status" aria-live="polite" hidden></div>
<section class="card">
    <div class="actions compose-top">
        <button type="button" id="saveDraft" class="btn secondary">Guardar borrador</button>
        <span id="saveStatus" class="hint" role="status">Sin guardar</span>
        <details class="compose-drafts disclosure"><summary>Continuar un borrador</summary><label>Borradores de esta sucursal
            <select id="loadDraft"><option value="">Continuar un borrador…</option>
                <?php foreach ($drafts as $draft): ?>
                    <option value="<?= (int) $draft['id'] ?>"><?= e($draft['name'] . ' · ' . $draft['editor'] . ' · ' . $draft['updated_at']) ?></option>
                <?php endforeach; ?>
            </select>
        </label></details>
    </div>
    <nav class="compose-steps" aria-label="Preparar envío">
        <button type="button" data-step="1" aria-current="step">1. Destinatarios</button>
        <button type="button" data-step="2">2. Mensaje</button>
        <button type="button" id="reviewTop">3. Revisar</button>
    </nav>
    <form id="composeForm">
        <section data-panel="1">
            <p class="step-intro"><?= $isInvoice ? 'Elegí el vencimiento y buscá las facturas que querés enviar.' : 'Buscá clientes y marcá los destinatarios de tu campaña.' ?></p>
            <div class="form-grid recipient-filters">
                <div class="field"><label for="channel">Canal</label><select id="channel" name="channel"><option value="email">Email</option><option value="whatsapp">WhatsApp</option><option value="both">Email + WhatsApp</option></select></div>
                <?php if (!$isInvoice): ?>
                    <div class="field"><label for="q">Buscar clientes</label><input type="search" id="q" name="q" placeholder="Nombre, código, email o teléfono"></div>
                <?php else: ?>
                    <div class="field"><label for="due_date">Vencimiento</label><input id="due_date" name="due_date" type="date" list="dueDates"><datalist id="dueDates"><?php foreach ($dueDates as $date): ?><option value="<?= e($date['due_date']) ?>"><?php endforeach; ?></datalist></div>
                <?php endif; ?>
            </div>
            <details class="disclosure extra-filters" id="extraFilters"><summary>Más filtros <span id="extraFilterCount" class="hint"></span></summary>
                <?php if (!$isInvoice): ?>
                    <p class="hint">Elegí uno o varios planes. Sin marcar: todos los planes.</p>
                    <div id="plans" class="plan-options" role="group" aria-label="Planes"><?php foreach ($plans as $plan): ?><label><input type="checkbox" name="plans[]" value="<?= e($plan) ?>"> <?= e($plan) ?></label><?php endforeach; ?></div>
                <?php else: ?>
                    <div class="form-grid">
                    <div class="field"><label for="status">Estado</label><select id="status" name="status"><option value="">Todos</option><option value="I">Impagas</option><option value="P">Pagas</option></select></div>
                    <div class="field"><label for="snb">SNB</label><input id="snb" name="snb"></div>
                    <div class="field"><label for="client_name">Nombre</label><input id="client_name" name="client_name"></div>
                    <div class="field"><label for="email">Email</label><input id="email" name="email"></div>
                    <div class="field"><label for="phone">Teléfono</label><input id="phone" name="phone"></div>
                    <div class="field"><label><input type="checkbox" name="include_sent"> Incluir facturas con email ya enviado</label></div>
                    </div>
                <?php endif; ?>
            </details>
            <div class="actions search-actions">
                <button type="button" id="searchRows">Buscar</button><button type="button" class="btn secondary" id="clearFilters">Limpiar filtros</button>
                <?php if ($isInvoice): ?><button type="button" class="btn secondary" id="exportInvoices">Exportar resultados</button><?php endif; ?>
            </div>
            <div class="selection-toolbar">
                <strong id="selectionCount" aria-live="polite">0 seleccionados</strong>
                <button type="button" class="btn secondary" id="selectPage">Esta página</button>
                <button type="button" class="btn secondary" id="selectAll">Todos los resultados</button>
                <button type="button" class="btn secondary" id="clearSelection">Quitar selección</button>
            </div>
            <div class="table-wrap"><table><thead><tr><th>Seleccionar</th><th>Cliente</th><th><?= $isInvoice ? 'Factura / importe' : 'Código / plan' ?></th><th>Email</th><th>WhatsApp</th></tr></thead><tbody id="recipientRows"></tbody></table></div>
            <div class="pagination"><button type="button" class="btn secondary" id="previousPage">Anterior</button><span id="pageInfo" role="status"></span><button type="button" class="btn secondary" id="nextPage">Siguiente</button></div>
            <?php if (!$isInvoice): ?><details class="disclosure manual-recipients" id="manualRecipients" data-email-field><summary>Agregar emails fuera de la lista</summary><div class="field"><label for="manual_emails">Emails adicionales</label><textarea id="manual_emails" name="manual_emails" rows="2" placeholder="Separalos con coma, espacio o salto de línea."></textarea><span class="hint">También podés enviar solo a estas direcciones.</span></div></details><?php endif; ?>
            <div class="actions compose-footer"><button type="button" data-step="2">Continuar al mensaje →</button></div>
        </section>
        <section data-panel="2" hidden>
            <p class="step-intro">Elegí la plantilla y cuándo comenzar. En el próximo paso vas a ver el mensaje completo.</p>
            <div class="form-grid">
                <div class="field"><label for="name">Nombre del envío</label><input id="name" name="name" maxlength="150" placeholder="Ej.: Facturas septiembre"></div>
                <div class="field" <?= !$isInvoice ? 'data-email-field' : '' ?>><label for="template_id"><?= $isInvoice ? 'Plantilla de factura y enlace' : 'Plantilla de email' ?></label><select id="template_id" name="template_id"><option value="">Seleccionar</option><?php foreach ($templates as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['name'] . ' · ' . $t['subject']) ?></option><?php endforeach; ?></select></div>
                <div class="field" data-wa-field><label for="whatsapp_template_id">Plantilla de WhatsApp</label><select id="whatsapp_template_id" name="whatsapp_template_id"><option value="">Seleccionar</option><?php foreach ($waTemplates as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['name'] . ' · ' . $t['language']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label for="scheduled_at">Fecha y hora de inicio</label><input id="scheduled_at" name="scheduled_at" type="datetime-local" value="<?= e($initial['now']) ?>"><span class="hint">Si la fecha ya pasó, empezará en la próxima ejecución automática.</span></div>
            </div>
            <p class="hint">El siguiente paso muestra el mensaje, sus adjuntos y los destinatarios habilitados. Todavía no se envía nada.</p>
            <div class="actions compose-footer"><button type="button" class="btn secondary" data-step="1">Volver a destinatarios</button><button type="button" id="reviewSend">Revisar envío →</button></div>
        </section>
        <section data-panel="3" hidden>
            <h3 id="reviewName"></h3><div id="reviewCounts" class="stats-grid"></div><p id="reviewOmissions" class="hint"></p>
            <div id="reviewTest" class="alert warning" hidden></div><p id="reviewSchedule"></p><p id="reviewSubject"></p><ul id="reviewAttachments"></ul>
            <iframe id="reviewFrame" sandbox="" title="Vista previa del email" class="preview-frame"></iframe><div id="reviewWhatsApp"></div>
            <p class="hint">Al confirmar, este contenido queda fijado para el envío. Las bajas posteriores se siguen respetando. Podés cerrar el navegador después de confirmar.</p>
            <div class="actions compose-footer"><button type="button" class="btn secondary" data-step="2">Modificar</button><button type="button" id="confirmSend">Confirmar y programar</button></div>
        </section>
    </form>
</section>
<script type="application/json" id="composeConfig"><?= json_encode($initial, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="assets/js/compose.js" defer></script>
<?php require __DIR__ . '/app/layout/footer.php'; ?>
