<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$pageTitle = 'Base de datos';
$pageSubtitle = 'Conexion SQL Server parametrizable desde pantalla.';
$config = Database::loadConfig();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $config = [
        'server' => post_string('server'),
        'database' => post_string('database'),
        'username' => post_string('username'),
        'password' => (string) ($_POST['password'] ?? ''),
        'encrypt' => post_string('encrypt', 'no'),
        'trust_server_certificate' => isset($_POST['trust_server_certificate']),
    ];

    try {
        Database::test($config);
        if (post_string('action') === 'save') {
            Database::saveConfig($config);
            Schema::ensure();
            flash('success', 'Conexion guardada y tablas del sistema verificadas.');
            redirect('config_db.php');
        }
        flash('success', 'Conexion SQL Server probada correctamente.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
}

require __DIR__ . '/app/layout/header.php';
?>

<section class="card">
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <div class="field">
            <label for="server">Servidor</label>
            <input id="server" name="server" value="<?= e($config['server'] ?? '') ?>" required placeholder="SERVIDOR\INSTANCIA o host,puerto">
        </div>
        <div class="field">
            <label for="database">Base de datos</label>
            <input id="database" name="database" value="<?= e($config['database'] ?? '') ?>" required>
        </div>
        <div class="field">
            <label for="username">Usuario</label>
            <input id="username" name="username" value="<?= e($config['username'] ?? '') ?>" required autocomplete="off">
        </div>
        <div class="field">
            <label for="password">Contrasena</label>
            <input id="password" name="password" type="password" value="<?= e($config['password'] ?? '') ?>" autocomplete="off">
        </div>
        <div class="field">
            <label for="encrypt">Encriptacion</label>
            <select id="encrypt" name="encrypt">
                <option value="no"<?= selected('no', (string) ($config['encrypt'] ?? 'no')) ?>>No</option>
                <option value="yes"<?= selected('yes', (string) ($config['encrypt'] ?? 'no')) ?>>Si</option>
            </select>
        </div>
        <div class="field">
            <label>&nbsp;</label>
            <label class="hint"><input type="checkbox" name="trust_server_certificate" value="1"<?= checked((bool) ($config['trust_server_certificate'] ?? true)) ?>> Confiar en certificado del servidor</label>
        </div>
        <div class="field full">
            <div class="actions">
                <button type="submit" name="action" value="test">Probar conexion</button>
                <button type="submit" name="action" value="save">Guardar y crear tablas</button>
            </div>
            <p class="hint">El JSON se guarda en <strong>storage/db_config.json</strong>. La carpeta storage queda bloqueada por .htaccess.</p>
        </div>
    </form>
</section>

<?php require __DIR__ . '/app/layout/footer.php'; ?>
