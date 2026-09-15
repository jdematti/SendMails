<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$pageTitle = 'Usuarios';
$pageSubtitle = 'Creá usuarios y definí a qué sucursales pueden acceder.';
$error = '';
$users = [];
$appSettings = Settings::app();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        Settings::saveApp([
            'base_url' => post_string('base_url'),
        ]);
        flash('success', 'Configuracion de seguridad guardada.');
        redirect('users.php');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

try {
    $users = UserRepository::all();
    $appSettings = Settings::app();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

require __DIR__ . '/app/layout/header.php';
?>

<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

<section class="grid users-admin-grid">
    <div class="card">
        <div class="toolbar">
            <h2>Usuarios</h2>
            <a class="btn small" href="user_edit.php" data-wait>Nuevo usuario</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Usuario</th><th>Email</th><th>Rol</th><th>Estado</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($users as $item): ?>
                    <tr>
                        <td>
                            <strong><?= e($item['username']) ?></strong><br>
                            <span class="muted"><?= e($item['full_name']) ?></span>
                        </td>
                        <td><?= e($item['email']) ?></td>
                        <td><span class="status <?= e(strtolower((string) $item['role'])) ?>"><?= e($item['role']) ?></span></td>
                        <td><span class="status <?= (bool) $item['is_active'] ? 'active' : 'failed' ?>"><?= (bool) $item['is_active'] ? 'activo' : 'inactivo' ?></span></td>
                        <td><a class="btn small secondary" href="user_edit.php?id=<?= e((string) $item['id']) ?>" data-wait>Editar</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$users): ?>
                    <tr><td colspan="5" class="empty">No hay usuarios cargados.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <details class="card disclosure">
        <summary>Configuración de enlaces de recuperación</summary>
        <form method="post" class="form-grid" data-wait-form>
            <?= csrf_field() ?>
            <div class="field full">
                <label for="base_url">URL base del sistema</label>
                <input id="base_url" name="base_url" value="<?= e($appSettings['base_url'] ?? '') ?>" placeholder="https://localhost/SendMails">
                <p class="hint">Se usa para generar los links de recuperacion de contraseña. Si queda vacia, se detecta automaticamente.</p>
            </div>
            <div class="field full">
                <button type="submit">Guardar configuracion</button>
            </div>
        </form>
    </details>
</section>

<?php require __DIR__ . '/app/layout/footer.php'; ?>
