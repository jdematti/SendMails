<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

set_time_limit(0);

$pageTitle = 'Cola de envios';
$pageSubtitle = 'Pendientes, procesados y errores de campañas y facturas.';
$status = query_string('status');
$type = UnifiedQueueService::normalizeType(query_string('type'));
$channel = UnifiedQueueService::normalizeChannel(query_string('channel', UnifiedQueueService::CHANNEL_ALL));
if ($type === UnifiedQueueService::TYPE_ALL) {
    $type = UnifiedQueueService::TYPE_CAMPAIGNS;
}
$pageTitle = $type === UnifiedQueueService::TYPE_INVOICES ? 'Cola de facturas' : 'Cola de campanas';
$pageSubtitle = $type === UnifiedQueueService::TYPE_INVOICES
    ? 'Pendientes, procesados y errores de facturas.'
    : 'Pendientes, procesados y errores de campanas.';
$allowedLimits = [20, 50, 100];
$displayLimit = normalize_int(query_string('limit', '20'), 20, 20, 100);
if (!in_array($displayLimit, $allowedLimits, true)) {
    $displayLimit = 20;
}
$error = '';
$items = [];
$pendingCount = 0;
$pendingCounts = [
    UnifiedQueueService::TYPE_CAMPAIGNS => 0,
    UnifiedQueueService::TYPE_INVOICES => 0,
];
$smtpIntervalSeconds = 0;

if (!in_array($status, ['', 'pending', 'sending', 'accepted', 'sent', 'delivered', 'read', 'failed', 'skipped'], true)) {
    $status = '';
}

