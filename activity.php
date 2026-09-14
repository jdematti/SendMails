<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
$pageTitle = 'Seguimiento de envíos';
$pageSubtitle = 'Campañas y facturas por email y WhatsApp, con procesamiento automático.';
$filters = ActivityRepository::filters($_GET);
$page = max(1, (int) query_string('page', '1'));
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        ActivityRepository::change(post_string('kind'), post_string('channel'), (int) post_string('batch_id'), post_string('action'));
        flash('success', 'Estado del envío actualizado.');
        redirect('activity.php?' . http_build_query(array_diff_key($filters, ['branch'=>true])));
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$summary = ActivityRepository::summary($filters);
$batches = ActivityRepository::batches($filters);
$result = ActivityRepository::page($filters, $page);
$worker = WorkerRuntime::status();
$fragment = query_string('fragment') === '1';
if ($fragment) header('Cache-Control: no-store');
if (!$fragment) {
    require __DIR__ . '/app/layout/header.php';
?>
<div class="actions"><a class="btn" href="send.php">Crear campaña</a><a class="btn secondary" href="invoices.php">Preparar facturas</a><a class="btn secondary" href="logs.php">Registros de email</a><a class="btn secondary" href="campaigns.php">Administrar campañas</a><a class="btn secondary" href="invoice_sends.php">Administrar lotes de facturas</a></div>
<section class="card"><form method="get" class="form-grid">
    <div class="field"><label for="kind">Tipo</label><select id="kind" name="kind"><option value="">Todos</option><option value="campaign"<?= selected('campaign',$filters['kind']) ?>>Campañas</option><option value="invoice"<?= selected('invoice',$filters['kind']) ?>>Facturas</option></select></div>
    <div class="field"><label for="channel">Canal</label><select id="channel" name="channel"><option value="">Todos</option><option value="email"<?= selected('email',$filters['channel']) ?>>Email</option><option value="whatsapp"<?= selected('whatsapp',$filters['channel']) ?>>WhatsApp</option></select></div>
    <div class="field"><label for="status">Estado del destinatario</label><select id="status" name="status"><option value="">Todos</option><?php foreach (['pending','sending','sent','failed','skipped','accepted','delivered','read'] as $state): ?><option value="<?= e($state) ?>"<?= selected($state,$filters['status']) ?>><?= e(ActivityRepository::label($state)) ?></option><?php endforeach; ?></select></div>
    <?php if ($filters['batch_id']): ?><input type="hidden" name="batch_id" value="<?= $filters['batch_id'] ?>"><?php endif; ?>
    <div class="actions"><button>Filtrar</button><a class="btn secondary" href="activity.php">Ver todos los envíos</a></div>
</form></section>
<p class="hint" id="activityRefresh" role="status">Se actualiza automáticamente cada 10 segundos.</p>
<div id="liveActivity">
<?php } ?>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<div class="alert <?= !empty($worker['healthy']) ? 'success' : 'warning' ?>">
    <?= !empty($worker['healthy']) ? 'Proceso automático activo.' : 'No hay actividad reciente del proceso automático. Revisá la tarea SendMails Worker.' ?>
    <?php if (!empty($worker['updated_at'])): ?> Última actividad: <?= e(date('d/m/Y H:i:s', (int) $worker['updated_at'])) ?>.<?php endif; ?>
    Los envíos confirmados continúan aunque cierres el navegador.
</div>
<div class="stats-grid">
    <?php foreach (['pending'=>'Pendientes','scheduled'=>'Programados','paused'=>'Pausados','sending'=>'Procesando','sent'=>'Enviados / aceptados','failed'=>'Fallidos','tests'=>'Pruebas'] as $key=>$label): ?>
        <div class="stat-card"><strong><?= (int) ($summary[$key] ?? 0) ?></strong><span><?= e($label) ?></span></div>
    <?php endforeach; ?>
