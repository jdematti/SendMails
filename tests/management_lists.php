<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/test_bootstrap.php';
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$pdo = Database::pdo();
Schema::ensure();
$originalBranch = $_SESSION['branch_id'];
$pdo->beginTransaction();
try {
    $_SESSION['branch_id'] = 1;
    // These are the queries used by both legacy management screens after migration.
    QueueRepository::campaigns();
    InvoiceRepository::invoiceBatches();
    WhatsAppRepository::batches('campaign');
    WhatsAppRepository::batches('invoice');

    $template = $pdo->prepare("INSERT INTO dbo.SendMail_WhatsAppTemplates
        (branch_id, meta_template_id, name, language, category, status, components_json, body_variables_json, is_active)
        OUTPUT INSERTED.id VALUES (1,:meta,:name,'es_AR',:category,'APPROVED','[]','[]',1)");
    $batch = $pdo->prepare("INSERT INTO dbo.SendMail_WhatsAppBatches
        (branch_id, source_type, template_id, name, status, total_queued, message_snapshot, created_at)
        OUTPUT INSERTED.id VALUES (:branch,:source,:template,:name,'paused',3,:snapshot,'2099-01-01')");
    $queue = $pdo->prepare("INSERT INTO dbo.SendMail_WhatsAppQueue
        (branch_id,batch_id,template_id,phone_to,status,scheduled_at)
        VALUES (1,:batch,:template,'5491100000001',:status,:scheduled)");
    $ids = [];
    foreach (['campaign'=>'MARKETING','invoice'=>'UTILITY'] as $source => $category) {
        $name = 'Listado fixture ' . $source;
        $template->execute([':meta'=>bin2hex(random_bytes(8)),':name'=>$name,':category'=>$category]);
        $templateId = (int) $template->fetchColumn();
        $params = [':branch'=>1,':source'=>$source,':template'=>$templateId,':name'=>$name,':snapshot'=>'{"html_body":"contenido privado del mensaje"}'];
        $batch->execute($params);
        $id = (int) $batch->fetchColumn();
        $ids[$source] = $id;
        foreach (['pending','sending','skipped'] as $i => $status) {
            $queue->execute([':batch'=>$id,':template'=>$templateId,':status'=>$status,':scheduled'=>'2099-01-0'.($i+1).' 10:00:00']);
        }
        $batch->execute(array_replace($params, [':branch'=>2,':name'=>'Otra sucursal']));
        $otherBranchId = (int) $batch->fetchColumn();
        $rows = WhatsAppRepository::batches($source, 'paused', 500);
        check(!in_array($otherBranchId, array_map('intval',array_column($rows,'id')), true), 'Debe respetar la sucursal');
        $matches = array_values(array_filter($rows, static fn(array $row): bool => (int) $row['id'] === $id));
        check(count($matches) === 1, 'Debe devolver una sola fila por lote');
        $row = $matches[0];
        check($row['source_type'] === $source && $row['template_name'] === $name && $row['language'] === 'es_AR', 'Metadatos del lote');
        check(!array_key_exists('message_snapshot', $row), 'El listado no debe cargar el contenido del mensaje');
        foreach (['pending_count','sending_count','skipped_count'] as $field) check((int) $row[$field] === 1, 'Conteo incorrecto: '.$field);
        check(str_starts_with($row['first_scheduled_at'], '2099-01-01') && str_starts_with($row['last_scheduled_at'], '2099-01-03'), 'Rango de fechas');
        check(count(WhatsAppRepository::batches($source, 'paused', 1)) === 1, 'Limite del listado');
        check(!in_array($id,array_map('intval',array_column(WhatsAppRepository::batches($source,'queued',500),'id')),true), 'Filtro de estado');
    }
    check(!in_array($ids['invoice'],array_map('intval',array_column(WhatsAppRepository::batches('campaign','paused',500),'id')),true), 'Campañas y facturas separadas');
    echo "MANAGEMENT OK: campañas y facturas, agregados, fechas, filtros, sucursal y listado sin snapshots. Sin envios externos.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['branch_id'] = $originalBranch;
}
