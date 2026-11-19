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
    $campaignTemplate=(int)TemplateRepository::all(true)[0]['id'];
    $invoiceTemplate=(int)InvoiceRepository::defaultTemplate()['id'];
    foreach ([['SendMail_Campaigns','SendMail_Queue','campaign_id','SendMail_Templates',$campaignTemplate,'campaign'],
        ['SendMail_InvoiceBatches','SendMail_InvoiceQueue','batch_id','SendMail_InvoiceTemplates',$invoiceTemplate,'invoice']] as [$table,$queueTable,$foreign,$templates,$templateId,$kind]) {
        $extra=$kind==='campaign'?',send_mode':''; $value=$kind==='campaign'?",'individual'":'';
        $pdo->exec("WITH n AS (SELECT TOP 120 ROW_NUMBER() OVER(ORDER BY (SELECT NULL)) AS id FROM sys.all_objects)
            INSERT INTO dbo.$table(branch_id,template_id,name,status,created_at$extra)
                SELECT CASE WHEN id=120 THEN 2 ELSE 1 END,$templateId,'Administracion fixture '+CONVERT(varchar(10),id),
                    CASE id%3 WHEN 0 THEN 'completed' WHEN 1 THEN 'paused' ELSE 'queued' END,DATEADD(second,id,'20261119')$value FROM n;");
        $extra=$kind==='invoice'?',invoice_id,snb,invoice_url':'';
        $value=$kind==='invoice'?",'fixture','00001','https://example.invalid'":'';
        $pdo->exec("INSERT INTO dbo.$queueTable(branch_id,$foreign,template_id,email_to,status,scheduled_at$extra)
            SELECT b.branch_id,b.id,b.template_id,'fixture@example.invalid',s.status,'20990101'$value
            FROM dbo.$table b CROSS JOIN (SELECT 'pending' AS status UNION ALL SELECT 'sent') s
            WHERE b.name LIKE 'Administracion fixture %';");
        foreach (['','paused','completed'] as $status) foreach ([1,20,50] as $limit) {
            $where='b.branch_id=1'; $params=[];
            if($status!=='') { $where.=' AND b.status=:status'; $params[':status']=$status; }
            // Original aggregate-before-limit query as an independent reference.
            $stmt=$pdo->prepare("SELECT TOP $limit b.id,COUNT(q.id) AS queue_total,
                SUM(CASE WHEN q.status='pending' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN q.status='sent' THEN 1 ELSE 0 END) AS sent_count
                FROM dbo.$table b INNER JOIN dbo.$templates t ON t.id=b.template_id
                LEFT JOIN dbo.$queueTable q ON q.$foreign=b.id AND q.branch_id=b.branch_id
                WHERE $where GROUP BY b.id,b.created_at ORDER BY b.created_at DESC,b.id DESC");
            $stmt->execute($params); $expected=$stmt->fetchAll();
            $actual=$kind==='campaign'?QueueRepository::campaigns($status,$limit):InvoiceRepository::invoiceBatches($status,$limit);
            $actual=array_map(static fn(array $row):array=>array_intersect_key($row,array_flip(['id','queue_total','pending_count','sent_count'])),$actual);
            foreach($actual as &$row)ksort($row);unset($row);
            foreach($expected as &$row)ksort($row);unset($row);
            check($actual===$expected,'Administracion: limites, filtros, orden y conteos equivalentes para '.$kind);
        }
    }
    check(!array_key_exists('password_hash',UserRepository::all()[0]),'Listado de usuarios sin hashes de contrasena');
    $metadata=InvoiceRepository::templates(true);
    check($metadata && !array_key_exists('html_body',$metadata[0]) && !array_key_exists('attachments_json',$metadata[0]),'Selector de copia sin contenido de plantillas');
    foreach(['from_email','test_mode','created_at'] as $field)check(array_key_exists($field,$metadata[0]),'Metadatos del listado de plantillas: '.$field);
    $template->execute([':meta'=>bin2hex(random_bytes(8)),':name'=>'Inactiva fixture',':category'=>'MARKETING']);
    $inactive=(int)$template->fetchColumn();
    $pdo->exec("UPDATE dbo.SendMail_WhatsAppTemplates SET is_active=0 WHERE id=$inactive");
    $template->execute([':meta'=>bin2hex(random_bytes(8)),':name'=>'Rechazada fixture',':category'=>'MARKETING']);
    $rejected=(int)$template->fetchColumn();
    $pdo->exec("UPDATE dbo.SendMail_WhatsAppTemplates SET status='REJECTED' WHERE id=$rejected");
    foreach(['campaign','invoice'] as $kind) {
        $expected=array_values(array_filter(WhatsAppRepository::templates($kind),[WhatsAppRepository::class,'isTemplateReady']));
        check(WhatsAppRepository::readyTemplates($kind)===$expected,'Selector WhatsApp equivalente, sin traer rechazadas o inactivas');
    }
    echo "MANAGEMENT OK: campañas y facturas, agregados, fechas, filtros, sucursal y listado sin snapshots. Sin envios externos.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['branch_id'] = $originalBranch;
}
