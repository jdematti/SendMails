<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$pageTitle = 'Facturas';
$pageSubtitle = 'Listado, seleccion y envio de facturas por email y WhatsApp.';
$requestMethod = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$isQueuePost = $requestMethod === 'POST';
$dueDate = $isQueuePost ? post_string('selection_venc', query_string('venc')) : query_string('venc');
$status = $isQueuePost ? post_string('selection_status', query_string('status')) : query_string('status');
$includeSent = $isQueuePost ? post_string('selection_include_sent', query_string('include_sent') === '1' ? '1' : '0') === '1' : query_string('include_sent') === '1';
$filterSnb = $isQueuePost ? post_string('selection_snb', query_string('snb')) : query_string('snb');
$filterEmail = $isQueuePost ? post_string('selection_email', query_string('email')) : query_string('email');
$filterPhone = $isQueuePost ? post_string('selection_phone', query_string('phone')) : query_string('phone');
$filterName = $isQueuePost ? post_string('selection_name', query_string('name')) : query_string('name');
$channel = $isQueuePost ? post_string('selection_channel', query_string('channel', 'email')) : query_string('channel', 'email');
if (!in_array($channel, ['email', 'whatsapp', 'both'], true)) {
    $channel = 'email';
}
$selectedTemplateId = normalize_int($isQueuePost ? post_string('selection_template_id', query_string('template_id')) : query_string('template_id'), 0, 0);
$selectedWhatsAppTemplateId = normalize_int($isQueuePost ? post_string('selection_whatsapp_template_id', query_string('whatsapp_template_id')) : query_string('whatsapp_template_id'), 0, 0);
$invoiceSearchFilters = [
    'snb' => $filterSnb,
    'email' => $filterEmail,
    'phone' => $filterPhone,
    'name' => $filterName,
];
$allowedLimits = [100, 250, 500, 1000];
$displayLimit = normalize_int(query_string('limit', '250'), 250, 100, 1000);
if (!in_array($displayLimit, $allowedLimits, true)) {
    $displayLimit = 250;
}
$dueDateOptions = [];
$invoices = [];
$totalInvoices = 0;
$templates = [];
$whatsAppTemplates = [];
$template = null;
$error = '';

