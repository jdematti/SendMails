<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$pageTitle = 'Historial de correos';
$pageSubtitle = 'Consultá qué correos se enviaron y el motivo de los errores.';
$error = '';
$logs = [];
$filters = [
    'client' => query_string('client'),
    'email' => query_string('email'),
    'date' => query_string('date'),
    'subject' => query_string('subject'),
];
$allowedLimits = [20, 50, 100];
$limit = normalize_int(query_string('limit', '20'), 20, 20, 100);
if (!in_array($limit, $allowedLimits, true)) {
    $limit = 20;
}

try {
    $logs = QueueRepository::recentLogs($limit, $filters);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

require __DIR__ . '/app/layout/header.php';
?>

<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

<section class="card">
    <form method="get" class="logs-filter-bar" data-wait-form>
        <div class="field">
            <label for="client">Cliente</label>
            <input id="client" name="client" value="<?= e($filters['client']) ?>" placeholder="Razon social o codigo">
        </div>
        <div class="field">
            <label for="email">Mail</label>
            <input id="email" name="email" value="<?= e($filters['email']) ?>" placeholder="destinatario@dominio.com">
        </div>
        <div class="field">
            <label for="date">Fecha</label>
            <input id="date" name="date" type="date" value="<?= e($filters['date']) ?>">
        </div>
        <div class="field">
            <label for="subject">Asunto</label>
            <input id="subject" name="subject" value="<?= e($filters['subject']) ?>" placeholder="Texto del asunto">
        </div>
        <div class="field">
            <label for="limit">Mostrar</label>
            <select id="limit" name="limit">
                <?php foreach ($allowedLimits as $option): ?>
                    <option value="<?= e((string) $option) ?>"<?= selected((string) $option, (string) $limit) ?>><?= e((string) $option) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field action-field">
            <label>&nbsp;</label>
            <div class="actions">
                <button type="submit">Buscar</button>
                <a class="btn secondary" href="logs.php" data-wait>Limpiar</a>
            </div>
        </div>
    </form>

    <p class="hint"><?= e((string) count($logs)) ?> registros mostrados.</p>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Fecha</th>
                <th>Cliente</th>
                <th>Email</th>
                <th>Asunto</th>
                <th>SMTP</th>
                <th>Estado</th>
                <th>Error</th>
                <th>Duracion</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= e(format_datetime($log['created_at'])) ?></td>
                    <td><?= e($log['client_name']) ?><br><span class="muted"><?= e($log['client_code']) ?></span></td>
                    <td><?= e($log['email_to']) ?></td>
                    <td><?= e($log['email_subject']) ?></td>
                    <td><?= e($log['smtp_host']) ?></td>
                    <td><span class="status <?= e($log['status']) ?>"><?= e($log['status']) ?></span></td>
                    <td><?= e($log['error_message']) ?></td>
                    <td><?= e($log['duration_ms'] !== null ? number_format(((int) $log['duration_ms']) / 1000, 2, ',', '.') . ' s' : '-') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$logs): ?>
                <tr><td colspan="8" class="empty">No hay logs.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/app/layout/footer.php'; ?>
