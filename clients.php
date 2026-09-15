<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$pageTitle = 'Clientes';
$pageSubtitle = 'Buscá clientes y elegí quiénes pueden recibir campañas.';
$term = query_string('q');
$subscription = query_string('subscription');
if (!in_array($subscription, ['', 'subscribed', 'unsubscribed'], true)) {
    $subscription = '';
}
$delivery = query_string('delivery');
if (!in_array($delivery, ['', 'enabled', 'excluded'], true)) {
    $delivery = '';
}
$allowedLimits = [20, 50, 100];
$limit = normalize_int(query_string('limit', '20'), 20, 20, 100);
if (!in_array($limit, $allowedLimits, true)) {
    $limit = 20;
}
$clients = [];
$subscriptionStatus = [];
$exclusionStatus = [];
$error = '';
$currentQuery = (string) ($_SERVER['QUERY_STRING'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        if (post_string('action') === 'toggle_exclusion') {
            EmailExclusionRepository::setExcluded([
                'email' => post_string('email'),
                'oid' => post_string('client_oid'),
                'codigo_cliente' => post_string('client_code'),
                'razon_social' => post_string('client_name'),
            ], isset($_POST['excluded']));
            flash('success', isset($_POST['excluded']) ? 'Cliente excluido de envios.' : 'Cliente habilitado para envios.');
        }
        redirect('clients.php' . ($currentQuery !== '' ? '?' . $currentQuery : ''));
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

try {
    Schema::ensure();
    $clients = ClientRepository::search($term, $limit, '', $subscription, $delivery);
    $subscriptionStatus = UnsubscribeRepository::statusByEmail(array_column($clients, 'email'));
    $exclusionStatus = EmailExclusionRepository::statusByEmail(array_column($clients, 'email'));
} catch (Throwable $e) {
    $error = $e->getMessage();
}

require __DIR__ . '/app/layout/header.php';
?>

<?php if ($error): ?>
    <div class="alert error"><?= e($error) ?></div>
<?php else: ?>
    <section class="card">
        <form method="get" class="toolbar" data-wait-form>
            <div class="field client-search-field">
                <label for="q">Buscar</label>
                <input id="q" name="q" value="<?= e($term) ?>" placeholder="Razon social, codigo, email o ID">
            </div>
            <div class="field">
                <label for="limit">Mostrar</label>
                <select id="limit" name="limit">
                    <?php foreach ($allowedLimits as $option): ?>
                        <option value="<?= e((string) $option) ?>"<?= selected((string) $option, (string) $limit) ?>><?= e((string) $option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="subscription">Suscripcion</label>
                <select id="subscription" name="subscription">
                    <option value=""<?= selected('', $subscription) ?>>Todos</option>
                    <option value="subscribed"<?= selected('subscribed', $subscription) ?>>Suscritos</option>
                    <option value="unsubscribed"<?= selected('unsubscribed', $subscription) ?>>Dados de baja</option>
                </select>
            </div>
            <div class="field">
                <label for="delivery">Envios</label>
                <select id="delivery" name="delivery">
                    <option value=""<?= selected('', $delivery) ?>>Todos</option>
                    <option value="enabled"<?= selected('enabled', $delivery) ?>>Habilitados</option>
                    <option value="excluded"<?= selected('excluded', $delivery) ?>>Excluidos</option>
                </select>
            </div>
            <div class="actions">
                <button type="submit">Buscar</button>
                <a class="btn secondary" href="clients.php" data-wait>Limpiar</a>
            </div>
        </form>

        <p class="hint"><?= e((string) count($clients)) ?> registros mostrados.</p>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Codigo</th>
                    <th>Razon social</th>
                    <th>Plan contratado</th>
                    <th>Email</th>
                    <th>Suscripcion</th>
                    <th>Envios</th>
                    <th>Telefono movil</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($clients as $client): ?>
<?php
                    $email = (string) ($client['email'] ?? '');
                    $normalizedEmail = UnsubscribeRepository::normalizeEmail($email);
                    $isUnsubscribed = $normalizedEmail !== '' && (bool) ($subscriptionStatus[$normalizedEmail] ?? false);
                    $isExcluded = $normalizedEmail !== '' && (bool) ($exclusionStatus[$normalizedEmail] ?? false);
                    $subscriptionLabel = $normalizedEmail === '' ? 'Sin email' : ($isUnsubscribed ? 'Dado de baja' : 'Suscrito');
                    $subscriptionClass = $normalizedEmail === '' ? 'no-email' : ($isUnsubscribed ? 'unsubscribed' : 'subscribed');
                    $deliveryLabel = $normalizedEmail === '' ? 'Sin email' : ($isExcluded ? 'Excluido' : 'Habilitado');
                    $deliveryClass = $normalizedEmail === '' ? 'no-email' : ($isExcluded ? 'excluded' : 'enabled');
                    ?>
                    <tr>
                        <td><?= e($client['codigo_cliente']) ?></td>
                        <td><?= e($client['razon_social']) ?></td>
                        <td><?= e($client['plan_contratado']) ?></td>
                        <td><?= e($client['email']) ?></td>
                        <td><span class="status <?= e($subscriptionClass) ?>"><?= e($subscriptionLabel) ?></span></td>
                        <td>
                            <form method="post" action="clients.php<?= $currentQuery !== '' ? '?' . e($currentQuery) : '' ?>" class="inline-toggle-form" data-wait-form>
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle_exclusion">
                                <input type="hidden" name="email" value="<?= e($email) ?>">
                                <input type="hidden" name="client_oid" value="<?= e((string) ($client['oid'] ?? '')) ?>">
                                <input type="hidden" name="client_code" value="<?= e((string) ($client['codigo_cliente'] ?? '')) ?>">
                                <input type="hidden" name="client_name" value="<?= e((string) ($client['razon_social'] ?? '')) ?>">
                                <label class="table-check">
                                    <input type="checkbox" name="excluded" value="1"<?= checked($isExcluded) ?><?= $normalizedEmail === '' ? ' disabled' : '' ?> onchange="this.form.requestSubmit()">
                                    <span class="status <?= e($deliveryClass) ?>"><?= e($deliveryLabel) ?></span>
                                </label>
                            </form>
                        </td>
                        <td><?= e($client['telefono_movil']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$clients): ?>
                    <tr><td colspan="7" class="empty">No se encontraron clientes.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/app/layout/footer.php'; ?>
