<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$pageTitle = 'WhatsApp / Meta';
$currentBranch = BranchRepository::selected();
$pageSubtitle = 'Conexion de WhatsApp Business para ' . (string) ($currentBranch['name'] ?? 'la sucursal activa') . '.';
$error = '';
$result = '';
$config = Settings::whatsapp();
$callbackUrl = Auth::baseUrl() . '/whatsapp_webhook.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $posted = [
            'graph_version' => post_string('graph_version', 'v25.0'),
            'business_id' => post_string('business_id'),
            'app_id' => post_string('app_id'),
            'waba_id' => post_string('waba_id'),
            'phone_number_id' => post_string('phone_number_id'),
            'display_phone' => post_string('display_phone'),
            'country_code' => post_string('country_code', '54'),
            'test_phone' => post_string('test_phone'),
            'access_token' => (string) ($_POST['access_token'] ?? ''),
            'app_secret' => (string) ($_POST['app_secret'] ?? ''),
            'verify_token' => (string) ($_POST['verify_token'] ?? ''),
            'is_enabled' => isset($_POST['is_enabled']),
        ];
        Settings::saveWhatsapp($posted);
        $config = Settings::whatsapp();
        $action = post_string('action', 'save');

        if ($action === 'test_connection') {
            $response = WhatsAppBusinessService::testConnection($config);
            $verifiedName = trim((string) ($response['verified_name'] ?? ''));
            $displayPhone = trim((string) ($response['display_phone_number'] ?? ''));
            $result = 'Conexion correcta con Meta' . ($verifiedName !== '' ? ': ' . $verifiedName : '') . ($displayPhone !== '' ? ' (' . $displayPhone . ')' : '') . '.';
        } elseif ($action === 'send_test') {
            $phone = WhatsAppPhone::normalizeRequired((string) $config['test_phone'], (string) $config['country_code']);
            $response = WhatsAppBusinessService::sendHelloWorld($config, $phone);
            $messageId = trim((string) ($response['messages'][0]['id'] ?? ''));
            $result = 'Mensaje de prueba aceptado por Meta para +' . $phone . ($messageId !== '' ? '. ID: ' . $messageId : '') . '.';
        } elseif ($action === 'sync_templates') {
            $count = WhatsAppRepository::syncTemplates();
            flash('success', 'Configuracion guardada y ' . $count . ' plantillas sincronizadas desde Meta.');
            redirect('whatsapp_templates.php');
        } else {
            flash('success', 'Configuracion de WhatsApp guardada para la sucursal.');
            redirect('whatsapp.php');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $config = array_merge($config, $posted ?? []);
    }
}

require __DIR__ . '/app/layout/header.php';
?>

<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<?php if ($result): ?><div class="alert success"><?= e($result) ?></div><?php endif; ?>

<section class="card">
    <p class="hint"><strong>Importante:</strong> esta pantalla no registra ni migra el numero existente. Primero se completa en Meta Business el alta compatible con el WhatsApp Business que ya usa la sucursal; luego se cargan aqui los identificadores entregados por Meta.</p>
    <form method="post" class="form-grid" data-wait-form autocomplete="off">
        <?= csrf_field() ?>
        <div class="field">
            <label for="display_phone">Numero de la sucursal</label>
            <input id="display_phone" name="display_phone" value="<?= e((string) $config['display_phone']) ?>" placeholder="Ej: +54 9 11 1234-5678">
        </div>
        <div class="field">
            <label for="country_code">Codigo de pais</label>
            <input id="country_code" name="country_code" value="<?= e((string) $config['country_code']) ?>" inputmode="numeric" required>
        </div>
        <div class="field">
            <label for="business_id">Meta Business ID</label>
            <input id="business_id" name="business_id" value="<?= e((string) $config['business_id']) ?>">
        </div>
        <div class="field">
            <label for="app_id">App ID</label>
            <input id="app_id" name="app_id" value="<?= e((string) $config['app_id']) ?>">
        </div>
        <div class="field">
            <label for="waba_id">WhatsApp Business Account ID</label>
            <input id="waba_id" name="waba_id" value="<?= e((string) $config['waba_id']) ?>" required>
        </div>
        <div class="field">
            <label for="phone_number_id">Phone Number ID</label>
            <input id="phone_number_id" name="phone_number_id" value="<?= e((string) $config['phone_number_id']) ?>" required>
        </div>
        <div class="field">
            <label for="graph_version">Version Graph API</label>
            <input id="graph_version" name="graph_version" value="<?= e((string) $config['graph_version']) ?>" required>
        </div>
        <div class="field">
            <label for="test_phone">Numero receptor de prueba</label>
            <input id="test_phone" name="test_phone" value="<?= e((string) $config['test_phone']) ?>" placeholder="5491112345678">
        </div>
        <div class="field full">
            <label for="access_token">Token de acceso permanente</label>
            <input id="access_token" name="access_token" type="password" placeholder="Dejar vacio para conservar el cargado">
        </div>
        <div class="field">
            <label for="app_secret">App Secret</label>
            <input id="app_secret" name="app_secret" type="password" placeholder="Dejar vacio para conservarlo">
            <p class="hint">Se utiliza para verificar la firma de cada webhook.</p>
        </div>
        <div class="field">
            <label for="verify_token">Verify Token del webhook</label>
            <input id="verify_token" name="verify_token" value="<?= e((string) $config['verify_token']) ?>" placeholder="Se genera al guardar">
        </div>
        <div class="field full">
            <label for="callback_url">Callback URL para Meta</label>
            <input id="callback_url" value="<?= e($callbackUrl) ?>" readonly>
            <p class="hint">Debe ser una URL publica HTTPS. Copiala en la configuracion de Webhooks de la aplicacion de Meta junto con el Verify Token.</p>
        </div>
        <div class="field full">
            <label><input type="checkbox" name="is_enabled" value="1"<?= checked(!empty($config['is_enabled'])) ?>> Habilitar envios de WhatsApp en esta sucursal</label>
        </div>
        <div class="field full">
            <div class="actions">
                <button type="submit" name="action" value="save">Guardar</button>
                <button class="btn secondary" type="submit" name="action" value="test_connection">Probar conexion</button>
                <button class="btn secondary" type="submit" name="action" value="send_test">Enviar hello_world</button>
                <button class="btn secondary" type="submit" name="action" value="sync_templates">Guardar y sincronizar plantillas</button>
            </div>
        </div>
    </form>
</section>

<?php require __DIR__ . '/app/layout/footer.php'; ?>
