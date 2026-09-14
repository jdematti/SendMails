<?php
$pageTitle = 'Inicio';
$pageSubtitle = 'Actividad de la sucursal y próximos envíos.';
$filters = ActivityRepository::filters([]);
$summary = ActivityRepository::summary($filters);
$batches = array_slice(ActivityRepository::batches($filters), 0, 8);
$worker = WorkerRuntime::status();
require __DIR__ . '/header.php';
?>
<div class="actions"><a class="btn" href="send.php">Crear campaña</a><a class="btn secondary" href="invoices.php">Preparar facturas</a><a class="btn secondary" href="activity.php">Ver seguimiento</a></div>
<div class="alert <?= !empty($worker['healthy']) ? 'success' : 'warning' ?>"><?= !empty($worker['healthy']) ? 'Procesamiento automático activo.' : 'Sin actividad reciente del proceso automático. Revisá la tarea SendMails Worker.' ?><?php if (!empty($worker['updated_at'])): ?> Última actividad: <?= e(date('d/m/Y H:i:s', (int) $worker['updated_at'])) ?>.<?php endif; ?></div>
<div class="stats-grid"><?php foreach (['pending'=>'Pendientes','scheduled'=>'Programados','paused'=>'Pausados','sending'=>'Procesando','sent'=>'Enviados / aceptados','failed'=>'Fallidos','tests'=>'Pruebas de facturas'] as $key=>$label): ?>
    <a class="stat-card" href="activity.php<?= $key === 'failed' ? '?status=failed' : '' ?>"><strong><?= (int) ($summary[$key] ?? 0) ?></strong><span><?= e($label) ?></span></a>
<?php endforeach; ?></div>
<section class="card"><h3>Actividad reciente</h3><div class="table-wrap"><table><thead><tr><th>Envío</th><th>Tipo</th><th>Canal</th><th>Progreso</th><th>Estado</th></tr></thead><tbody>
<?php foreach ($batches as $batch): ?><tr><td><a href="activity.php?<?= e(http_build_query(['kind'=>$batch['kind'],'channel'=>$batch['channel'],'batch_id'=>$batch['batch_id']])) ?>"><?= e($batch['name']) ?></a></td>
<td><?= $batch['kind']==='invoice' ? 'Facturas' : 'Campaña' ?></td><td><?= $batch['channel']==='email' ? 'Email' : 'WhatsApp' ?></td><td><?= (int) $batch['processed'] ?> / <?= (int) $batch['total'] ?></td><td><?= e(ActivityRepository::label($batch['batch_status'])) ?></td></tr><?php endforeach; ?>
<?php if (!$batches): ?><tr><td colspan="5">Todavía no hay envíos en esta sucursal.</td></tr><?php endif; ?>
</tbody></table></div><p class="hint">Aceptado por Meta no significa entregado. El seguimiento muestra la confirmación de entrega y lectura cuando está disponible.</p></section>
<?php require __DIR__ . '/footer.php'; ?>
