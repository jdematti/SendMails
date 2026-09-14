<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$pageTitle = 'SMTP';
$currentBranch = BranchRepository::selected();
$pageSubtitle = 'Configuracion del servidor de correo para ' . (string) ($currentBranch['name'] ?? 'la sucursal activa') . '.';
$error = '';
$smtp = Settings::smtp();
$app = Settings::app();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $smtp = Settings::normalizeSmtp([
            'host' => post_string('host'),
            'port' => post_string('port', '587'),
            'username' => post_string('username'),
            'password' => (string) ($_POST['password'] ?? ''),
            'encryption' => post_string('encryption', 'tls'),
            'from_email' => post_string('from_email'),
            'from_name' => post_string('from_name'),
            'reply_to' => post_string('reply_to'),
            'interval_seconds' => post_string('interval_seconds', '2'),
        ]);
        $app = Settings::normalizeApp([
            'base_url' => post_string('base_url'),
        ]);

        if (post_string('action') === 'send_test') {
            MailerService::sendTest($smtp, post_string('test_email'));
            flash('success', 'Correo de prueba enviado correctamente a ' . post_string('test_email') . '.');
            redirect('smtp.php');
        }

        Settings::saveSmtp($smtp);
        Settings::saveApp($app);
        flash('success', 'Configuracion SMTP guardada.');
        redirect('smtp.php');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

require __DIR__ . '/app/layout/header.php';
?>

<?php if (!Database::configExists()): ?>
    <div class="alert warning">Primero configura la base de datos.</div>
<?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

<section class="card">
    <form method="post" class="form-grid" data-wait-form>
        <?= csrf_field() ?>
        <div class="field">
            <label for="host">Servidor SMTP</label>
            <input id="host" name="host" value="<?= e($smtp['host']) ?>" required>
        </div>
        <div class="field">
            <label for="port">Puerto</label>
            <input id="port" name="port" type="number" min="1" max="65535" value="<?= e((string) $smtp['port']) ?>" required>
        </div>
        <div class="field">
            <label for="username">Usuario</label>
            <input id="username" name="username" value="<?= e($smtp['username']) ?>" autocomplete="off">
        </div>
        <div class="field">
            <label for="password">Contrasena</label>
            <input id="password" name="password" type="password" value="<?= e($smtp['password']) ?>" autocomplete="off">
        </div>
        <div class="field">
            <label for="encryption">Seguridad</label>
            <select id="encryption" name="encryption">
                <option value="tls"<?= selected('tls', (string) $smtp['encryption']) ?>>TLS</option>
                <option value="ssl"<?= selected('ssl', (string) $smtp['encryption']) ?>>SSL</option>
                <option value="none"<?= selected('none', (string) $smtp['encryption']) ?>>Ninguna</option>
            </select>
        </div>
        <div class="field">
            <label for="interval_seconds">Segundos entre cada envio</label>
            <input id="interval_seconds" name="interval_seconds" type="number" min="0" max="3600" value="<?= e((string) $smtp['interval_seconds']) ?>">
        </div>
        <div class="field">
            <label for="from_email">Email remitente</label>
            <input id="from_email" name="from_email" type="email" value="<?= e($smtp['from_email']) ?>" required>
        </div>
        <div class="field">
            <label for="from_name">Nombre remitente</label>
            <input id="from_name" name="from_name" value="<?= e($smtp['from_name']) ?>">
        </div>
        <div class="field full">
            <label for="reply_to">Email de respuesta</label>
            <input id="reply_to" name="reply_to" type="email" value="<?= e($smtp['reply_to']) ?>">
        </div>
        <div class="field full">
            <label for="base_url">URL publica del sistema</label>
            <input id="base_url" name="base_url" type="url" value="<?= e($app['base_url']) ?>" placeholder="https://tudominio.com/SendMails">
            <p class="hint">Se usa para generar el link de baja en campanas, especialmente cuando los envios salen desde el worker.</p>
        </div>
        <div class="field full">
            <label for="test_email">Email para prueba de envio</label>
            <div class="inline-test">
                <input id="test_email" name="test_email" type="email" value="<?= e(post_string('test_email')) ?>" placeholder="destino@dominio.com">
                <button type="submit" name="action" value="send_test">Enviar prueba</button>
            </div>
            <p class="hint">La prueba usa los valores cargados en esta pantalla, aunque todavia no los hayas guardado.</p>
        </div>
        <div class="field full">
            <button type="submit" name="action" value="save">Guardar SMTP</button>
        </div>
    </form>
</section>

<?php require __DIR__ . '/app/layout/footer.php'; ?>
