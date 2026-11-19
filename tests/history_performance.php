<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/test_bootstrap.php';
function check(bool $condition,string $message):void { if(!$condition)throw new RuntimeException($message); }
$pdo=Database::pdo(); Schema::ensure();
$pdo->beginTransaction();
try {
    $pdo->exec("WITH n AS (SELECT TOP 30000 ROW_NUMBER() OVER(ORDER BY (SELECT NULL)) AS id FROM sys.all_objects a CROSS JOIN sys.all_objects b)
        INSERT INTO dbo.SendMail_Log(branch_id,client_name,client_code,email_to,email_subject,smtp_host,status,error_message,duration_ms,created_at,html_snapshot)
        SELECT CASE WHEN id%10=0 THEN 2 ELSE 1 END,'Cliente '+CONVERT(varchar(20),id),CONVERT(varchar(20),id),
            'cliente'+CONVERT(varchar(20),id)+'@example.invalid','Asunto '+CONVERT(varchar(20),id%5),'smtp.example.invalid',
            CASE WHEN id%3=0 THEN 'failed' ELSE 'sent' END,
            CASE WHEN id%3=0 THEN REPLICATE(CAST(N'Error de prueba. ' AS nvarchar(max)),300) ELSE NULL END,
            id%1000,DATEADD(second,id%100,CONVERT(datetime2,'20200101',112)),
            CASE WHEN id%100=0 THEN REPLICATE(CAST(N'HTML privado. ' AS nvarchar(max)),1000) ELSE NULL END FROM n;");
    foreach ([1,2] as $branch) {
        $_SESSION['branch_id']=$branch;
        foreach ([[],['client'=>'123'],['email'=>'cliente2'],['subject'=>'Asunto 3'],['date'=>'2020-01-01'],
            ['date'=>'2020-01-02'],['client'=>'12','email'=>'example.invalid','subject'=>'Asunto 2','date'=>'2020-01-01']] as $filters) {
            $where=['branch_id=:branch']; $params=[':branch'=>$branch];
            if (!empty($filters['client'])) { $where[]='(client_name LIKE :name OR client_code LIKE :code)'; $params[':name']=$params[':code']='%'.$filters['client'].'%'; }
            foreach (['email'=>'email_to','subject'=>'email_subject'] as $key=>$column) {
                if (!empty($filters[$key])) { $where[]="$column LIKE :$key"; $params[":$key"]='%'.$filters[$key].'%'; }
            }
            if (!empty($filters['date'])) {
                $date=new DateTimeImmutable($filters['date']); $where[]='created_at>=:from AND created_at<:to';
                $params[':from']=$date->format('Y-m-d 00:00:00'); $params[':to']=$date->modify('+1 day')->format('Y-m-d 00:00:00');
            }
            foreach ([20,50,100] as $limit) {
                $stmt=$pdo->prepare("SELECT TOP $limit id,created_at,client_name,client_code,email_to,email_subject,smtp_host,status,error_message,duration_ms
                    FROM dbo.SendMail_Log WHERE ".implode(' AND ',$where).' ORDER BY created_at DESC,id DESC');
                $stmt->execute($params); $expected=$stmt->fetchAll();
                $actual=QueueRepository::recentLogs($limit,$filters);
                check($actual===$expected,'Historial exacto: filtros, sucursal, orden, limites y errores completos');
                if ($actual) check(!array_key_exists('html_snapshot',$actual[0]),'No cargar mensajes completos');
            }
        }
    }
    $_SESSION['branch_id']=1;
    $started=microtime(true); QueueRepository::recentLogs(100);
    echo 'HISTORY OK: 30000 registros, filtros y sucursales equivalentes a consulta anterior. Ultimos 100: '.round((microtime(true)-$started)*1000)." ms. Base temporal; sin envios ni purga real.\n";
} finally { if($pdo->inTransaction())$pdo->rollBack(); $_SESSION['branch_id']=1; }
