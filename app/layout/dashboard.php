<?php
$pageTitle = 'Inicio';
$pageSubtitle = 'Actividad de la sucursal y próximos envíos.';
$filters = ActivityRepository::filters([]);
$summary = ActivityRepository::summary($filters);
$batches = array_slice(ActivityRepository::batches($filters), 0, 8);
require __DIR__ . '/header.php';
?>
<section class="quick-start" aria-label="Empezar un envío">
    <a class="task-choice" href="send.php"><strong>Crear campaña <span aria-hidden="true">→</span></strong><span>Promociones y novedades para tus clientes.</span></a>
    <a class="task-choice" href="invoices.php"><strong>Preparar facturas <span aria-hidden="true">→</span></strong><span>Elegí un vencimiento y enviá las facturas.</span></a>
</section>
<p class="workflow-help">En 3 pasos: <strong>elegí destinatarios → revisá el mensaje → confirmá.</strong> Podés guardar un borrador y seguir después.</p>
<?php require __DIR__ . '/activity_metrics.php'; ?>
<?php if (!empty($summary['failed'])): ?><p class="attention-link"><a href="activity.php?status=failed">Revisar <?= (int) $summary['failed'] ?> destinatarios con error →</a></p><?php endif; ?>
<section class="card"><div class="section-heading"><h2>Últimos envíos</h2><a href="activity.php">Ver todos →</a></div><div class="table-wrap bounded-table" tabindex="0" aria-label="Últimos envíos"><table><thead><tr><th>Envío</th><th>Tipo</th><th>Canal</th><th>Progreso</th><th>Estado</th></tr></thead><tbody>
<?php foreach ($batches as $batch): ?><tr><td><a href="activity.php?<?= e(http_build_query(['kind'=>$batch['kind'],'channel'=>$batch['channel'],'batch_id'=>$batch['batch_id']])) ?>"><?= e($batch['name']) ?></a></td>
<td><?= $batch['kind']==='invoice' ? 'Facturas' : 'Campaña' ?></td><td><?= $batch['channel']==='email' ? 'Email' : 'WhatsApp' ?></td><td><?= (int) $batch['processed'] ?> / <?= (int) $batch['total'] ?></td><td><?= e(ActivityRepository::label($batch['batch_status'])) ?></td></tr><?php endforeach; ?>
<?php if (!$batches): ?><tr><td colspan="5">Todavía no hay envíos en esta sucursal.</td></tr><?php endif; ?>
</tbody></table></div><details class="inline-help"><summary>Cómo leer los estados de entrega</summary><p class="hint">Enviado indica que el proveedor recibió el mensaje. En WhatsApp, Entregado y Leído aparecen cuando Meta confirma esos estados.</p></details></section>
<?php require __DIR__ . '/footer.php'; ?>
