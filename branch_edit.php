<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$id = normalize_int(query_string('id'), 0, 0);
$isNew = $id <= 0;
$pageTitle = $isNew ? 'Nueva sucursal' : 'Editar sucursal';
$pageSubtitle = 'Conexion SQL Server operativa para clientes y facturas.';
$error = '';
$branch = [
    'id' => null,
    'name' => '',
    'server' => '',
    'port' => '',
    'database_name' => '',
    'username' => '',
    'password' => '',
    'encrypt' => 'no',
    'trust_server_certificate' => 1,
    'is_active' => 1,
    'notes' => '',
];

try {
    if (!$isNew) {
        $found = BranchRepository::find($id);
        if (!$found) {
            throw new RuntimeException('Sucursal no encontrada.');
        }
        $branch = array_merge($branch, $found, ['password' => '']);
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $branch = [
        'id' => $isNew ? null : $id,
        'name' => post_string('name'),
        'server' => post_string('server'),
        'port' => post_string('port'),
        'database_name' => post_string('database_name'),
        'username' => post_string('username'),
        'password' => (string) ($_POST['password'] ?? ''),
        'encrypt' => post_string('encrypt', 'no'),
        'trust_server_certificate' => isset($_POST['trust_server_certificate']) ? 1 : 0,
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'notes' => post_string('notes'),
    ];

    try {
        $action = post_string('action', 'save');
        if ($action === 'test') {
            BranchRepository::test($branch, $isNew ? null : $id);
            flash('success', 'Conexion de sucursal probada correctamente.');
        } elseif ($isNew) {
            $newId = BranchRepository::create($branch);
            flash('success', 'Sucursal creada.');
            redirect('branch_edit.php?id=' . $newId);
        } else {
            BranchRepository::update($id, $branch);
            flash('success', 'Sucursal actualizada.');
            redirect('branches.php');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

require __DIR__ . '/app/layout/header.php';
?>

<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

<section class="card auth-settings-card">
    <form method="post" class="form-grid" data-wait-form>
        <?= csrf_field() ?>
        <div class="field">
            <label for="name">Nombre de sucursal</label>
            <input id="name" name="name" value="<?= e((string) $branch['name']) ?>" required>
        </div>
        <div class="field">
            <label for="server">Servidor</label>
            <input id="server" name="server" value="<?= e((string) $branch['server']) ?>" required placeholder="SERVIDOR\INSTANCIA o host">
        </div>
        <div class="field">
            <label for="port">Puerto</label>
            <input id="port" name="port" type="number" min="0" max="65535" value="<?= e((string) ($branch['port'] ?? '')) ?>" placeholder="Opcional">
        </div>
        <div class="field">
            <label for="database_name">Base de datos</label>
            <input id="database_name" name="database_name" value="<?= e((string) $branch['database_name']) ?>" required>
        </div>
        <div class="field">
            <label for="username">Usuario DB</label>
            <input id="username" name="username" value="<?= e((string) $branch['username']) ?>" required autocomplete="off">
        </div>
        <div class="field">
            <label for="password"><?= $isNew ? 'Contrasena DB' : 'Nueva contrasena DB' ?></label>
            <input id="password" name="password" type="password" autocomplete="new-password"<?= $isNew ? ' required' : '' ?>>
            <p class="hint"><?= $isNew ? 'Se guardara cifrada.' : 'Completar solo para cambiarla.' ?></p>
        </div>
        <div class="field">
            <label for="encrypt">Encriptacion</label>
            <select id="encrypt" name="encrypt">
                <option value="no"<?= selected('no', (string) $branch['encrypt']) ?>>No</option>
                <option value="yes"<?= selected('yes', (string) $branch['encrypt']) ?>>Si</option>
            </select>
        </div>
        <div class="field">
            <label>&nbsp;</label>
            <label class="hint"><input type="checkbox" name="trust_server_certificate" value="1"<?= checked((bool) $branch['trust_server_certificate']) ?>> Confiar en certificado del servidor</label>
        </div>
        <div class="field full">
            <label for="notes">Observaciones</label>
            <textarea id="notes" name="notes" rows="3"><?= e((string) ($branch['notes'] ?? '')) ?></textarea>
        </div>
        <div class="field full">
            <label class="hint"><input type="checkbox" name="is_active" value="1"<?= checked((bool) $branch['is_active']) ?>> Sucursal activa</label>
        </div>
        <div class="field full actions">
            <button type="submit" name="action" value="save">Guardar sucursal</button>
            <button type="submit" name="action" value="test" class="btn secondary">Probar conexion</button>
            <a class="btn secondary" href="branches.php" data-wait>Volver</a>
        </div>
    </form>
</section>

<?php require __DIR__ . '/app/layout/footer.php'; ?>