function queue_url(string $type, string $status, int $limit = 20, string $channel = UnifiedQueueService::CHANNEL_ALL): string
{
    $params = ['limit' => $limit];
    if ($type !== '') {
        $params['type'] = $type;
    }
    if ($status !== '') {
        $params['status'] = $status;
    }
    if ($channel !== UnifiedQueueService::CHANNEL_ALL) {
        $params['channel'] = $channel;
    }

    return 'queue.php' . ($params ? '?' . http_build_query($params) : '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $type = UnifiedQueueService::normalizeType(post_string('type'));
        $channel = UnifiedQueueService::normalizeChannel(post_string('channel'));
        $limit = normalize_int(post_string('limit', '10'), 10, 1, 10000);
        $processed = UnifiedQueueService::processPending($type, $limit, $channel);
        flash('success', 'Procesados: ' . $processed['processed'] . ' | enviados: ' . $processed['sent'] . ' | fallidos: ' . $processed['failed'] . ' | omitidos: ' . (int) ($processed['skipped'] ?? 0));
        redirect(queue_url($type, '', $displayLimit, $channel));
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

try {
    Schema::ensure();
    $items = UnifiedQueueService::queueItems($type, $status, $displayLimit, $channel);
    $pendingCounts = UnifiedQueueService::pendingCounts($channel);
    $pendingCount = UnifiedQueueService::pendingCount($type, $channel);
    $smtpIntervalSeconds = normalize_int((string) (Settings::smtp()['interval_seconds'] ?? '0'), 0, 0, 3600);
} catch (Throwable $e) {
    $items = [];
    $error = $e->getMessage();
}

$typeFilters = [
    UnifiedQueueService::TYPE_CAMPAIGNS => 'Campanas',
    UnifiedQueueService::TYPE_INVOICES => 'Facturas',
];
$statusFilters = [
    '' => 'Todos',
    'pending' => 'Pendientes',
    'accepted' => 'Aceptados por Meta',
    'sent' => 'Enviados',
    'delivered' => 'Entregados',
    'read' => 'Leidos',
    'failed' => 'Fallidos',
    'skipped' => 'Omitidos',
];
$channelFilters = [
    UnifiedQueueService::CHANNEL_ALL => 'Todos los canales',
    UnifiedQueueService::CHANNEL_EMAIL => 'Email',
    UnifiedQueueService::CHANNEL_WHATSAPP => 'WhatsApp',
];
$defaultLimit = $pendingCount > 0 ? min($pendingCount, 10000) : 10;

require __DIR__ . '/app/layout/header.php';
?>

<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

<section class="card">
    <?php if ($pendingCount > 0): ?>
        <div class="toolbar queue-toolbar">
            <form method="post" class="actions queue-process-form" id="queueProcessForm" data-pending="<?= e((string) $pendingCount) ?>" data-interval="<?= e((string) $smtpIntervalSeconds) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="type" value="<?= e($type) ?>">
                <input type="hidden" name="channel" value="<?= e($channel) ?>">
                <input name="limit" type="number" min="1" max="10000" value="<?= e((string) $defaultLimit) ?>" style="width:120px;">
                <button type="submit">Procesar pendientes</button>
            </form>
        </div>
    <?php endif; ?>

    <div class="toolbar queue-filter-toolbar">
        <div class="actions segmented-actions">
            <?php foreach ($channelFilters as $filterChannel => $label): ?>
                <a class="btn small secondary <?= $channel === $filterChannel ? 'active' : '' ?>" href="<?= e(queue_url($type, $status, $displayLimit, $filterChannel)) ?>" data-wait><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="actions segmented-actions">
            <?php foreach ($typeFilters as $filterType => $label): ?>
                <a class="btn small secondary <?= $type === $filterType ? 'active' : '' ?>" href="<?= e(queue_url($filterType, $status, $displayLimit, $channel)) ?>" data-wait><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="actions segmented-actions">
            <?php foreach ($statusFilters as $filterStatus => $label): ?>
                <a class="btn small secondary <?= $status === $filterStatus ? 'active' : '' ?>" href="<?= e(queue_url($type, $filterStatus, $displayLimit, $channel)) ?>" data-wait><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
        <form method="get" class="actions queue-limit-form" data-wait-form>
            <?php if ($type !== ''): ?><input type="hidden" name="type" value="<?= e($type) ?>"><?php endif; ?>
            <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
            <?php if ($channel !== UnifiedQueueService::CHANNEL_ALL): ?><input type="hidden" name="channel" value="<?= e($channel) ?>"><?php endif; ?>
            <div class="field">
                <label for="limit">Mostrar</label>
                <select id="limit" name="limit">
                    <?php foreach ($allowedLimits as $option): ?>
                        <option value="<?= e((string) $option) ?>"<?= selected((string) $option, (string) $displayLimit) ?>><?= e((string) $option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>

    <p class="hint"><?= e((string) count($items)) ?> registros mostrados.</p>

    <div class="table-wrap">
        <table>
            <thead><tr><th>ID</th><th>Canal</th><th>Programado</th><th>Cliente</th><th>Destinatario</th><th>Plantilla / ID Meta</th><th>Estado</th><th>Error</th></tr></thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= e((string) $item['display_id']) ?></td>
                    <td><?= e((string) $item['channel_label']) ?></td>
                    <td><?= e(format_datetime($item['scheduled_at'] ?? $item['created_at'])) ?></td>
                    <td>
                        <?= e($item['client_name']) ?>
                        <?php foreach ($item['client_meta'] as $meta): ?>
                            <br><span class="muted"><?= e($meta) ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td><?= e((string) $item['recipient']) ?></td>
                    <td>
                        <?= e((string) $item['template_label']) ?>
                        <?php if (trim((string) $item['provider_message_id']) !== ''): ?><br><span class="muted"><?= e((string) $item['provider_message_id']) ?></span><?php endif; ?>
                    </td>
                    <td><span class="status <?= e($item['status']) ?>"><?= e($item['status']) ?></span></td>
                    <td><?= e($item['last_error']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$items): ?>
                <tr><td colspan="8" class="empty">No hay items en la cola.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="send-progress-modal" id="sendProgressModal" aria-hidden="true">
    <div class="send-progress-dialog" role="status" aria-live="polite">
        <h2>Enviando, por favor espere</h2>
        <p id="sendProgressText">Preparando envios pendientes...</p>
        <div class="progress-track">
            <div class="progress-fill" id="sendProgressFill" style="width:0%"></div>
        </div>
        <div class="progress-meta">
            <span id="sendProgressCount">0 / 0</span>
            <span id="sendProgressPercent">0%</span>
        </div>
    </div>
</div>

<script>
(() => {
    const limitForm = document.querySelector('.queue-limit-form');
    const limitSelect = document.getElementById('limit');
    if (limitForm && limitSelect) {
        limitSelect.addEventListener('change', () => limitForm.requestSubmit());
    }

    const form = document.getElementById('queueProcessForm');
    const modal = document.getElementById('sendProgressModal');
    if (!form || !modal || !window.fetch) return;

    const fill = document.getElementById('sendProgressFill');
    const text = document.getElementById('sendProgressText');
    const count = document.getElementById('sendProgressCount');
    const percent = document.getElementById('sendProgressPercent');
    let running = false;

    const updateProgress = (processed, total, sent, failed, message, skipped = 0) => {
        const safeTotal = Math.max(total, 1);
        const value = Math.min(100, Math.round((processed / safeTotal) * 100));
        fill.style.width = value + '%';
        count.textContent = processed + ' / ' + total;
        percent.textContent = value + '%';
        text.textContent = message || ('Enviados: ' + sent + ' | Fallidos: ' + failed + ' | Omitidos: ' + skipped);
    };

    const finishAndReload = (message) => {
        if (message) text.textContent = message;
        setTimeout(() => window.location.reload(), 900);
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (running) return;
        running = true;

        const data = new FormData(form);
        const limit = Math.max(1, Math.min(parseInt(String(data.get('limit') || '10'), 10) || 10, 10000));
        const pending = Math.max(0, parseInt(form.dataset.pending || '0', 10) || 0);
        const fallbackIntervalMs = Math.max(0, parseInt(form.dataset.interval || '0', 10) || 0) * 1000;
        const total = Math.min(limit, pending || limit);

        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        updateProgress(0, total, 0, 0, total > 0 ? 'Iniciando envio...' : 'No hay envios pendientes para procesar.');

        if (total <= 0) {
            finishAndReload('No hay envios pendientes para procesar.');
            return;
        }

        let processed = 0;
        let sent = 0;
        let failed = 0;
        let skipped = 0;

        try {
            while (processed < total) {
                const step = new URLSearchParams();
                step.set('csrf_token', String(data.get('csrf_token') || ''));
                step.set('type', String(data.get('type') || ''));
                step.set('channel', String(data.get('channel') || 'all'));

                const response = await fetch('queue_process_step.php', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: step.toString()
                });

                const result = await response.json();
                if (!response.ok || !result.ok) {
                    throw new Error(result.error || 'No se pudo procesar el envio.');
                }

                if (result.processed === 0) {
                    break;
                }

                processed += result.processed;
                sent += result.sent;
                failed += result.failed;
                skipped += result.skipped || 0;
                updateProgress(processed, total, sent, failed, null, skipped);

                const hasResultInterval = result.interval_seconds !== undefined && result.interval_seconds !== null;
                const intervalSeconds = hasResultInterval
                    ? Math.max(0, parseInt(String(result.interval_seconds), 10) || 0)
                    : Math.round(fallbackIntervalMs / 1000);
                const intervalMs = intervalSeconds * 1000;
                if (processed < total && (result.pending || 0) > 0 && intervalMs > 0) {
                    const waitSeconds = Math.round(intervalMs / 1000);
                    updateProgress(processed, total, sent, failed, 'Esperando ' + waitSeconds + ' segundos antes del proximo envio...', skipped);
                    await new Promise((resolve) => setTimeout(resolve, intervalMs));
                }
            }

            updateProgress(processed, total, sent, failed, 'Proceso finalizado. Actualizando cola...', skipped);
            finishAndReload();
        } catch (error) {
            text.textContent = error.message;
            modal.classList.add('has-error');
            running = false;
        }
    });
})();
</script>

<?php require __DIR__ . '/app/layout/footer.php'; ?>
