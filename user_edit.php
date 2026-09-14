<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$id = normalize_int(query_string('id'), 0, 0);
$isNew = $id <= 0;
$pageTitle = $isNew ? 'Nuevo usuario' : 'Editar usuario';
$pageSubtitle = 'Alta y mantenimiento de usuarios del sistema.';
$error = '';
$branches = [];
$selectedBranchIds = [];
$user = [
    'id' => null,
    'username' => '',
    'full_name' => '',
    'email' => '',
    'role' => 'Usuario',
    'is_active' => 1,
    'must_change_password' => 1,
];

try {
    $branches = BranchRepository::active();
    if (!$isNew) {
        $found = UserRepository::find($id);
        if (!$found) {
            throw new RuntimeException('Usuario no encontrado.');
        }
        $user = $found;
        $selectedBranchIds = BranchRepository::idsForUser($id);
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $user = [
        'id' => $isNew ? null : $id,
        'username' => post_string('username'),
        'full_name' => post_string('full_name'),
        'email' => post_string('email'),
        'role' => post_string('role', 'Usuario'),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'must_change_password' => $isNew ? 1 : (int) ($user['must_change_password'] ?? 0),
    ];
    $postedBranchIds = $_POST['branch_ids'] ?? [];
    $selectedBranchIds = array_values(array_filter(
        array_map('intval', is_array($postedBranchIds) ? $postedBranchIds : []),
        static fn (int $value): bool => $value > 0
    ));

    try {
        $temporaryPassword = (string) ($_POST['temporary_password'] ?? '');
        if ($isNew) {
            $newUserId = UserRepository::create(
                (string) $user['username'],
                (string) $user['full_name'],
                (string) $user['email'],
                (string) $user['role'],
                $temporaryPassword,
                true
            );
            BranchRepository::saveUserBranches($newUserId, $selectedBranchIds);
            flash('success', 'Usuario creado. Debera cambiar la contraseña al ingresar.');
        } else {
            UserRepository::update(
                $id,
                (string) $user['username'],
                (string) $user['full_name'],
                (string) $user['email'],
                (string) $user['role'],
                (bool) $user['is_active']
            );
            BranchRepository::saveUserBranches($id, $selectedBranchIds);

            if (trim($temporaryPassword) !== '') {
                UserRepository::setPassword($id, $temporaryPassword, true);
                flash('success', 'Usuario actualizado y contraseña temporal asignada.');
            } else {
                flash('success', 'Usuario actualizado.');
            }
        }

        redirect('users.php');
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
            <label for="username">Usuario</label>
            <input id="username" name="username" value="<?= e($user['username']) ?>" required autocomplete="off">
        </div>
        <div class="field">
            <label for="full_name">Nombre</label>
            <input id="full_name" name="full_name" value="<?= e($user['full_name']) ?>" required>
        </div>
        <div class="field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="<?= e($user['email']) ?>" required>
        </div>
        <div class="field">
            <label for="role">Rol</label>
            <select id="role" name="role">
                <option value="Usuario"<?= selected('Usuario', (string) $user['role']) ?>>Usuario</option>
                <option value="Admin"<?= selected('Admin', (string) $user['role']) ?>>Admin</option>
            </select>
        </div>
        <div class="field full">
            <label for="temporary_password"><?= $isNew ? 'Contraseña temporal' : 'Nueva contraseña temporal' ?></label>
            <input id="temporary_password" name="temporary_password" type="password" autocomplete="new-password"<?= $isNew ? ' required' : '' ?>>
            <p class="hint"><?= $isNew ? 'El usuario debera cambiarla al ingresar por primera vez.' : 'Completar solo si necesitas resetear la contraseña. El usuario debera cambiarla al ingresar.' ?></p>
        </div>
        <div class="field full">
            <label class="hint"><input type="checkbox" name="is_active" value="1"<?= checked((bool) $user['is_active']) ?>> Usuario activo</label>
        </div>
        <div class="field full">
            <label>Sucursales permitidas</label>
            <?php if (!$branches): ?>
                <p class="hint">No hay sucursales activas cargadas.</p>
            <?php else: ?>
                <div class="checkbox-grid">
                    <?php foreach ($branches as $branch): ?>
                        <label class="hint">
                            <input type="checkbox" name="branch_ids[]" value="<?= e((string) $branch['id']) ?>"<?= checked(in_array((int) $branch['id'], $selectedBranchIds, true)) ?>>
                            <?= e((string) $branch['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="field full actions">
            <button type="submit">Guardar usuario</button>
            <a class="btn secondary" href="users.php" data-wait>Volver</a>
        </div>
    </form>
</section>

<?php require __DIR__ . '/app/layout/footer.php'; ?>
