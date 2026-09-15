<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Only the remote Meta response is replaced; repository SQL runs against the temporary database.
final class WhatsAppBusinessService
{
    public static array $templates = [];
    public static function fetchTemplates(array $config): array { return self::$templates; }
}
require __DIR__ . '/test_bootstrap.php';
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$pdo = Database::pdo();
Schema::ensure();
$pdo->beginTransaction();
try {
    WhatsAppBusinessService::$templates = [[
        'id'=>'smoke-sync', 'name'=>'smoke_template', 'language'=>'es_AR', 'category'=>'MARKETING', 'status'=>'APPROVED',
        'components'=>[['type'=>'BODY', 'text'=>str_repeat('Texto á ', 600).'{{1}}']],
    ]];
    check(WhatsAppRepository::syncTemplates(1) === 1, 'Insertar plantilla desde respuesta ficticia');
    $template = $pdo->query("SELECT * FROM dbo.SendMail_WhatsAppTemplates WHERE branch_id=1 AND meta_template_id='smoke-sync'")->fetch();
    check(json_decode($template['components_json'], true)[0]['text'] === WhatsAppBusinessService::$templates[0]['components'][0]['text'], 'Conservar Unicode y contenido largo');
    WhatsAppRepository::updateTemplateMapping((int)$template['id'], ['email'], false);
    WhatsAppBusinessService::$templates[0]['name'] = 'smoke_updated';
    WhatsAppBusinessService::$templates[0]['status'] = 'PAUSED';
    check(WhatsAppRepository::syncTemplates(1) === 1, 'Actualizar plantilla existente');
    $updated = $pdo->query("SELECT * FROM dbo.SendMail_WhatsAppTemplates WHERE id=".(int)$template['id'])->fetch();
    check($updated['name'] === 'smoke_updated' && $updated['status'] === 'PAUSED', 'Actualizar campos de Meta');
    check(!$updated['is_active'] && json_decode($updated['body_variables_json'], true) === ['email'], 'Conservar mapeo y activacion local');
    check(WhatsAppRepository::syncTemplates(2) === 1, 'Mismo identificador de Meta en otra sucursal');
    check((int)$pdo->query("SELECT COUNT(*) FROM dbo.SendMail_WhatsAppTemplates WHERE meta_template_id='smoke-sync'")->fetchColumn() === 2, 'Sin duplicados dentro de cada sucursal');

    $eventAt = new DateTimeImmutable('2026-09-15 10:00:00');
    WhatsAppRepository::insertEvent(1, 'wamid.event-smoke', 'delivered', $eventAt, '{"fixture":true}');
    WhatsAppRepository::insertEvent(1, 'wamid.event-smoke', 'delivered', $eventAt, '{"fixture":true}');
    WhatsAppRepository::insertEvent(null, 'wamid.event-smoke', 'received', null, '{}');
    check((int)$pdo->query("SELECT COUNT(*) FROM dbo.SendMail_WhatsAppEvents WHERE provider_message_id='wamid.event-smoke'")->fetchColumn() === 2, 'Eventos repetidos sin duplicados y fecha nula admitida');

    $queue = $pdo->query("SELECT TOP 1 q.* FROM dbo.SendMail_WhatsAppQueue q INNER JOIN dbo.SendMail_WhatsAppBatches b ON b.id=q.batch_id WHERE b.source_type='campaign' AND q.branch_id=1 AND q.status='pending' ORDER BY q.id")->fetch();
    check(is_array($queue), 'Destinatario ficticio disponible');
    $id = (int)$queue['id'];
    $phone = (string)$queue['phone_to'];
    WhatsAppRepository::recordOptOut(1, $phone, 'campaign', 'BAJA ficticia');
    WhatsAppRepository::recordOptOut(1, $phone, 'campaign', 'STOP repetido');
    check(WhatsAppRepository::isOptedOut(1, $phone, 'campaign'), 'Baja registrada');
    check(!WhatsAppRepository::isOptedOut(1, $phone, 'invoice') && !WhatsAppRepository::isOptedOut(2, $phone, 'campaign'), 'Baja limitada a su sucursal y tipo');
    check($pdo->query("SELECT status FROM dbo.SendMail_WhatsAppQueue WHERE id=$id")->fetchColumn() === 'skipped', 'Excluir pendientes tras una baja');
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM dbo.SendMail_WhatsAppOptOuts WHERE branch_id=1 AND phone_to=:phone AND reason=:reason');
    $stmt->execute([':phone'=>$phone, ':reason'=>'STOP repetido']);
    check((int)$stmt->fetchColumn() === 1, 'Baja repetida actualiza sin duplicar');

    // Simulate provider acceptance and callbacks without invoking any transport.
    $pdo->exec("UPDATE dbo.SendMail_WhatsAppQueue SET status='pending' WHERE id=$id");
    check(WhatsAppRepository::markSending($id), 'Reservar destinatario ficticio');
    WhatsAppRepository::markAccepted($id, 'wamid.status-smoke', ['fixture'=>true]);
    foreach (['sent', 'delivered', 'read', 'sent', 'failed'] as $status) {
        WhatsAppRepository::updateProviderStatus('wamid.status-smoke', $status, $eventAt, 'Error ficticio atrasado');
    }
    $row=$pdo->query("SELECT * FROM dbo.SendMail_WhatsAppQueue WHERE id=$id")->fetch();
    check($row['status'] === 'read' && $row['delivered_at'] !== null && $row['read_at'] !== null, 'No retroceder estados ante callbacks atrasados');
    check((int)$pdo->query('SELECT total_read FROM dbo.SendMail_WhatsAppBatches WHERE id='.(int)$queue['batch_id'])->fetchColumn() >= 1, 'Actualizar totales del lote');
    echo "WHATSAPP SQL OK: sincronizacion simulada, altas/actualizaciones, Unicode, mapeos, sucursales, eventos, bajas repetidas y estados fuera de orden. Sin conexiones externas.\n";
} finally {
    $pdo->rollBack();
}
