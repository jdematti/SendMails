<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
$filters = ActivityRepository::filters($_GET);
$page = max(1, (int) query_string('page', '1'));
$showRecipients = $filters['batch_id'] > 0 || $filters['status'] !== '' || query_string('recipients') === '1' || $page > 1;
$pageTitle = $filters['batch_id'] ? 'Detalle del envío' : 'Seguimiento de envíos';
$pageSubtitle = $filters['batch_id'] ? 'Consultá el resultado de cada destinatario.' : 'Elegí un envío para ver sus destinatarios y resultados.';
$viewQuery = array_diff_key($filters, ['branch'=>true]) + ($showRecipients ? ['recipients'=>'1'] : []);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        ActivityRepository::change(post_string('kind'), post_string('channel'), (int) post_string('batch_id'), post_string('action'));
        flash('success', 'Estado del envío actualizado.');
        redirect('activity.php?' . http_build_query($viewQuery));
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$summary = ActivityRepository::summary($filters);
$batches = (!$showRecipients || $filters['batch_id']) ? ActivityRepository::batches($filters) : [];
$result = $showRecipients ? ActivityRepository::page($filters, $page) : ['rows'=>[], 'total'=>0];
$worker = WorkerRuntime::status();
$fragment = query_string('fragment') === '1';
if ($fragment) header('Cache-Control: no-store');
if (!$fragment) {
    require __DIR__ . '/app/layout/header.php';
?>
<div class="page-actions"><div class="actions"><?php if ($showRecipients): ?><a class="btn secondary" href="activity.php">← Todos los envíos</a><?php else: ?><a class="btn" href="send.php">Crear campaña</a><a class="btn secondary" href="invoices.php">Preparar facturas</a><?php endif; ?></div><details class="more-actions"><summary>Más opciones</summary><div class="actions"><a href="logs.php">Historial de correos</a><a href="campaigns.php">Administrar campañas</a><a href="invoice_sends.php">Administrar lotes de facturas</a></div></details></div>
<section class="card filter-card"><form method="get" class="compact-filters">
    <div class="field"><label for="kind">Tipo</label><select id="kind" name="kind"><option value="">Todos</option><option value="campaign"<?= selected('campaign',$filters['kind']) ?>>Campañas</option><option value="invoice"<?= selected('invoice',$filters['kind']) ?>>Facturas</option></select></div>
    <div class="field"><label for="channel">Canal</label><select id="channel" name="channel"><option value="">Todos</option><option value="email"<?= selected('email',$filters['channel']) ?>>Email</option><option value="whatsapp"<?= selected('whatsapp',$filters['channel']) ?>>WhatsApp</option></select></div>
    <div class="field"><label for="status">Estado del destinatario</label><select id="status" name="status"><option value="">Todos</option><?php foreach (['pending','sending','sent','failed','skipped','accepted','delivered','read'] as $state): ?><option value="<?= e($state) ?>"<?= selected($state,$filters['status']) ?>><?= e(ActivityRepository::label($state)) ?></option><?php endforeach; ?></select></div>
    <?php if ($filters['batch_id']): ?><input type="hidden" name="batch_id" value="<?= $filters['batch_id'] ?>"><?php endif; ?>
    <?php if ($showRecipients): ?><input type="hidden" name="recipients" value="1"><?php endif; ?>
    <div class="actions"><button>Filtrar</button><a class="btn secondary" href="activity.php">Limpiar</a></div>
</form></section>
<p class="hint" id="activityRefresh" role="status">Se actualiza automáticamente cada 10 segundos.</p>
<div id="liveActivity">
<?php } ?>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<?php require __DIR__ . '/app/layout/activity_metrics.php'; ?>
<?php if (($summary['attention'] ?? 0) || (($summary['sending'] ?? 0) && empty($worker['healthy']))): ?><div class="alert warning">Hay envíos cuyo resultado necesita revisión. Abrí su detalle antes de reenviarlos: podrían haber sido entregados.</div><?php endif; ?>
<?php if (!$showRecipients || $filters['batch_id']): ?>
<section class="card"><div class="section-heading"><h2><?= $filters['batch_id'] ? 'Resumen del envío' : 'Envíos recientes' ?></h2><?php if (!$showRecipients): ?><a href="?<?= e(http_build_query($viewQuery + ['recipients'=>'1'])) ?>">Ver todos los destinatarios →</a><?php endif; ?></div><div class="table-wrap bounded-table" tabindex="0" aria-label="Envíos recientes" data-scroll-key="batches"><table>
    <thead><tr><th>Envío</th><th>Canal</th><th>Progreso</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
    <?php foreach ($batches as $batch): ?>
        <tr><td><a href="activity.php?<?= e(http_build_query(['kind'=>$batch['kind'],'channel'=>$batch['channel'],'batch_id'=>$batch['batch_id']])) ?>"><?= e($batch['name']) ?></a></td>
        <td><?= $batch['channel']==='email' ? 'Email' : 'WhatsApp' ?></td>
        <td><div class="batch-progress"><progress aria-label="Destinatarios procesados" max="<?= (int) $batch['total'] ?>" value="<?= (int) $batch['processed'] ?>"></progress><span><?= e(number_format((int) $batch['processed'],0,',','.')) ?> / <?= e(number_format((int) $batch['total'],0,',','.')) ?></span></div><?php if ($batch['failed']): ?><span class="hint"><?= (int) $batch['failed'] ?> con error</span><?php endif; ?></td>
        <td><span class="status <?= e($batch['batch_status']) ?>"><?= e(ActivityRepository::label($batch['batch_status'])) ?></span></td><td>
        <?php if (!$filters['batch_id']): ?><a class="btn small secondary" data-batch-detail href="activity.php?<?= e(http_build_query(['kind'=>$batch['kind'],'channel'=>$batch['channel'],'batch_id'=>$batch['batch_id']])) ?>">Ver detalle</a><?php else: ?>
        <form method="post" class="actions"><?= csrf_field() ?><input type="hidden" name="kind" value="<?= e($batch['kind']) ?>"><input type="hidden" name="channel" value="<?= e($batch['channel']) ?>"><input type="hidden" name="batch_id" value="<?= (int) $batch['batch_id'] ?>">
            <?php if (in_array($batch['batch_status'],['queued','processing'],true)): ?><button name="action" value="pause" class="btn secondary">Pausar</button><?php endif; ?>
            <?php if ($batch['batch_status']==='paused'): ?><button name="action" value="resume">Continuar</button><?php endif; ?>
            <?php if (in_array($batch['batch_status'],['queued','processing','paused'],true)): ?><button name="action" value="stop" class="btn danger" data-confirm-stop>Detener</button><?php endif; ?>
        </form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$batches): ?><tr><td colspan="5">No hay envíos para estos filtros.</td></tr><?php endif; ?>
    </tbody></table></div><?php if (count($batches) === 100 && !$filters['batch_id']): ?><p class="hint">Se muestran los 100 envíos más recientes. Usá los filtros o las opciones de administración para consultar más historial.</p><?php endif; ?></section>
