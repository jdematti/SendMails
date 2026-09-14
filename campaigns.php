<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$pageTitle = 'Gestion de campanas';
$pageSubtitle = 'Pausar, reanudar, detener o eliminar campanas creadas.';
$status = query_string('status');
$allowedStatuses = [
    '',
    QueueRepository::CAMPAIGN_STATUS_QUEUED,
    QueueRepository::CAMPAIGN_STATUS_PROCESSING,
    QueueRepository::CAMPAIGN_STATUS_PAUSED,
    QueueRepository::CAMPAIGN_STATUS_STOPPED,
    QueueRepository::CAMPAIGN_STATUS_COMPLETED,
];
if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

$allowedLimits = [20, 50, 100, 200];
$limit = normalize_int(query_string('limit', '50'), 50, 20, 200);
if (!in_array($limit, $allowedLimits, true)) {
    $limit = 50;
}

$campaigns = [];
$whatsAppCampaigns = [];
$counts = [];
$error = '';

function campaigns_url(string $status, int $limit): string
{
    $params = ['limit' => $limit];
    if ($status !== '') {
        $params['status'] = $status;
    }

    return 'campaigns.php?' . http_build_query($params);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $campaignId = normalize_int(post_string('campaign_id'), 0, 1);
        $action = post_string('action');
        $actionChannel = post_string('channel', 'email');

        if ($actionChannel === 'whatsapp' && $action === 'pause') {
            WhatsAppRepository::pauseBatch($campaignId);
            flash('success', 'Campana de WhatsApp pausada.');
        } elseif ($actionChannel === 'whatsapp' && $action === 'resume') {
            WhatsAppRepository::resumeBatch($campaignId);
            flash('success', 'Campana de WhatsApp reanudada.');
        } elseif ($actionChannel === 'whatsapp' && $action === 'stop') {
            WhatsAppRepository::stopBatch($campaignId);
            flash('success', 'Campana de WhatsApp detenida.');
        } elseif ($action === 'pause') {
            QueueRepository::pauseCampaign($campaignId);
            flash('success', 'Campana pausada.');
        } elseif ($action === 'resume') {
            QueueRepository::resumeCampaign($campaignId);
            flash('success', 'Campana reanudada.');
        } elseif ($action === 'stop') {
            QueueRepository::stopCampaign($campaignId);
            flash('success', 'Campana detenida. Los pendientes fueron omitidos.');
        } elseif ($action === 'delete') {
            QueueRepository::deleteQueuedCampaign($campaignId);
            flash('success', 'Campana eliminada.');
        } else {
            throw new RuntimeException('Accion no valida.');
        }

        redirect(campaigns_url($status, $limit));
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

try {
    Schema::ensure();
    $campaigns = QueueRepository::campaigns($status, $limit);
    $whatsAppCampaigns = WhatsAppRepository::batches(WhatsAppRepository::SOURCE_CAMPAIGN, $status, $limit);
    $counts = QueueRepository::campaignStatusCounts();
} catch (Throwable $e) {
    $campaigns = [];
    $whatsAppCampaigns = [];
    $counts = [];
    $error = $e->getMessage();
}

$statusLabels = [
    '' => 'Todas',
    QueueRepository::CAMPAIGN_STATUS_QUEUED => 'En cola',
    QueueRepository::CAMPAIGN_STATUS_PROCESSING => 'En proceso',
    QueueRepository::CAMPAIGN_STATUS_PAUSED => 'Pausadas',
    QueueRepository::CAMPAIGN_STATUS_STOPPED => 'Detenidas',
    QueueRepository::CAMPAIGN_STATUS_COMPLETED => 'Completadas',
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
                <a class="btn small secondary <?= $status === $filterStatus ? 'active' : '' ?>" href="<?= e(campaigns_url($filterStatus, $limit)) ?>" data-wait>
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

    <p class="hint"><?= e((string) count($campaigns)) ?> campanas mostradas.</p>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>ID</th>
                <th>Creada</th>
                <th>Programada</th>
                <th>Campana</th>
                <th>Plantilla</th>
                <th>Estado</th>
                <th>Progreso</th>
                <th>Cola</th>
                <th>Acciones</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($campaigns as $campaign): ?>
                <?php
                $campaignStatus = (string) $campaign['status'];
                $canPause = in_array($campaignStatus, [QueueRepository::CAMPAIGN_STATUS_QUEUED, QueueRepository::CAMPAIGN_STATUS_PROCESSING], true);
                $canResume = $campaignStatus === QueueRepository::CAMPAIGN_STATUS_PAUSED;
                $canStop = in_array($campaignStatus, [QueueRepository::CAMPAIGN_STATUS_QUEUED, QueueRepository::CAMPAIGN_STATUS_PROCESSING, QueueRepository::CAMPAIGN_STATUS_PAUSED], true);
                $canDelete = $campaignStatus === QueueRepository::CAMPAIGN_STATUS_QUEUED;
                ?>
                <tr>
                    <td><?= e((string) $campaign['id']) ?></td>
                    <td><?= e(format_datetime($campaign['created_at'])) ?></td>
                    <td>
                        <?= e(format_datetime($campaign['first_scheduled_at'])) ?>
                        <?php if ($campaign['last_scheduled_at'] && $campaign['last_scheduled_at'] !== $campaign['first_scheduled_at']): ?>
                            <br><span class="muted">hasta <?= e(format_datetime($campaign['last_scheduled_at'])) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?= e($campaign['name']) ?></strong>
                        <br><span class="muted"><?= e($campaign['send_mode']) ?></span>
                    </td>
                    <td><?= e($campaign['template_name']) ?></td>
                    <td><span class="status <?= e($campaignStatus) ?>"><?= e($campaignStatus) ?></span></td>
                    <td>
                        <?= e((string) $campaign['sent_count']) ?> enviados
                        <br><span class="muted"><?= e((string) $campaign['failed_count']) ?> fallidos</span>
                    </td>
                    <td>
                        <?= e((string) $campaign['pending_count']) ?> pendientes
                        <?php if ((int) $campaign['sending_count'] > 0): ?>
                            <br><span class="muted"><?= e((string) $campaign['sending_count']) ?> enviando</span>
                        <?php endif; ?>
                        <?php if ((int) $campaign['skipped_count'] > 0): ?>
                            <br><span class="muted"><?= e((string) $campaign['skipped_count']) ?> omitidos</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="table-actions">
                            <?php if ($canPause): ?>
                                <form method="post" class="inline-action-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="campaign_id" value="<?= e((string) $campaign['id']) ?>">
                                    <input type="hidden" name="action" value="pause">
                                    <button type="submit" class="btn small secondary">Pausar</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canResume): ?>
                                <form method="post" class="inline-action-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="campaign_id" value="<?= e((string) $campaign['id']) ?>">
                                    <input type="hidden" name="action" value="resume">
                                    <button type="submit" class="btn small secondary">Reanudar</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canStop): ?>
                                <form method="post" class="inline-action-form" onsubmit="return confirm('Detener esta campana? Los pendientes no se enviaran.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="campaign_id" value="<?= e((string) $campaign['id']) ?>">
                                    <input type="hidden" name="action" value="stop">
                                    <button type="submit" class="btn small danger">Detener</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                                <form method="post" class="inline-action-form" onsubmit="return confirm('Eliminar esta campana en cola? Esta accion no se puede deshacer.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="campaign_id" value="<?= e((string) $campaign['id']) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <button type="submit" class="btn small danger">Eliminar</button>
                                </form>
                            <?php endif; ?>
                            <?php if (!$canPause && !$canResume && !$canStop && !$canDelete): ?>
                                <span class="muted">Sin acciones</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$campaigns): ?>
                <tr><td colspan="9" class="empty">No hay campanas para este filtro.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card" style="margin-top:14px;">
    <div class="toolbar">
        <div>
            <h2>Campañas de WhatsApp</h2>
            <p class="hint"><?= e((string) count($whatsAppCampaigns)) ?> envios mostrados.</p>
        </div>
        <a class="btn small secondary" href="queue.php?type=campaigns&channel=whatsapp" data-wait>Ver cola WhatsApp</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>ID</th><th>Creada</th><th>Campaña</th><th>Plantilla</th><th>Estado</th><th>Progreso</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($whatsAppCampaigns as $campaign): ?>
                <?php
                $waStatus = (string) $campaign['status'];
                $canPause = in_array($waStatus, ['queued', 'processing'], true);
                $canResume = $waStatus === 'paused';
                $canStop = in_array($waStatus, ['queued', 'processing', 'paused'], true);
                ?>
                <tr>
                    <td>W-<?= e((string) $campaign['id']) ?></td>
                    <td><?= e(format_datetime($campaign['created_at'])) ?></td>
                    <td><strong><?= e((string) $campaign['name']) ?></strong></td>
                    <td><?= e((string) $campaign['template_name']) ?><br><span class="muted"><?= e((string) $campaign['language']) ?></span></td>
                    <td><span class="status <?= e($waStatus) ?>"><?= e($waStatus) ?></span></td>
                    <td>
                        <?= e((string) $campaign['total_sent']) ?> aceptados · <?= e((string) $campaign['total_delivered']) ?> entregados · <?= e((string) $campaign['total_read']) ?> leidos
                        <br><span class="muted"><?= e((string) $campaign['pending_count']) ?> pendientes · <?= e((string) $campaign['total_failed']) ?> fallidos</span>
                    </td>
                    <td><div class="table-actions">
                        <?php if ($canPause): ?><form method="post" class="inline-action-form"><?= csrf_field() ?><input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>"><input type="hidden" name="channel" value="whatsapp"><button class="btn small secondary" name="action" value="pause">Pausar</button></form><?php endif; ?>
                        <?php if ($canResume): ?><form method="post" class="inline-action-form"><?= csrf_field() ?><input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>"><input type="hidden" name="channel" value="whatsapp"><button class="btn small secondary" name="action" value="resume">Reanudar</button></form><?php endif; ?>
                        <?php if ($canStop): ?><form method="post" class="inline-action-form" onsubmit="return confirm('Detener esta campaña de WhatsApp?');"><?= csrf_field() ?><input type="hidden" name="campaign_id" value="<?= (int) $campaign['id'] ?>"><input type="hidden" name="channel" value="whatsapp"><button class="btn small danger" name="action" value="stop">Detener</button></form><?php endif; ?>
                        <?php if (!$canPause && !$canResume && !$canStop): ?><span class="muted">Sin acciones</span><?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$whatsAppCampaigns): ?><tr><td colspan="7" class="empty">No hay campañas de WhatsApp para este filtro.</td></tr><?php endif; ?>
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
