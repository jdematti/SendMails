<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$pageTitle = 'Plantillas de facturas';
$pageSubtitle = 'Alta y edicion de plantillas HTML para envios de facturas.';
$templates = [];
$error = '';

try {
    Schema::ensure();
    $templates = InvoiceRepository::templates();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

require __DIR__ . '/app/layout/header.php';
?>

<?php if ($error): ?>
    <div class="alert error"><?= e($error) ?></div>
<?php else: ?>
    <section class="card">
        <div class="toolbar">
            <h2>Plantillas de facturas disponibles</h2>
            <a class="btn" href="invoice_template_edit.php" data-wait>Nueva plantilla</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Asunto</th>
                        <th>Remitente</th>
                        <th>Modo test</th>
                        <th>Estado</th>
                        <th>Creada</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($templates as $template): ?>
                    <tr>
                        <td><?= e((string) $template['name']) ?></td>
                        <td><?= e((string) $template['subject']) ?></td>
                        <td>
                            <?php
                            $sender = trim((string) ($template['from_email'] ?? ''));
                            if ($sender === '') {
                                $sender = 'SMTP de la sucursal';
                            }
                            ?>
                            <?= e($sender) ?>
                        </td>
                        <td><span class="status <?= !empty($template['test_mode']) ? '' : 'active' ?>"><?= !empty($template['test_mode']) ? 'activo' : 'inactivo' ?></span></td>
                        <td><span class="status <?= !empty($template['is_active']) ? 'active' : '' ?>"><?= !empty($template['is_active']) ? 'activa' : 'inactiva' ?></span></td>
                        <td><?= e(format_datetime($template['created_at'] ?? null)) ?></td>
                        <td>
                            <div class="actions">
                                <a class="btn small secondary" href="invoice_template_edit.php?source_id=<?= e((string) $template['id']) ?>" data-wait>Duplicar</a>
                                <a class="btn small secondary" href="invoice_template_edit.php?id=<?= e((string) $template['id']) ?>" data-wait>Editar</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$templates): ?>
                    <tr><td colspan="7" class="empty">No hay plantillas de facturas cargadas.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/app/layout/footer.php'; ?>
