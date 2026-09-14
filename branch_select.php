<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::currentUser();
if (!$user) {
    redirect('login.php');
}

$error = '';
$branches = BranchRepository::forUser((int) $user['id'], true);
$currentBranchId = (int) (BranchRepository::currentId() ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        BranchRepository::selectForUser((int) $user['id'], normalize_int(post_string('branch_id'), 0, 1));
        redirect('index.php');
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
    <title>Sucursal | <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/png" href="favicon.png">
    <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="auth-page">
    <main class="auth-shell">
        <section class="auth-card branch-select-card">
            <div class="auth-brand">
                <span class="brand-mark"><img src="favicon.png" alt=""></span>
                <h1>SendMails</h1>
            </div>

            <?php foreach (flashes() as $item): ?>
                <div class="alert <?= e($item['type']) ?>"><?= e($item['message']) ?></div>
            <?php endforeach; ?>
            <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

            <?php if (!$branches): ?>
                <div class="alert warning">Tu usuario no tiene sucursales activas asignadas. Contacta a un administrador.</div>
                <div class="auth-links">
                    <?php if (Auth::isAdmin()): ?>
                        <a href="branches.php">Administrar sucursales</a>
                    <?php endif; ?>
                    <a href="logout.php">Salir</a>
                </div>
            <?php else: ?>
                <form method="post" class="form-grid auth-form" data-wait-form>
                    <?= csrf_field() ?>
                    <div class="field full">
                        <label for="branch_id">Sucursal</label>
                        <select id="branch_id" name="branch_id" required autofocus>
                            <option value="">Seleccionar</option>
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?= e((string) $branch['id']) ?>"<?= selected((string) $branch['id'], (string) $currentBranchId) ?>>
                                    <?= e((string) $branch['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field full">
                        <button type="submit">Ingresar</button>
                    </div>
                    <div class="auth-links">
                        <a href="logout.php">Salir</a>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
