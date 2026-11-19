<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/test_bootstrap.php';
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
// Independent reference: the original full-history union, before projection,
// page materialization and aggregation before batch joins.
function referenceSource(): string {
    return "WITH activity AS (
        SELECT q.id,q.branch_id,q.campaign_id AS batch_id,'campaign' AS kind,'email' AS channel,
            c.name,c.status AS batch_status,q.client_name,q.email_to AS recipient,q.status,q.scheduled_at,q.created_at,q.last_error,q.is_test
        FROM dbo.SendMail_Queue q INNER JOIN dbo.SendMail_Campaigns c ON c.id=q.campaign_id AND c.branch_id=q.branch_id
        UNION ALL
        SELECT q.id,q.branch_id,q.batch_id,'invoice','email',b.name,b.status,q.client_name,q.email_to,q.status,q.scheduled_at,q.created_at,q.last_error,q.is_test
        FROM dbo.SendMail_InvoiceQueue q INNER JOIN dbo.SendMail_InvoiceBatches b ON b.id=q.batch_id AND b.branch_id=q.branch_id
        UNION ALL
        SELECT q.id,q.branch_id,q.batch_id,b.source_type,'whatsapp',b.name,b.status,q.client_name,q.phone_to,q.status,q.scheduled_at,q.created_at,q.last_error,0
        FROM dbo.SendMail_WhatsAppQueue q INNER JOIN dbo.SendMail_WhatsAppBatches b ON b.id=q.batch_id AND b.branch_id=q.branch_id) ";
}
function canonical(array $rows): array { foreach ($rows as &$row) ksort($row); unset($row); return $rows; }
$pdo = Database::pdo();
$database = (string) getenv('SENDMAILS_TEST_DATABASE');
if (!preg_match('/^SendMails_Agility_Test_[a-f0-9]{12}$/D', $database)) throw new RuntimeException('Base inesperada');
$compatibility = (int) $pdo->query('SELECT compatibility_level FROM sys.databases WHERE database_id=DB_ID()')->fetchColumn();
$whereMethod = new ReflectionMethod(ActivityRepository::class,'where');
try {
    $pdo->exec("ALTER DATABASE [$database] SET COMPATIBILITY_LEVEL = 100");
    $pdo->beginTransaction();
    $pdo->exec("WITH n AS (SELECT TOP 120 ROW_NUMBER() OVER (ORDER BY (SELECT NULL)) AS id FROM sys.all_objects)
        INSERT INTO dbo.SendMail_Campaigns(branch_id,template_id,name,send_mode,status)
            SELECT CASE WHEN id=120 THEN 2 ELSE 1 END,1,'Seguimiento fixture '+CONVERT(varchar(10),id),'individual',
                CASE id%4 WHEN 0 THEN 'queued' WHEN 1 THEN 'processing' WHEN 2 THEN 'paused' ELSE 'completed' END FROM n;
        WITH n AS (SELECT TOP 250 ROW_NUMBER() OVER (ORDER BY (SELECT NULL)) AS id FROM sys.all_objects)
        INSERT INTO dbo.SendMail_Queue(branch_id,campaign_id,template_id,client_name,email_to,status,scheduled_at,created_at,last_error,is_test)
            SELECT b.branch_id,b.id,b.template_id,'Destinatario fixture','fixture@example.invalid',
                CASE n.id%5 WHEN 0 THEN 'pending' WHEN 1 THEN 'sending' WHEN 2 THEN 'sent' WHEN 3 THEN 'failed' ELSE 'skipped' END,
                CASE WHEN n.id%2=0 THEN '20990101' ELSE '20200101' END,
                DATEADD(second,b.id*1000+n.id,'20261119'),
                CASE WHEN n.id%5=1 THEN REPLICATE(CAST(N'Error de prueba. ' AS nvarchar(max)),300) ELSE NULL END,
                CASE WHEN n.id%11=0 THEN 1 ELSE 0 END
            FROM dbo.SendMail_Campaigns b CROSS JOIN n WHERE b.name LIKE 'Seguimiento fixture %';
        INSERT INTO dbo.SendMail_WhatsAppBatches(branch_id,source_type,template_id,name,status)
            VALUES(1,'campaign',1,'WA fixture campaign','queued'),(1,'invoice',1,'WA fixture invoice','paused'),(2,'campaign',1,'WA otra sucursal','queued');
        INSERT INTO dbo.SendMail_WhatsAppQueue(branch_id,batch_id,template_id,phone_to,status,scheduled_at)
            SELECT b.branch_id,b.id,1,'5491100000001',s.status,'20200101'
            FROM dbo.SendMail_WhatsAppBatches b CROSS JOIN
                (SELECT 'pending' AS status UNION ALL SELECT 'accepted' UNION ALL SELECT 'delivered' UNION ALL SELECT 'read') s
            WHERE b.name LIKE 'WA %';");
    $_SESSION['branch_id'] = 1;
    $batch = (int) $pdo->query("SELECT TOP 1 id FROM dbo.SendMail_Campaigns WHERE name LIKE 'Seguimiento fixture %' AND branch_id=1 ORDER BY id DESC")->fetchColumn();
    $all = ActivityRepository::filters([]);
    $filtersToCheck = [$all, ActivityRepository::filters(['kind'=>'campaign','channel'=>'email']),
        ActivityRepository::filters(['kind'=>'invoice','channel'=>'whatsapp']), ActivityRepository::filters(['status'=>'sending']),
        ActivityRepository::filters(['kind'=>'campaign','channel'=>'email','batch_id'=>$batch,'status'=>'failed']),
        array_replace($all,['branch'=>2]), array_replace($all,['branch'=>99999])];
    foreach ($filtersToCheck as $filters) {
        [$where,$params] = $whereMethod->invoke(null,$filters);
        foreach ([1,2,99999] as $page) {
            $stmt = $pdo->prepare(referenceSource().", numbered AS (SELECT *, COUNT(*) OVER() AS result_total,
                ROW_NUMBER() OVER (ORDER BY created_at DESC,id DESC,kind,channel) AS page_row_number FROM activity WHERE $where)
                SELECT * FROM numbered WHERE page_row_number>:start AND page_row_number<=:end ORDER BY page_row_number");
            $stmt->execute($params+[':start'=>($page-1)*50,':end'=>$page*50]);
            $expected = $stmt->fetchAll();
            foreach ($expected as &$row) unset($row['page_row_number']); unset($row);
            $actual = ActivityRepository::page($filters,$page);
            check(canonical($actual['rows']) === canonical($expected), 'Pagina exacta, filtros, orden y errores completos');
            check($actual['total'] === (int)($expected[0]['result_total']??0), 'Total de destinatarios');
        }
        [$where,$params] = $whereMethod->invoke(null,$filters,false);
        $stmt = $pdo->prepare(referenceSource()."SELECT TOP 100 kind,channel,batch_id,name,batch_status,COUNT(*) AS total,
            SUM(CASE WHEN status NOT IN ('pending','sending') THEN 1 ELSE 0 END) AS processed,
            SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed,MAX(created_at) AS created_at
            FROM activity WHERE $where GROUP BY kind,channel,batch_id,name,batch_status ORDER BY MAX(created_at) DESC,batch_id DESC");
        $stmt->execute($params);
        $expected = $stmt->fetchAll();
        check(canonical(ActivityRepository::batches($filters)) === canonical($expected), 'Lotes exactos y limite de 100');
        $stmt = $pdo->prepare(referenceSource()."SELECT
            SUM(CASE WHEN status='pending' AND scheduled_at<=SYSDATETIME() AND batch_status IN ('queued','processing') THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status='pending' AND scheduled_at>SYSDATETIME() AND batch_status IN ('queued','processing') THEN 1 ELSE 0 END) AS scheduled,
            SUM(CASE WHEN status='pending' AND batch_status='paused' THEN 1 ELSE 0 END) AS paused,
            SUM(CASE WHEN status='sending' THEN 1 ELSE 0 END) AS sending,
            SUM(CASE WHEN status='sending' AND last_error IS NOT NULL THEN 1 ELSE 0 END) AS attention,
            SUM(CASE WHEN status IN ('sent','accepted','delivered','read') AND is_test=0 THEN 1 ELSE 0 END) AS sent,
            SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed,
            SUM(CASE WHEN is_test=1 AND status='sent' THEN 1 ELSE 0 END) AS tests FROM activity WHERE $where");
        $stmt->execute($params);
        check(ActivityRepository::summary($filters) === array_map('intval',$stmt->fetch()), 'Metricas exactas, incluidas pruebas y atencion');
    }
    $started = microtime(true);
    ActivityRepository::summary($all); ActivityRepository::batches($all); ActivityRepository::page($all);
    $count = (int)$pdo->query('SELECT COUNT(*) FROM dbo.SendMail_Queue')->fetchColumn();
    echo 'ACTIVITY OK: historial de '.$count.' emails mas facturas y WhatsApp, equivalencia con consultas anteriores, sucursales, filtros, 100 lotes y compatibilidad 100. Consulta conjunta: '.round((microtime(true)-$started)*1000)." ms. Base temporal; sin envios.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->exec("ALTER DATABASE [$database] SET COMPATIBILITY_LEVEL = $compatibility");
}