<?php endif; ?>
<?php if ($showRecipients): ?>
<section class="card" id="recipientResults"><div class="section-heading"><h2>Destinatarios y resultados</h2><span class="hint"><?= e(number_format($result['total'],0,',','.')) ?> resultados</span></div><div class="table-wrap bounded-table recipient-results" tabindex="0" aria-label="Destinatarios y resultados" data-scroll-key="recipients"><table>
    <thead><tr><th>Cliente</th><th>Destino</th><th>Envío</th><th>Programado</th><th>Resultado</th></tr></thead><tbody>
    <?php foreach ($result['rows'] as $row): ?><tr><td><?= e($row['client_name']) ?></td><td><?= e($row['recipient']) ?><br><small><?= $row['channel']==='email' ? 'Email' : 'WhatsApp' ?></small></td>
        <td><?= e($row['name']) ?></td><td><?= e(format_datetime($row['scheduled_at'])) ?></td><td><?= e(ActivityRepository::label($row['status'])) ?><?= $row['is_test'] ? ' · PRUEBA' : '' ?>
        <?php if ($row['last_error']): ?><details><summary>Ver detalle</summary><?= e($row['last_error']) ?></details><?php endif; ?></td></tr><?php endforeach; ?>
    <?php if (!$result['rows']): ?><tr><td colspan="5">No hay destinatarios para estos filtros.</td></tr><?php endif; ?>
    </tbody></table></div><div class="pagination">
    <?php if ($page>1): ?><a class="btn secondary" href="?<?= e(http_build_query($viewQuery+['page'=>$page-1])) ?>">Anterior</a><?php endif; ?>
    <span>Página <?= $page ?> · <?= $result['total'] ?> resultados</span>
    <?php if ($page*50<$result['total']): ?><a class="btn secondary" href="?<?= e(http_build_query($viewQuery+['page'=>$page+1])) ?>">Siguiente</a><?php endif; ?>
</div></section>
<?php endif; ?>
<?php if (!$fragment): ?>
</div>
<script src="assets/js/activity.js" defer></script>
<?php require __DIR__ . '/app/layout/footer.php'; endif; ?>
