<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$pageTitle = 'Sucursales';
$pageSubtitle = 'Bases operativas disponibles y permisos por usuario.';
$error = '';
$branches = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if (post_string('action') !== 'delete') {
            throw new RuntimeException('Accion no valida.');
        }

        BranchRepository::delete(normalize_int(post_string('branch_id'), 0, 1));
        flash('success', 'Sucursal eliminada.');
        redirect('branches.php');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

try {
    $branches = BranchRepository::all();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

require __DIR__ . '/app/layout/header.php';
?>

<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

<section class="card">
    <div class="toolbar">
        <div>
            <h2>Sucursales</h2>
            <p class="hint">Las contrasenas se guardan cifradas y no se muestran en pantalla.</p>
        </div>
        <a class="btn small" href="branch_edit.php" data-wait>Nueva sucursal</a>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Nombre</th>
                <th>Servidor</th>
                <th>Base</th>
                <th>Usuario DB</th>
                <th>Estado</th>
                <th>Acciones</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($branches as $branch): ?>
                <tr>
                    <td>
                        <strong><?= e((string) $branch['name']) ?></strong>
                        <?php if (trim((string) ($branch['notes'] ?? '')) !== ''): ?>
                            <br><span class="muted"><?= e((string) $branch['notes']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= e((string) $branch['server']) ?>
                        <?php if (!empty($branch['port'])): ?><br><span class="muted">Puerto <?= e((string) $branch['port']) ?></span><?php endif; ?>
                    </td>
                    <td><?= e((string) $branch['database_name']) ?></td>
                    <td><?= e((string) $branch['username']) ?></td>
                    <td><span class="status <?= (bool) $branch['is_active'] ? 'active' : 'failed' ?>"><?= (bool) $branch['is_active'] ? 'activa' : 'inactiva' ?></span></td>
                    <td>
                        <div class="table-actions">
                            <a class="btn small secondary" href="branch_edit.php?id=<?= e((string) $branch['id']) ?>" data-wait>Editar</a>
                            <form method="post" class="inline-action-form" onsubmit="return confirm('Eliminar esta sucursal? Esta accion no se puede deshacer si no tiene historial asociado.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="branch_id" value="<?= e((string) $branch['id']) ?>">
                                <button type="submit" class="btn small danger">Eliminar</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$branches): ?>
                <tr><td colspan="6" class="empty">No hay sucursales cargadas.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/app/layout/footer.php'; ?>
