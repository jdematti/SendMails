<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$pageTitle = 'Plantillas';
$pageSubtitle = 'Alta y edicion de plantillas HTML con variables.';
$templates = [];
$error = '';

try {
    Schema::ensure();
    $templates = TemplateRepository::all(false);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

require __DIR__ . '/app/layout/header.php';
$draftKind = 'template';
$draftEditor = 'template_edit.php';
require __DIR__ . '/app/layout/template_drafts.php';
?>

<?php if ($error): ?>
    <div class="alert error"><?= e($error) ?></div>
<?php else: ?>
    <section class="card">
        <div class="toolbar">
            <h2>Plantillas disponibles</h2>
            <a class="btn" href="template_edit.php" data-wait>Nueva plantilla</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Nombre</th><th>Asunto</th><th>Estado</th><th>Creada</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($templates as $template): ?>
                    <tr>
                        <td><?= e($template['name']) ?></td>
                        <td><?= e($template['subject']) ?></td>
                        <td><span class="status <?= $template['is_active'] ? 'active' : '' ?>"><?= $template['is_active'] ? 'activa' : 'inactiva' ?></span></td>
                        <td><?= e(format_datetime($template['created_at'])) ?></td>
                        <td>
                            <div class="actions">
                                <a class="btn small secondary" href="template_edit.php?source_id=<?= e((string) $template['id']) ?>" data-wait>Duplicar</a>
                                <a class="btn small secondary" href="template_edit.php?id=<?= e((string) $template['id']) ?>" data-wait>Editar</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$templates): ?>
                    <tr><td colspan="5" class="empty">No hay plantillas cargadas.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/app/layout/footer.php'; ?>
