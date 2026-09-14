<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $reset = UserRepository::createPasswordReset(
            post_string('identifier'),
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
        );

        if ($reset) {
            $url = Auth::baseUrl() . '/reset_password.php?token=' . rawurlencode((string) $reset['token']);
            MailerService::sendPasswordReset(
                (string) $reset['user']['email'],
                (string) $reset['user']['full_name'],
                $url
            );
        }

        flash('success', 'Si el usuario existe y esta activo, enviaremos un correo con instrucciones.');
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
    <title>Recuperar contraseña | <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="auth-page">
    <main class="auth-shell">
        <section class="auth-card">
            <div class="auth-brand">
                <span class="brand-mark"><img src="favicon.png" alt=""></span>
                <h1>Recuperar contraseña</h1>
            </div>

            <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

            <form method="post" class="form-grid auth-form" data-wait-form>
                <?= csrf_field() ?>
                <div class="field full">
                    <label for="identifier">Usuario o email</label>
                    <input id="identifier" name="identifier" value="<?= e(post_string('identifier')) ?>" autocomplete="username" required autofocus>
                </div>
                <div class="field full">
                    <button type="submit">Enviar link de recuperación</button>
                </div>
                <div class="auth-links">
                    <a href="login.php">Volver al ingreso</a>
                </div>
            </form>
        </section>
    </main>
</body>
</html>
