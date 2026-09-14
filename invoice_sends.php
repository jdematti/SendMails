<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$pageTitle = 'Gestion de facturas';
$pageSubtitle = 'Pausar, reanudar, detener o eliminar envios de facturas.';
$status = query_string('status');
$allowedStatuses = [
    '',
    InvoiceRepository::BATCH_STATUS_QUEUED,
    InvoiceRepository::BATCH_STATUS_PROCESSING,
    InvoiceRepository::BATCH_STATUS_PAUSED,
    InvoiceRepository::BATCH_STATUS_STOPPED,
    InvoiceRepository::BATCH_STATUS_COMPLETED,
];
if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

$allowedLimits = [20, 50, 100, 200];
$limit = normalize_int(query_string('limit', '50'), 50, 20, 200);
if (!in_array($limit, $allowedLimits, true)) {
    $limit = 50;
}

$batches = [];
$whatsAppBatches = [];
$counts = [];
$error = '';

function invoice_sends_url(string $status, int $limit): string
{
    $params = ['limit' => $limit];
    if ($status !== '') {
        $params['status'] = $status;
    }

    return 'invoice_sends.php?' . http_build_query($params);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $batchId = normalize_int(post_string('batch_id'), 0, 1);
        $action = post_string('action');
        $actionChannel = post_string('channel', 'email');

        if ($actionChannel === 'whatsapp' && $action === 'pause') {
            WhatsAppRepository::pauseBatch($batchId);
            flash('success', 'Envio de facturas por WhatsApp pausado.');
        } elseif ($actionChannel === 'whatsapp' && $action === 'resume') {
            WhatsAppRepository::resumeBatch($batchId);
            flash('success', 'Envio de facturas por WhatsApp reanudado.');
        } elseif ($actionChannel === 'whatsapp' && $action === 'stop') {
            WhatsAppRepository::stopBatch($batchId);
            flash('success', 'Envio de facturas por WhatsApp detenido.');
        } elseif ($action === 'pause') {
            InvoiceRepository::pauseInvoiceBatch($batchId);
            flash('success', 'Envio de facturas pausado.');
        } elseif ($action === 'resume') {
            InvoiceRepository::resumeInvoiceBatch($batchId);
            flash('success', 'Envio de facturas reanudado.');
        } elseif ($action === 'stop') {
            InvoiceRepository::stopInvoiceBatch($batchId);
            flash('success', 'Envio de facturas detenido. Los pendientes fueron omitidos.');
        } elseif ($action === 'delete') {
            InvoiceRepository::deleteQueuedInvoiceBatch($batchId);
            flash('success', 'Envio de facturas eliminado.');
        } else {
            throw new RuntimeException('Accion no valida.');
        }

        redirect(invoice_sends_url($status, $limit));
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

try {
    Schema::ensure();
    $batches = InvoiceRepository::invoiceBatches($status, $limit);
    $whatsAppBatches = WhatsAppRepository::batches(WhatsAppRepository::SOURCE_INVOICE, $status, $limit);
    $counts = InvoiceRepository::invoiceBatchStatusCounts();
} catch (Throwable $e) {
    $batches = [];
    $whatsAppBatches = [];
    $counts = [];
    $error = $e->getMessage();
}

$statusLabels = [
    '' => 'Todos',
    InvoiceRepository::BATCH_STATUS_QUEUED => 'En cola',
    InvoiceRepository::BATCH_STATUS_PROCESSING => 'En proceso',
    InvoiceRepository::BATCH_STATUS_PAUSED => 'Pausados',
    InvoiceRepository::BATCH_STATUS_STOPPED => 'Detenidos',
    InvoiceRepository::BATCH_STATUS_COMPLETED => 'Completados',
];
$allCount = array_sum(array_map(static fn ($value): int => (int) $value, $counts));

require __DIR__ . '/app/layout/header.php';
?>

<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

<section class="card">
    <div class="toolbar queue-filter-toolbar">
        <div class="actions segmented-actions">
            <?php foreach ($statusLabels as $filterStatus => $label): ?>
                <?php $count = $filterStatus === '' ? $allCount : (int) ($counts[$filterStatus] ?? 0); ?>
                <a class="btn small secondary <?= $status === $filterStatus ? 'active' : '' ?>" href="<?= e(invoice_sends_url($filterStatus, $limit)) ?>" data-wait>
                    <?= e($label) ?> <span class="btn-count"><?= e((string) $count) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <form method="get" class="actions queue-limit-form" data-wait-form>
            <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
            <div class="field">
                <label for="limit">Mostrar</label>
                <select id="limit" name="limit">
                    <?php foreach ($allowedLimits as $option): ?>
                        <option value="<?= e((string) $option) ?>"<?= selected((string) $option, (string) $limit) ?>><?= e((string) $option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>

    <p class="hint"><?= e((string) count($batches)) ?> envios mostrados.</p>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>ID</th>
                <th>Creado</th>
                <th>Programado</th>
                <th>Envio</th>
                <th>Vencimiento</th>
                <th>Estado</th>
                <th>Progreso</th>
                <th>Cola</th>
                <th>Acciones</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($batches as $batch): ?>
                <?php
                $batchStatus = (string) $batch['status'];
                $canPause = in_array($batchStatus, [InvoiceRepository::BATCH_STATUS_QUEUED, InvoiceRepository::BATCH_STATUS_PROCESSING], true);
                $canResume = $batchStatus === InvoiceRepository::BATCH_STATUS_PAUSED;
                $canStop = in_array($batchStatus, [InvoiceRepository::BATCH_STATUS_QUEUED, InvoiceRepository::BATCH_STATUS_PROCESSING, InvoiceRepository::BATCH_STATUS_PAUSED], true);
                $canDelete = $batchStatus === InvoiceRepository::BATCH_STATUS_QUEUED;
                ?>
                <tr>
                    <td><?= e((string) $batch['id']) ?></td>
                    <td><?= e(format_datetime($batch['created_at'])) ?></td>
                    <td>
                        <?= e(format_datetime($batch['first_scheduled_at'])) ?>
                        <?php if ($batch['last_scheduled_at'] && $batch['last_scheduled_at'] !== $batch['first_scheduled_at']): ?>
                            <br><span class="muted">hasta <?= e(format_datetime($batch['last_scheduled_at'])) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?= e($batch['name']) ?></strong>
                        <br><span class="muted"><?= e($batch['template_name']) ?></span>
                    </td>
                    <td><?= e((string) ($batch['due_date_label'] ?: '-')) ?></td>
                    <td><span class="status <?= e($batchStatus) ?>"><?= e($batchStatus) ?></span></td>
                    <td>
                        <?= e((string) $batch['sent_count']) ?> enviados
                        <br><span class="muted"><?= e((string) $batch['failed_count']) ?> fallidos</span>
                    </td>
                    <td>
                        <?= e((string) $batch['pending_count']) ?> pendientes
                        <?php if ((int) $batch['sending_count'] > 0): ?>
                            <br><span class="muted"><?= e((string) $batch['sending_count']) ?> enviando</span>
                        <?php endif; ?>
                        <?php if ((int) $batch['skipped_count'] > 0): ?>
                            <br><span class="muted"><?= e((string) $batch['skipped_count']) ?> omitidos</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="table-actions">
                            <a class="btn small secondary" href="queue.php?type=invoices" data-wait>Ver cola</a>
                            <?php if ($canPause): ?>
                                <form method="post" class="inline-action-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="batch_id" value="<?= e((string) $batch['id']) ?>">
                                    <input type="hidden" name="action" value="pause">
                                    <button type="submit" class="btn small secondary">Pausar</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canResume): ?>
                                <form method="post" class="inline-action-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="batch_id" value="<?= e((string) $batch['id']) ?>">
                                    <input type="hidden" name="action" value="resume">
                                    <button type="submit" class="btn small secondary">Reanudar</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canStop): ?>
                                <form method="post" class="inline-action-form" onsubmit="return confirm('Detener este envio de facturas? Los pendientes no se enviaran.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="batch_id" value="<?= e((string) $batch['id']) ?>">
                                    <input type="hidden" name="action" value="stop">
                                    <button type="submit" class="btn small danger">Detener</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                                <form method="post" class="inline-action-form" onsubmit="return confirm('Eliminar este envio de facturas en cola? Esta accion no se puede deshacer.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="batch_id" value="<?= e((string) $batch['id']) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <button type="submit" class="btn small danger">Eliminar</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$batches): ?>
                <tr><td colspan="9" class="empty">No hay envios de facturas para este filtro.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card" style="margin-top:14px;">
    <div class="toolbar">
        <div>
            <h2>Facturas por WhatsApp</h2>
            <p class="hint"><?= e((string) count($whatsAppBatches)) ?> envios mostrados.</p>
        </div>
        <a class="btn small secondary" href="queue.php?type=invoices&channel=whatsapp" data-wait>Ver cola WhatsApp</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>ID</th><th>Creado</th><th>Envio</th><th>Plantilla</th><th>Estado</th><th>Progreso</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($whatsAppBatches as $batch): ?>
                <?php
                $waStatus = (string) $batch['status'];
                $canPause = in_array($waStatus, ['queued', 'processing'], true);
                $canResume = $waStatus === 'paused';
                $canStop = in_array($waStatus, ['queued', 'processing', 'paused'], true);
                ?>
                <tr>
                    <td>W-<?= e((string) $batch['id']) ?></td>
                    <td><?= e(format_datetime($batch['created_at'])) ?></td>
                    <td><strong><?= e((string) $batch['name']) ?></strong></td>
                    <td><?= e((string) $batch['template_name']) ?><br><span class="muted"><?= e((string) $batch['language']) ?></span></td>
                    <td><span class="status <?= e($waStatus) ?>"><?= e($waStatus) ?></span></td>
                    <td>
                        <?= e((string) $batch['total_sent']) ?> aceptados · <?= e((string) $batch['total_delivered']) ?> entregados · <?= e((string) $batch['total_read']) ?> leidos
                        <br><span class="muted"><?= e((string) $batch['pending_count']) ?> pendientes · <?= e((string) $batch['total_failed']) ?> fallidos</span>
                    </td>
                    <td><div class="table-actions">
                        <?php if ($canPause): ?><form method="post" class="inline-action-form"><?= csrf_field() ?><input type="hidden" name="batch_id" value="<?= (int) $batch['id'] ?>"><input type="hidden" name="channel" value="whatsapp"><button class="btn small secondary" name="action" value="pause">Pausar</button></form><?php endif; ?>
                        <?php if ($canResume): ?><form method="post" class="inline-action-form"><?= csrf_field() ?><input type="hidden" name="batch_id" value="<?= (int) $batch['id'] ?>"><input type="hidden" name="channel" value="whatsapp"><button class="btn small secondary" name="action" value="resume">Reanudar</button></form><?php endif; ?>
                        <?php if ($canStop): ?><form method="post" class="inline-action-form" onsubmit="return confirm('Detener este envio de facturas por WhatsApp?');"><?= csrf_field() ?><input type="hidden" name="batch_id" value="<?= (int) $batch['id'] ?>"><input type="hidden" name="channel" value="whatsapp"><button class="btn small danger" name="action" value="stop">Detener</button></form><?php endif; ?>
                        <?php if (!$canPause && !$canResume && !$canStop): ?><span class="muted">Sin acciones</span><?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$whatsAppBatches): ?><tr><td colspan="7" class="empty">No hay envios de facturas por WhatsApp para este filtro.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
(() => {
    const limitForm = document.querySelector('.queue-limit-form');
    const limitSelect = document.getElementById('limit');
    if (limitForm && limitSelect) {
        limitSelect.addEventListener('change', () => limitForm.requestSubmit());
    }
})();
</script>

<?php require __DIR__ . '/app/layout/footer.php'; ?>