</div>
<?php if (($summary['attention'] ?? 0) || (($summary['sending'] ?? 0) && empty($worker['healthy']))): ?><div class="alert warning">Hay envíos cuyo resultado necesita revisión. Abrí su detalle antes de reenviarlos: podrían haber sido entregados.</div><?php endif; ?>
<section class="card"><h3>Envíos recientes</h3><div class="table-wrap"><table>
    <thead><tr><th>Envío</th><th>Canal</th><th>Progreso</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
    <?php foreach ($batches as $batch): ?>
        <tr><td><a href="activity.php?<?= e(http_build_query(['kind'=>$batch['kind'],'channel'=>$batch['channel'],'batch_id'=>$batch['batch_id']])) ?>"><?= e($batch['name']) ?></a></td>
        <td><?= $batch['channel']==='email' ? 'Email' : 'WhatsApp' ?></td>
        <td><progress max="<?= (int) $batch['total'] ?>" value="<?= (int) $batch['processed'] ?>"></progress> <?= (int) $batch['processed'] ?> / <?= (int) $batch['total'] ?><?php if ($batch['failed']): ?><br><?= (int) $batch['failed'] ?> fallidos<?php endif; ?></td>
        <td><?= e(ActivityRepository::label($batch['batch_status'])) ?></td><td>
        <form method="post" class="actions"><?= csrf_field() ?><input type="hidden" name="kind" value="<?= e($batch['kind']) ?>"><input type="hidden" name="channel" value="<?= e($batch['channel']) ?>"><input type="hidden" name="batch_id" value="<?= (int) $batch['batch_id'] ?>">
            <?php if (in_array($batch['batch_status'],['queued','processing'],true)): ?><button name="action" value="pause" class="btn secondary">Pausar</button><?php endif; ?>
            <?php if ($batch['batch_status']==='paused'): ?><button name="action" value="resume">Continuar</button><?php endif; ?>
            <?php if (in_array($batch['batch_status'],['queued','processing','paused'],true)): ?><button name="action" value="stop" class="btn danger" data-confirm-stop>Detener</button><?php endif; ?>
        </form></td></tr>
    <?php endforeach; ?>
    <?php if (!$batches): ?><tr><td colspan="5">No hay envíos para estos filtros.</td></tr><?php endif; ?>
    </tbody></table></div></section>
<section class="card"><h3>Destinatarios y resultados</h3><div class="table-wrap"><table>
    <thead><tr><th>Cliente</th><th>Destino</th><th>Envío</th><th>Programado</th><th>Resultado</th></tr></thead><tbody>
    <?php foreach ($result['rows'] as $row): ?><tr><td><?= e($row['client_name']) ?></td><td><?= e($row['recipient']) ?><br><small><?= e($row['channel']) ?></small></td>
        <td><?= e($row['name']) ?></td><td><?= e($row['scheduled_at']) ?></td><td><?= e(ActivityRepository::label($row['status'])) ?><?= $row['is_test'] ? ' · PRUEBA' : '' ?>
        <?php if ($row['last_error']): ?><details><summary>Ver detalle</summary><?= e($row['last_error']) ?></details><?php endif; ?></td></tr><?php endforeach; ?>
    <?php if (!$result['rows']): ?><tr><td colspan="5">No hay destinatarios para estos filtros.</td></tr><?php endif; ?>
    </tbody></table></div><div class="pagination">
    <?php if ($page>1): ?><a class="btn secondary" href="?<?= e(http_build_query(array_diff_key($filters,['branch'=>true])+['page'=>$page-1])) ?>">Anterior</a><?php endif; ?>
    <span>Página <?= $page ?> · <?= $result['total'] ?> resultados</span>
    <?php if ($page*50<$result['total']): ?><a class="btn secondary" href="?<?= e(http_build_query(array_diff_key($filters,['branch'=>true])+['page'=>$page+1])) ?>">Siguiente</a><?php endif; ?>
</div></section>
<?php if (!$fragment): ?>
</div>
<script src="assets/js/activity.js" defer></script>
<?php require __DIR__ . '/app/layout/footer.php'; endif; ?>
