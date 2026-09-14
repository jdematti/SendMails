<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$token = query_string('token');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = post_string('token');
}

$error = '';
$reset = null;

try {
    $reset = UserRepository::findValidReset($token);
    if (!$reset) {
        $error = 'El link de recuperacion no es valido o ya vencio.';
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    verify_csrf();
    try {
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        if ($password !== $confirm) {
            throw new InvalidArgumentException('Las contraseñas no coinciden.');
        }

        UserRepository::resetPasswordWithToken($token, $password);
        flash('success', 'Contraseña actualizada. Ya puedes ingresar.');
        redirect('login.php');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nueva contraseña | <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="auth-page">
    <main class="auth-shell">
        <section class="auth-card">
            <div class="auth-brand">
                <span class="brand-mark"><img src="favicon.png" alt=""></span>
                <h1>Nueva contraseña</h1>
            </div>

            <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

            <?php if ($reset): ?>
                <form method="post" class="form-grid auth-form" data-wait-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <div class="field full">
                        <label for="password">Nueva contraseña</label>
                        <input id="password" name="password" type="password" autocomplete="new-password" required autofocus>
                    </div>
                    <div class="field full">
                        <label for="password_confirm">Confirmar contraseña</label>
                        <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required>
                    </div>
                    <div class="field full">
                        <button type="submit">Guardar contraseña</button>
                    </div>
                </form>
            <?php endif; ?>

            <div class="auth-links">
                <a href="login.php">Volver al ingreso</a>
            </div>
        </section>
    </main>
</body>
</html>
