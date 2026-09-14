<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$pageTitle = 'Plantillas de WhatsApp';
$pageSubtitle = 'Plantillas aprobadas por Meta y variables utilizadas por SendMails.';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $action = post_string('action');
        if ($action === 'sync') {
            $count = WhatsAppRepository::syncTemplates();
            flash('success', $count . ' plantillas sincronizadas desde Meta.');
            redirect('whatsapp_templates.php');
        }
        if ($action === 'save_mapping') {
            $templateId = normalize_int(post_string('template_id'), 0, 1);
            $variables = preg_split('/[\s,;]+/', post_string('variables')) ?: [];
            WhatsAppRepository::updateTemplateMapping($templateId, $variables, isset($_POST['is_active']));
            flash('success', 'Variables de la plantilla actualizadas.');
            redirect('whatsapp_templates.php');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

try {
    $templates = WhatsAppRepository::templates();
} catch (Throwable $e) {
    $templates = [];
    $error = $error !== '' ? $error : $e->getMessage();
}

require __DIR__ . '/app/layout/header.php';
?>

<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

<section class="card">
    <div class="toolbar">
        <div>
            <h2>Plantillas sincronizadas</h2>
            <p class="hint">MARKETING se usa para campañas. UTILITY se usa para facturas. Solo aparecen al enviar cuando estan aprobadas, activas y con todas sus variables asignadas.</p>
        </div>
        <div class="actions">
            <?php if (Auth::isAdmin()): ?><a class="btn secondary" href="whatsapp.php" data-wait>Configurar Meta</a><?php endif; ?>
            <form method="post" data-wait-form>
                <?= csrf_field() ?>
                <button type="submit" name="action" value="sync">Sincronizar desde Meta</button>
            </form>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead><tr><th>Plantilla</th><th>Uso</th><th>Estado Meta</th><th>Variables del cuerpo, en orden</th><th>Disponible</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($templates as $template): ?>
                <?php
                $formId = 'wa-template-' . (int) $template['id'];
                $bodyText = '';
                $components = json_decode((string) ($template['components_json'] ?? '[]'), true);
                foreach (is_array($components) ? $components : [] as $component) {
                    if (is_array($component) && strtoupper((string) ($component['type'] ?? '')) === 'BODY') {
                        $bodyText = trim((string) ($component['text'] ?? ''));
                        break;
                    }
                }
                ?>
                <tr>
                    <td><strong><?= e((string) $template['name']) ?></strong><br><span class="muted"><?= e((string) $template['language']) ?> · <?= e((string) $template['meta_template_id']) ?></span><?php if ($bodyText !== ''): ?><br><span class="muted"><?= e($bodyText) ?></span><?php endif; ?></td>
                    <td><?= (string) $template['category'] === 'UTILITY' ? 'Facturas' : ((string) $template['category'] === 'MARKETING' ? 'Campañas' : e((string) $template['category'])) ?></td>
                    <td><span class="status <?= (string) $template['status'] === 'APPROVED' ? 'active' : 'pending' ?>"><?= e((string) $template['status']) ?></span></td>
                    <td>
                        <input form="<?= e($formId) ?>" name="variables" value="<?= e(implode(', ', $template['variables'])) ?>" placeholder="<?= (int) $template['parameter_count'] ?> variables"<?= (int) $template['parameter_count'] === 0 ? ' readonly' : '' ?>>
                        <span class="muted"><?= (int) $template['parameter_count'] ?> parametro(s)</span>
                    </td>
                    <td><label><input form="<?= e($formId) ?>" type="checkbox" name="is_active" value="1"<?= checked(!empty($template['is_active'])) ?>> Activa</label></td>
                    <td>
                        <form id="<?= e($formId) ?>" method="post" data-wait-form>
                            <?= csrf_field() ?>
                            <input type="hidden" name="template_id" value="<?= (int) $template['id'] ?>">
                            <button class="btn small secondary" type="submit" name="action" value="save_mapping">Guardar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$templates): ?><tr><td colspan="6" class="empty">Todavia no hay plantillas. Configura la conexion y sincroniza desde Meta.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="hint">Variables de campañas: razon_social, codigo_cliente, plan_contratado, telefono_movil, localidad, provincia. Variables de facturas: client_name, snb, amount_label, due_date_label, invoice_id, invoice_url.</p>
</section>

<?php require __DIR__ . '/app/layout/footer.php'; ?>
