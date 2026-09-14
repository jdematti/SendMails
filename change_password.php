<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$pageTitle = 'Cambiar contraseña';
$pageSubtitle = 'Actualiza tu clave de acceso al sistema.';
$error = '';
$user = Auth::currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');
        if ($newPassword !== $confirm) {
            throw new InvalidArgumentException('Las contraseñas nuevas no coinciden.');
        }

        UserRepository::changePassword((int) $user['id'], (string) ($_POST['current_password'] ?? ''), $newPassword);
        flash('success', 'Contraseña actualizada correctamente.');
        redirect('index.php');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

require __DIR__ . '/app/layout/header.php';
?>

<?php if (Auth::mustChangePassword($user)): ?>
    <div class="alert warning">Debes cambiar tu contraseña temporal para continuar.</div>
<?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

<section class="card auth-settings-card">
    <form method="post" class="form-grid" data-wait-form>
        <?= csrf_field() ?>
        <div class="field full">
            <label for="current_password">Contraseña actual</label>
            <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
        </div>
        <div class="field">
            <label for="new_password">Nueva contraseña</label>
            <input id="new_password" name="new_password" type="password" autocomplete="new-password" required>
        </div>
        <div class="field">
            <label for="new_password_confirm">Confirmar nueva contraseña</label>
            <input id="new_password_confirm" name="new_password_confirm" type="password" autocomplete="new-password" required>
        </div>
        <div class="field full actions">
            <button type="submit">Guardar contraseña</button>
            <?php if (!Auth::mustChangePassword($user)): ?>
                <a class="btn secondary" href="index.php" data-wait>Volver</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<?php require __DIR__ . '/app/layout/footer.php'; ?>
