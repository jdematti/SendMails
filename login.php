<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (Auth::attempt(post_string('identifier'), (string) ($_POST['password'] ?? ''))) {
        Auth::redirectAfterLogin();
    }

    $error = 'Usuario/email o contraseña incorrectos.';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ingresar | <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="auth-page">
    <main class="auth-shell">
        <section class="auth-card">
            <div class="auth-brand">
                <span class="brand-mark"><img src="favicon.png" alt=""></span>
                <h1>SendMails</h1>
            </div>

            <?php foreach (flashes() as $item): ?>
                <div class="alert <?= e($item['type']) ?>"><?= e($item['message']) ?></div>
            <?php endforeach; ?>
            <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

            <form method="post" class="form-grid auth-form" data-wait-form>
                <?= csrf_field() ?>
                <div class="field full">
                    <label for="identifier">Usuario o email</label>
                    <input id="identifier" name="identifier" value="<?= e(post_string('identifier')) ?>" autocomplete="username" required autofocus>
                </div>
                <div class="field full">
                    <label for="password">Contraseña</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required>
                </div>
                <div class="field full">
                    <button type="submit">Ingresar</button>
                </div>
                <div class="auth-links">
                    <a href="forgot_password.php">Recuperar contraseña</a>
                </div>
            </form>
        </section>
    </main>
</body>
</html>
