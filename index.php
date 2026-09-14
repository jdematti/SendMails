<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$pageTitle = 'Dashboard';
$pageSubtitle = 'Resumen de plantillas, clientes, cola y envios realizados.';
$dbConfigured = Database::configExists();
$stats = [];
$templates = [];
$logs = [];
$campaigns = [];
$error = '';

if ($dbConfigured) {
    try {
        Schema::ensure();
        $stats = QueueRepository::dashboardStats();
        $templates = TemplateRepository::recent(5);
        $logs = QueueRepository::recentLogs(8);
        $campaigns = QueueRepository::recentCampaigns(5);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

require __DIR__ . '/app/layout/header.php';
?>

<?php if (!$dbConfigured): ?>
    <div class="alert warning">Primero carga la conexion SQL Server desde Base de datos. La configuracion se guarda en JSON y luego se crean las tablas propias del sistema en esa base.</div>
<?php elseif ($error): ?>
    <div class="alert error"><?= e($error) ?></div>
<?php else: ?>
    <section class="grid cols-3">
        <div class="card metric"><strong><?= e((string) $stats['templates']) ?></strong><span>Plantillas</span></div>
        <div class="card metric"><strong><?= e((string) $stats['sent']) ?></strong><span>Mails enviados</span></div>
        <div class="card metric"><strong><?= e((string) $stats['pending']) ?></strong><span>En cola</span></div>
        <div class="card metric"><strong><?= e((string) $stats['clients_with_email']) ?></strong><span>Clientes con email</span></div>
        <div class="card metric"><strong><?= e((string) $stats['unsubscribed']) ?></strong><span>Bajas de suscripcion</span></div>
        <div class="card metric"><strong><?= e((string) $stats['clients_without_email']) ?> / <?= e((string) $stats['clients_invalid_email']) ?></strong><span>Clientes sin mail / email inválido</span></div>
    </section>

    <section class="grid cols-2" style="margin-top:14px;">
        <div class="card">
            <div class="toolbar">
                <h2>Ultimas 5 plantillas</h2>
                <a class="btn small" href="template_edit.php">Nueva plantilla</a>
            </div>
            <?php if (!$templates): ?>
                <div class="empty">No hay plantillas activas.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Nombre</th><th>Asunto</th><th>Creada</th></tr></thead>
                        <tbody>
                        <?php foreach ($templates as $template): ?>
                            <tr>
                                <td><a href="template_edit.php?id=<?= e((string) $template['id']) ?>"><?= e($template['name']) ?></a></td>
                                <td><?= e($template['subject']) ?></td>
                                <td><?= e(format_datetime($template['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="toolbar">
                <h2>Ultimas 5 campañas</h2>
                <a class="btn small secondary" href="send.php">Preparar envio</a>
            </div>
            <?php if (!$campaigns): ?>
                <div class="empty">Todavía no se cargaron campañas.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Campaña</th><th>Plantilla</th><th>Estado</th><th>Enviados</th></tr></thead>
                        <tbody>
                        <?php foreach ($campaigns as $campaign): ?>
                            <tr>
                                <td><?= e($campaign['name']) ?></td>
                                <td><?= e($campaign['template_name']) ?></td>
                                <td><span class="status <?= e($campaign['status']) ?>"><?= e($campaign['status']) ?></span></td>
                                <td><?= e((string) $campaign['total_sent']) ?> / <?= e((string) $campaign['total_queued']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="card" style="margin-top:14px;">
        <div class="toolbar">
            <h2>Ultimos envios</h2>
            <a class="btn small secondary" href="logs.php">Ver logs</a>
        </div>
        <?php if (!$logs): ?>
            <div class="empty">No hay registros de envio.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Fecha</th><th>Cliente</th><th>Email</th><th>Asunto</th><th>Estado</th></tr></thead>
                    <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= e(format_datetime($log['created_at'])) ?></td>
                            <td><?= e($log['client_name']) ?></td>
                            <td><?= e($log['email_to']) ?></td>
                            <td><?= e($log['email_subject']) ?></td>
                            <td><span class="status <?= e($log['status']) ?>"><?= e($log['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<script>
window.setTimeout(function () {
    window.location.reload();
}, 60000);
</script>

<?php require __DIR__ . '/app/layout/footer.php'; ?>
