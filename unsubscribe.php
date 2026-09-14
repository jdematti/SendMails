<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$token = query_string('token');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = post_string('token');
}

$error = '';
$payload = null;
$result = null;

try {
    if ($token === '') {
        throw new RuntimeException('El link de baja esta incompleto.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $result = UnsubscribeRepository::unsubscribeWithToken(
            $token,
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
        );
        $payload = $result['payload'];
    } else {
        $payload = UnsubscribeRepository::payloadFromToken($token);
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$pageTitle = 'Cancelar suscripción';
$email = is_array($payload) ? (string) ($payload['email'] ?? '') : '';
$clientName = is_array($payload) ? (string) ($payload['client_name'] ?? '') : '';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= APP_NAME ?></title>
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<main class="auth-page">
    <section class="auth-shell">
        <div class="auth-card unsubscribe-card">
            <div class="auth-brand">
                <span class="brand-mark"><img src="favicon.png" alt=""></span>
                <div>
                    <h1><?= e($pageTitle) ?></h1>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert error"><?= e($error) ?></div>
                <p class="hint">Si el problema continua, responde el correo recibido para solicitar la baja manualmente.</p>
            <?php elseif ($result): ?>
                <div class="alert success">
                    <?= $result['created'] ? 'La suscripcion fue cancelada.' : 'Esta direccion ya estaba dada de baja.' ?>
                </div>
                <p>
                    <?= e($email) ?> no recibira nuevas campanas desde <?= e(APP_NAME) ?>.
                </p>
            <?php else: ?>
                <p>
                    Vas a dejar de recibir correos de campana en la direccion:
                </p>
                <p class="unsubscribe-email"><?= e($email) ?></p>
                <?php if ($clientName !== ''): ?>
                    <p class="hint">Cliente: <?= e($clientName) ?></p>
                <?php endif; ?>

                <form method="post" class="form-grid auth-form" data-wait-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <button type="submit" class="btn danger">Cancelar suscripción</button>
                </form>
            <?php endif; ?>
        </div>
    </section>
</main>

<div class="wait-modal" id="waitModal" aria-hidden="true">
    <div class="wait-dialog" role="status" aria-live="polite">
        <span class="wait-spinner"></span>
        <span>Espere...</span>
    </div>
</div>
<script>
(() => {
    const modal = document.getElementById('waitModal');
    const showWait = () => {
        if (!modal) return;
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
    };

    document.querySelectorAll('form[data-wait-form]').forEach((form) => {
        form.addEventListener('submit', () => showWait());
    });
})();
</script>
</body>
</html>
