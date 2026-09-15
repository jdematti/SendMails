<div class="stats-grid compact-stats" aria-label="Resumen de destinatarios">
    <?php foreach (['pending'=>'Por enviar','sending'=>'En proceso','sent'=>'Enviados / aceptados','failed'=>'Con error'] as $key=>$label): ?>
        <div class="stat-card<?= $key === 'failed' && !empty($summary[$key]) ? ' needs-attention' : '' ?>">
            <strong><?= e(number_format((int) ($summary[$key] ?? 0), 0, ',', '.')) ?></strong><span><?= e($label) ?></span>
        </div>
    <?php endforeach; ?>
</div>
<p class="summary-extra"><span>Programados: <strong><?= (int) ($summary['scheduled'] ?? 0) ?></strong></span><span>Pausados: <strong><?= (int) ($summary['paused'] ?? 0) ?></strong></span><span>Pruebas de facturas: <strong><?= (int) ($summary['tests'] ?? 0) ?></strong></span></p>