try {
    Schema::ensure();
    $templates = array_values(array_filter(
        InvoiceRepository::templates(),
        static fn(array $item): bool => !empty($item['is_active'])
    ));
    $whatsAppTemplates = WhatsAppRepository::readyTemplates(WhatsAppRepository::SOURCE_INVOICE);
    if ($selectedTemplateId > 0) {
        foreach ($templates as $templateOption) {
            if ((int) $templateOption['id'] === $selectedTemplateId) {
                $template = $templateOption;
                break;
            }
        }
        if (!$template) {
            $error = 'Selecciona una plantilla de facturas activa.';
        }
    } elseif ($templates) {
        $template = $templates[0];
        $selectedTemplateId = (int) $template['id'];
    } else {
        $error = 'No hay plantilla de facturas activa.';
    }
    $dueDateOptions = InvoiceRepository::recentDueDates(10);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($requestMethod === 'POST') {
    verify_csrf();
    try {
        $usesEmail = in_array($channel, ['email', 'both'], true);
        $usesWhatsApp = in_array($channel, ['whatsapp', 'both'], true);
        $templateId = normalize_int(post_string('selection_template_id'), 0, 0);
        if ($templateId <= 0) {
            throw new RuntimeException('Selecciona una plantilla de facturas activa.');
        }
        $template = InvoiceRepository::findTemplate($templateId);
        if (!$template || !(bool) $template['is_active']) {
            throw new RuntimeException('Selecciona una plantilla de facturas activa.');
        }
        $selectedTemplateId = (int) $template['id'];
        $whatsAppTemplateId = 0;
        if ($usesWhatsApp) {
            $whatsAppTemplateId = normalize_int(post_string('selection_whatsapp_template_id'), 0, 1);
            $whatsAppTemplate = WhatsAppRepository::findTemplate($whatsAppTemplateId);
            if (!$whatsAppTemplate || empty($whatsAppTemplate['is_ready']) || (string) $whatsAppTemplate['category'] !== 'UTILITY') {
                throw new RuntimeException('Selecciona una plantilla UTILITY de WhatsApp aprobada y configurada.');
            }
            $selectedWhatsAppTemplateId = $whatsAppTemplateId;
        }

        if (post_string('invoice_select_all') === '1') {
            if ($dueDate === '') {
                throw new RuntimeException('Selecciona un vencimiento antes de crear el envio.');
            }
            $selected = InvoiceRepository::search($dueDate, $status, $includeSent, $template, $invoiceSearchFilters, 0, $channel);
        } else {
            $tokens = $_POST['invoice_token'] ?? [];
            $selected = InvoiceRepository::findBySelectionTokens(is_array($tokens) ? $tokens : []);
        }
        if (!$selected) {
            throw new RuntimeException('Selecciona al menos una factura.');
        }
        if ($usesWhatsApp) {
            $whatsAppConfig = Settings::whatsapp();
            WhatsAppBusinessService::assertConfigured($whatsAppConfig);
            $hasValidWhatsApp = false;
            foreach ($selected as $selectedInvoice) {
                if (WhatsAppPhone::normalize((string) ($selectedInvoice['telefono_movil'] ?? ''), (string) ($whatsAppConfig['country_code'] ?? '54')) !== null) {
                    $hasValidWhatsApp = true;
                    break;
                }
            }
            if (!$hasValidWhatsApp) {
                throw new RuntimeException('No hay facturas seleccionadas con un celular de WhatsApp valido.');
            }
        }

        $batchName = post_string('invoice_send_name');
        $messages = [];
        if ($usesEmail) {
            $emailSelected = array_values(array_filter($selected, static fn (array $item): bool => filter_var((string) ($item['email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false));
            if (!$emailSelected) {
                throw new RuntimeException('No hay facturas seleccionadas con email valido.');
            }
            $count = InvoiceRepository::createQueue($selectedTemplateId, $emailSelected, $batchName);
            $messages[] = $count . ' emails encolados';
        }
        if ($usesWhatsApp) {
            $whatsAppBatch = WhatsAppRepository::createBatch(
                WhatsAppRepository::SOURCE_INVOICE,
                $whatsAppTemplateId,
                $batchName,
                $selected
            );
            $messages[] = (int) $whatsAppBatch['queued'] . ' WhatsApp encolados';
            if ((int) $whatsAppBatch['invalid'] > 0) {
                $messages[] = (int) $whatsAppBatch['invalid'] . ' celulares invalidos omitidos';
            }
        }
        flash('success', 'Se creo el envio de facturas: ' . implode(', ', $messages) . '.');
        $queueChannel = $channel === 'both' ? 'all' : $channel;
        redirect('queue.php?type=invoices&channel=' . rawurlencode($queueChannel));
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($template && $dueDate !== '') {
    try {
        $totalInvoices = InvoiceRepository::searchTotal($dueDate, $status, $includeSent, $invoiceSearchFilters, $channel);
        $invoices = InvoiceRepository::search($dueDate, $status, $includeSent, $template, $invoiceSearchFilters, $displayLimit, $channel);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$exportUrl = 'invoice_export.php?' . http_build_query([
    'template_id' => $selectedTemplateId,
    'venc' => $dueDate,
    'status' => $status,
    'include_sent' => $includeSent ? '1' : '0',
    'snb' => $filterSnb,
    'email' => $filterEmail,
    'phone' => $filterPhone,
    'name' => $filterName,
    'channel' => $channel,
    'whatsapp_template_id' => $selectedWhatsAppTemplateId,
    'limit' => $displayLimit,
]);
$dueDateOptionValues = array_map(static fn(array $item): string => (string) ($item['due_date'] ?? ''), $dueDateOptions);
$selectedDueDateLabel = $dueDate;
if ($dueDate !== '' && !in_array($dueDate, $dueDateOptionValues, true)) {
    $selectedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);
    if ($selectedDate) {
        $selectedDueDateLabel = $selectedDate->format('d/m/Y');
    }
}
$selectionTotal = max($totalInvoices, count($invoices));
$defaultInvoiceSendName = $invoices
    ? 'Facturas vto ' . ($invoices[0]['due_date_label'] ?? $dueDate) . ' - ' . date('d/m/Y H:i')
    : '';

require __DIR__ . '/app/layout/header.php';
?>

<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<?php if ($template && (bool) $template['test_mode'] && in_array($channel, ['email', 'both'], true)): ?>
    <div class="alert warning">Modo test activo: todos los envios de facturas se enviaran a <?= e($template['test_email']) ?>.</div>
<?php endif; ?>

<section class="card">
    <form method="get" class="invoice-filter-bar" data-wait-form>
        <div class="field">
            <label for="channel">Canal</label>
            <select id="channel" name="channel" required>
                <option value="email"<?= selected('email', $channel) ?>>Email</option>
                <option value="whatsapp"<?= selected('whatsapp', $channel) ?>>WhatsApp</option>
                <option value="both"<?= selected('both', $channel) ?>>Email + WhatsApp</option>
            </select>
        </div>
        <div class="field">
            <label for="venc">Vencimiento</label>
            <select id="venc" name="venc" required>
                <option value="">Seleccionar vencimiento</option>
                <?php if ($dueDate !== '' && !in_array($dueDate, $dueDateOptionValues, true)): ?>
                    <option value="<?= e($dueDate) ?>" selected><?= e($selectedDueDateLabel) ?></option>
                <?php endif; ?>
                <?php foreach ($dueDateOptions as $option): ?>
                    <option value="<?= e((string) $option['due_date']) ?>"<?= selected((string) $option['due_date'], $dueDate) ?>>
                        <?= e((string) $option['due_date_label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="template_id">Plantilla factura / enlace</label>
            <select id="template_id" name="template_id" required>
                <option value="">Seleccionar plantilla</option>
                <?php foreach ($templates as $templateOption): ?>
                    <option value="<?= e((string) $templateOption['id']) ?>"<?= selected((string) $templateOption['id'], (string) $selectedTemplateId) ?>>
                        <?= e((string) $templateOption['name']) ?> - <?= e((string) $templateOption['subject']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field" id="whatsAppInvoiceTemplateField">
            <label for="whatsapp_template_id">Plantilla de WhatsApp</label>
            <select id="whatsapp_template_id" name="whatsapp_template_id">
                <option value="">Seleccionar plantilla</option>
                <?php foreach ($whatsAppTemplates as $templateOption): ?>
                    <option value="<?= e((string) $templateOption['id']) ?>"<?= selected((string) $templateOption['id'], (string) $selectedWhatsAppTemplateId) ?>>
                        <?= e((string) $templateOption['name']) ?> · <?= e((string) $templateOption['language']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="status">Estado</label>
            <select id="status" name="status">
                <option value=""<?= selected('', $status) ?>>Todos los estados</option>
                <option value="I"<?= selected('I', $status) ?>>Impagas</option>
                <option value="P"<?= selected('P', $status) ?>>Pagas</option>
            </select>
        </div>
        <div class="field">
            <label for="snb">SNB</label>
            <input id="snb" name="snb" value="<?= e($filterSnb) ?>" autocomplete="off">
        </div>
        <div class="field">
            <label for="email">Email</label>
            <input id="email" name="email" value="<?= e($filterEmail) ?>" autocomplete="off">
        </div>
        <div class="field">
            <label for="phone">Celular</label>
            <input id="phone" name="phone" value="<?= e($filterPhone) ?>" autocomplete="off">
        </div>
        <div class="field">
            <label for="name">Nombre</label>
            <input id="name" name="name" value="<?= e($filterName) ?>" autocomplete="off">
        </div>
        <div class="field">
            <label for="limit">Mostrar</label>
            <select id="limit" name="limit">
                <?php foreach ($allowedLimits as $option): ?>
                    <option value="<?= e((string) $option) ?>"<?= selected((string) $option, (string) $displayLimit) ?>><?= e((string) $option) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field check-field">
            <label>&nbsp;</label>
            <label class="hint"><input type="checkbox" name="include_sent" value="1"<?= checked($includeSent) ?>> Incluir enviados</label>
        </div>
        <div class="field action-field">
            <label>&nbsp;</label>
            <div class="actions">
                <button type="submit">Buscar</button>
                <a class="btn secondary" href="invoices.php" data-wait>Limpiar</a>
            </div>
        </div>
    </form>
</section>

<section class="card" style="margin-top:14px;">
    <div class="toolbar">
        <div>
            <h2>Facturas disponibles</h2>
            <p class="hint">
                <?php if ($selectionTotal > count($invoices)): ?>
                    <?= e((string) count($invoices)) ?> de <?= e((string) $selectionTotal) ?> registros mostrados. Limite <?= e((string) $displayLimit) ?>.
                <?php else: ?>
                    <?= e((string) count($invoices)) ?> registros mostrados. Limite <?= e((string) $displayLimit) ?>.
                <?php endif; ?>
            </p>
        </div>
        <div class="actions">
            <?php if ($dueDate !== '' && $invoices): ?>
                <a class="btn small secondary" href="<?= e($exportUrl) ?>">Exportar XLSX</a>
            <?php endif; ?>
        </div>
    </div>

    <form method="post" id="invoiceQueueForm" data-total-invoices="<?= e((string) $selectionTotal) ?>" data-wait-form>
        <?= csrf_field() ?>
        <input type="hidden" id="invoiceSelectAll" name="invoice_select_all" value="0">
        <input type="hidden" id="invoiceSendNameValue" name="invoice_send_name" value="<?= e($defaultInvoiceSendName) ?>">
        <input type="hidden" name="selection_template_id" value="<?= e((string) $selectedTemplateId) ?>">
        <input type="hidden" name="selection_whatsapp_template_id" value="<?= e((string) $selectedWhatsAppTemplateId) ?>">
        <input type="hidden" name="selection_channel" value="<?= e($channel) ?>">
        <input type="hidden" name="selection_venc" value="<?= e($dueDate) ?>">
        <input type="hidden" name="selection_status" value="<?= e($status) ?>">
        <input type="hidden" name="selection_include_sent" value="<?= $includeSent ? '1' : '0' ?>">
        <input type="hidden" name="selection_snb" value="<?= e($filterSnb) ?>">
        <input type="hidden" name="selection_email" value="<?= e($filterEmail) ?>">
        <input type="hidden" name="selection_phone" value="<?= e($filterPhone) ?>">
        <input type="hidden" name="selection_name" value="<?= e($filterName) ?>">
        <?php if ($invoices): ?>
            <div class="invoice-queue-actions invoice-queue-actions-top">
                <div class="field invoice-send-name-field">
                    <label for="invoice_send_name_top">Nombre del envio</label>
                    <input id="invoice_send_name_top" value="<?= e($defaultInvoiceSendName) ?>" data-invoice-send-name-input>
                </div>
                <div class="actions">
                    <button type="submit">Crear envio de facturas</button>
                    <span class="hint invoice-selected-count">0 seleccionadas</span>
                </div>
            </div>
        <?php endif; ?>
        <div class="table-wrap">
            <table class="invoice-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="invoiceCheckAll"></th>
                        <th>Email</th>
                        <th>Celular</th>
                        <th>Importe</th>
                        <th>Nombre</th>
                        <th>SNB</th>
                        <th>Vencimiento</th>
                        <th>Enviado</th>
                        <th>Factura</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($invoices as $invoice): ?>
                    <tr data-snb="<?= e($invoice['snb']) ?>" data-email="<?= e($invoice['email']) ?>" data-name="<?= e($invoice['client_name']) ?>">
                        <td><input type="checkbox" name="invoice_token[]" value="<?= e($invoice['selection_token']) ?>" class="invoice-check"></td>
                        <td><?= e($invoice['email']) ?></td>
                        <td><?= e((string) $invoice['telefono_movil']) ?></td>
                        <td><?= e($invoice['amount_label']) ?></td>
                        <td><?= e($invoice['client_name']) ?></td>
                        <td><?= e($invoice['snb']) ?></td>
                        <td><?= e($invoice['due_date_label']) ?></td>
                        <td><?= (int) $invoice['mail_sent'] === 1 ? e($invoice['sent_label'] ?: 'si') : '<span class="muted">no</span>' ?></td>
                        <td>
                            <button
                                type="button"
                                class="btn small secondary invoice-view-button"
                                data-invoice-url="<?= e($invoice['invoice_url']) ?>"
                                data-invoice-title="<?= e('Factura ' . $invoice['invoice_id'] . ' - ' . $invoice['client_name']) ?>"
                            >Ver Factura</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($dueDate === ''): ?>
                    <tr><td colspan="9" class="empty">Selecciona un vencimiento para listar facturas.</td></tr>
                <?php elseif (!$invoices): ?>
                    <tr><td colspan="9" class="empty">No hay facturas para mostrar.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($invoices): ?>
            <div class="invoice-queue-actions invoice-queue-actions-bottom">
                <div class="field invoice-send-name-field">
                    <label for="invoice_send_name_bottom">Nombre del envio</label>
                    <input id="invoice_send_name_bottom" value="<?= e($defaultInvoiceSendName) ?>" data-invoice-send-name-input>
                </div>
                <div class="actions">
                    <button type="submit">Crear envio de facturas</button>
                    <span class="hint invoice-selected-count">0 seleccionadas</span>
                </div>
            </div>
        <?php endif; ?>
    </form>
</section>

<div class="invoice-modal" id="invoiceModal" aria-hidden="true">
    <div class="invoice-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="invoiceModalTitle">
        <div class="invoice-modal-head">
            <h2 id="invoiceModalTitle">Factura</h2>
            <button type="button" class="invoice-modal-close" data-invoice-modal-close aria-label="Cerrar">&times;</button>
        </div>
        <iframe class="invoice-modal-frame" id="invoiceModalFrame" title="Vista de factura"></iframe>
    </div>
</div>

<script>
(() => {
    const channel = document.getElementById('channel');
    const whatsAppTemplateField = document.getElementById('whatsAppInvoiceTemplateField');
    const whatsAppTemplate = document.getElementById('whatsapp_template_id');
    const updateChannelFields = () => {
        const usesWhatsApp = channel?.value === 'whatsapp' || channel?.value === 'both';
        if (whatsAppTemplateField) whatsAppTemplateField.hidden = !usesWhatsApp;
        if (whatsAppTemplate) whatsAppTemplate.required = usesWhatsApp;
    };
    channel?.addEventListener('change', updateChannelFields);
    updateChannelFields();

    const form = document.getElementById('invoiceQueueForm');
    const checkAll = document.getElementById('invoiceCheckAll');
    const selectAllInput = document.getElementById('invoiceSelectAll');
    const sendNameValue = document.getElementById('invoiceSendNameValue');
    const sendNameInputs = Array.from(document.querySelectorAll('[data-invoice-send-name-input]'));
    const checks = Array.from(document.querySelectorAll('.invoice-check'));
    const counts = Array.from(document.querySelectorAll('.invoice-selected-count'));
    const totalInvoices = Math.max(parseInt(form?.getAttribute('data-total-invoices') || '0', 10) || 0, checks.length);
    const modal = document.getElementById('invoiceModal');
    const modalTitle = document.getElementById('invoiceModalTitle');
    const modalFrame = document.getElementById('invoiceModalFrame');
    const closeButtons = Array.from(document.querySelectorAll('[data-invoice-modal-close]'));
    const viewButtons = Array.from(document.querySelectorAll('.invoice-view-button'));
    let selectAllMode = false;

    const selectedText = (selected) => selected + ' seleccionadas';

    const update = () => {
        const selected = checks.filter((item) => item.checked).length;
        const visibleSelectionIsGlobal = totalInvoices <= checks.length;
        const selectedCount = selectAllMode ? totalInvoices : selected;
        counts.forEach((item) => {
            item.textContent = selectedText(selectedCount);
        });
        if (checkAll) {
            checkAll.disabled = checks.length === 0;
            checkAll.checked = selectAllMode || (visibleSelectionIsGlobal && selected > 0 && selected === checks.length);
            checkAll.indeterminate = !selectAllMode && selected > 0 && !checkAll.checked;
        }
        if (selectAllInput) selectAllInput.value = selectAllMode ? '1' : '0';
    };

    if (checkAll) {
        checkAll.addEventListener('change', () => {
            selectAllMode = checkAll.checked;
            checks.forEach((check) => {
                check.checked = checkAll.checked;
            });
            update();
        });
    }

    checks.forEach((item) => item.addEventListener('change', () => {
        selectAllMode = false;
        update();
    }));
    sendNameInputs.forEach((input) => {
        input.addEventListener('input', () => {
            if (sendNameValue) sendNameValue.value = input.value;
            sendNameInputs.forEach((other) => {
                if (other !== input) other.value = input.value;
            });
        });
    });
    update();

    const closeModal = () => {
        if (!modal || !modalFrame) return;
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('invoice-modal-open');
        modalFrame.removeAttribute('src');
    };

    const openModal = (url, title) => {
        if (!modal || !modalFrame || !url) return;
        if (modalTitle) modalTitle.textContent = title || 'Factura';
        modalFrame.setAttribute('src', url);
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('invoice-modal-open');
    };

    viewButtons.forEach((button) => {
        button.addEventListener('click', () => {
            openModal(button.getAttribute('data-invoice-url') || '', button.getAttribute('data-invoice-title') || '');
        });
    });

    closeButtons.forEach((button) => button.addEventListener('click', closeModal));
    if (modal) {
        modal.addEventListener('click', (event) => {
            if (event.target === modal) closeModal();
        });
    }
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal && modal.classList.contains('show')) {
            closeModal();
        }
    });
})();
</script>

<?php require __DIR__ . '/app/layout/footer.php'; ?>
