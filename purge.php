<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
Auth::requireAdmin();
$pageTitle = 'Purgar historial';
$pageSubtitle = 'Eliminar historial antiguo de campañas y facturas, con revisión previa.';
$filters = ['cutoff'=>PurgeRepository::maxCutoff(), 'branch_id'=>0, 'kind'=>'', 'channel'=>''];
$preview = null; $error = '';
foreach ($_SESSION['purge_previews'] ?? [] as $token=>$item) if (time() - (int) $item['created_at'] > 900) unset($_SESSION['purge_previews'][$token]);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    set_time_limit(120);
    try {
        if (post_string('action') === 'preview') {
            $filters = PurgeRepository::filters($_POST);
            $preview = PurgeRepository::preview($filters);
            if (count($_SESSION['purge_previews'] ?? []) >= 5) array_shift($_SESSION['purge_previews']);
            $_SESSION['purge_previews'][$preview['token']] = $preview;
        } elseif (post_string('action') === 'confirm') {
            $preview = $_SESSION['purge_previews'][post_string('token')] ?? null;
            if (!$preview) throw new RuntimeException('La vista previa venció. Calculala nuevamente.');
            $filters = $preview['filters'];
            if (post_string('acknowledge') !== 'yes') throw new RuntimeException('Confirmá que revisaste los registros a eliminar.');
            $receipt = PurgeRepository::execute($preview);
            flash('success', 'Purga completada: ' . $receipt['totals']['batches'] . ' lotes, ' . $receipt['totals']['recipients'] . ' destinatarios y ' . $receipt['totals']['records'] . ' registros eliminados.');
            redirect('purge.php');
        } else { throw new InvalidArgumentException('Acción inválida.'); }
    } catch (Throwable $e) {
        error_log('SendMails purga [' . post_string('action') . ']: ' . $e->getMessage());
        $error = $e->getMessage(); $preview = null;
    }
}
$branches = BranchRepository::all();
$recent = []; $receiptError = '';
try { $recent = PurgeRepository::recent(); } catch (Throwable $e) {
    error_log('SendMails purga [comprobantes]: ' . $e->getMessage());
    $receiptError = 'No se pudieron consultar las últimas purgas. Volvé a abrir esta página para comprobar el resultado antes de repetir una operación.';
}
require __DIR__ . '/app/layout/header.php';
?>
<?php if ($error): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="card">
    <p>Se incluyen lotes <strong>completados o detenidos</strong>, sus destinatarios, copias del mensaje y registros asociados de email o WhatsApp. Se conservan al menos los últimos <strong>90 días</strong> y todos los envíos pendientes, en curso o pausados.</p>
    <form method="post" id="purgeFilters" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="preview">
        <div class="field"><label for="purgeBranch">Sucursal</label><select name="branch_id" id="purgeBranch"><option value="0">Todas las sucursales</option><?php foreach ($branches as $branch): ?><option value="<?= (int)$branch['id'] ?>"<?= selected((string)$branch['id'],(string)$filters['branch_id']) ?>><?= e($branch['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="purgeCutoff">Finalizados antes de</label><input type="date" name="cutoff" id="purgeCutoff" max="<?= e(PurgeRepository::maxCutoff()) ?>" value="<?= e($filters['cutoff']) ?>" required></div>
        <div class="field"><label for="purgeKind">Tipo</label><select name="kind" id="purgeKind"><option value="">Campañas y facturas</option><option value="campaign"<?= selected('campaign',$filters['kind']) ?>>Campañas</option><option value="invoice"<?= selected('invoice',$filters['kind']) ?>>Facturas</option></select></div>
        <div class="field"><label for="purgeChannel">Canal</label><select name="channel" id="purgeChannel"><option value="">Email y WhatsApp</option><option value="email"<?= selected('email',$filters['channel']) ?>>Email</option><option value="whatsapp"<?= selected('whatsapp',$filters['channel']) ?>>WhatsApp</option></select></div>
        <div class="actions"><button type="submit" id="previewPurge">Calcular vista previa</button></div>
    </form>
    <p class="hint">La purga afecta solo al historial de SendMails. Conserva clientes, facturas de origen, plantillas, borradores, bajas y exclusiones. Cada operación incluye hasta <?= PurgeRepository::LIMIT ?> lotes y aproximadamente <?= number_format(PurgeRepository::ROW_BUDGET,0,',','.') ?> destinatarios y registros asociados en total, empezando por los más antiguos. Un lote que supera ese volumen se procesa solo.</p>
</section>
<?php if ($preview): ?>
<section class="card" id="purgeReview">
    <h2>Vista previa</h2>
    <div class="stats-grid"><?php foreach (['batches'=>'Lotes','recipients'=>'Destinatarios','records'=>'Registros asociados'] as $key=>$label): ?><div class="stat-card"><strong><?= (int)$preview['totals'][$key] ?></strong><span><?= e($label) ?></span></div><?php endforeach; ?></div>
    <?php if ($preview['rows']): ?>
        <div class="table-wrap"><table><thead><tr><th>Sucursal</th><th>Envío</th><th>Tipo / canal</th><th>Finalización</th><th>Estado</th><th>Destinatarios</th><th>Registros</th></tr></thead><tbody>
        <?php foreach ($preview['rows'] as $row): ?><tr><td><?= e($row['branch_name']) ?></td><td><?= e($row['name']) ?></td><td><?= $row['kind'] === 'campaign' ? 'Campaña' : 'Facturas' ?> / <?= e($row['channel']) ?></td><td><?= e(format_datetime($row['finished_at'])) ?></td><td><?= e(ActivityRepository::label($row['status'])) ?></td><td><?= (int)$row['recipients'] ?></td><td><?= (int)$row['records'] ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php if ($preview['more']): ?><p class="hint">Hay más lotes antiguos. Después de esta operación podés calcular otra vista previa para continuar.</p><?php endif; ?>
        <form method="post" id="purgeConfirm"><?= csrf_field() ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="token" value="<?= e($preview['token']) ?>">
            <label class="purge-ack"><input type="checkbox" name="acknowledge" value="yes" required> Revisé la lista y entiendo que estos registros se eliminarán definitivamente.</label>
            <button type="submit" class="btn danger" id="confirmPurge">Eliminar <?= (int)$preview['totals']['batches'] ?> lotes de la vista previa</button>
        </form>
    <?php else: ?><p>No hay lotes que cumplan estos filtros y la antigüedad mínima.</p><?php endif; ?>
</section>
<?php endif; ?>
<section class="card"><h2>Últimas purgas</h2>
<?php if ($receiptError): ?><div class="alert error" role="alert"><?= e($receiptError) ?></div><?php endif; ?>
<div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Administrador</th><th>Lotes</th><th>Destinatarios</th><th>Registros</th></tr></thead><tbody>
<?php foreach ($recent as $receipt): ?><tr><td><?= e(format_datetime($receipt['at'])) ?></td><td><?= e($receipt['user_name']) ?></td><td><?= (int)$receipt['totals']['batches'] ?></td><td><?= (int)$receipt['totals']['recipients'] ?></td><td><?= (int)$receipt['totals']['records'] ?></td></tr><?php endforeach; ?>
<?php if (!$recent && !$receiptError): ?><tr><td colspan="5">Todavía no se realizaron purgas.</td></tr><?php endif; ?></tbody></table></div></section>
<script src="assets/js/purge.js" defer></script>
<?php require __DIR__ . '/app/layout/footer.php'; ?>
