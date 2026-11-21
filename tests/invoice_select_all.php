<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/test_bootstrap.php';
function check(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
$pdo=Database::pdo();$_SESSION['branch_id']=1;Schema::ensure();
$criteriaMethod=new ReflectionMethod(InvoiceRepository::class,'invoiceSearchCriteria');
$phoneMethod=new ReflectionMethod(InvoiceRepository::class,'invoicePhoneExpression');
$pdo->beginTransaction();
try {
    $pdo->exec("WITH n AS (SELECT TOP 16000 ROW_NUMBER() OVER(ORDER BY (SELECT NULL)) AS id FROM sys.all_objects a CROSS JOIN sys.all_objects b)
        INSERT INTO dbo.FacturasTel(Id,IdCliente,V1_M_Final,Nombre,SNB,V1_FechaVto,Estado,Autorizada,mail_enviado)
        SELECT 'BULK'+CONVERT(nvarchar(40),id),'1',100,'Factura masiva','B'+CONVERT(nvarchar(40),id),'20260930','I',1,0 FROM n;
        UPDATE dbo.FacturasTel SET mail_enviado=1 WHERE Id='F1';
        UPDATE dbo.FacturasTel SET Estado='P' WHERE Id='F2';
        UPDATE dbo.FacturasTel SET Estado='A' WHERE Id='F5';
        UPDATE dbo.FacturasTel SET Autorizada=0 WHERE Id='F6';
        UPDATE dbo.clientes SET telefono_movil=NULL WHERE OId='3';
        UPDATE dbo.clientes SET DirEMail=NULL WHERE OId='4';");
    $cases=[['email','',false,[]],['email','',true,[]],['whatsapp','',false,[]],['both','',false,[]],
        ['email','P',true,[]],['email','I',true,['snb'=>'0001']],['email','',true,['name'=>'Cliente 00']],
        ['email','',true,['email'=>'cliente2@']],['email','',true,['phone'=>'3980']],
        ['both','',true,['phone'=>'3980','email'=>'cliente']],['whatsapp','',true,['name'=>'No existe']]];
    $referenceMs=0;$fastMs=0;
    foreach($cases as [$channel,$status,$sent,$filters]) {
        $criteria=$criteriaMethod->invoke(null,'2026-09-30',$status,$sent,$filters,$channel,$phoneMethod->invoke(null));
        $started=microtime(true);
        $stmt=$pdo->prepare('SELECT CONVERT(nvarchar(80),F.Id) FROM FacturasTel F
            INNER JOIN clientes C ON C.OId COLLATE Modern_Spanish_CI_AS=F.IdCliente
            WHERE '.implode(' AND ',$criteria['where']).' ORDER BY F.SNB,F.Id');
        $stmt->execute($criteria['params']);$expected=$stmt->fetchAll(PDO::FETCH_COLUMN);
        $referenceMs+=(microtime(true)-$started)*1000;
        $started=microtime(true);
        $actual=InvoiceRepository::matchingIds('2026-09-30',$status,$sent,$filters,$channel,false);
        $fastMs+=(microtime(true)-$started)*1000;
        sort($expected,SORT_STRING);sort($actual,SORT_STRING);
        check($actual===$expected,'Seleccion total equivalente a consulta anterior: '.json_encode([$channel,$status,$sent,$filters]));
    }
    $input=ComposeService::input(['due_date'=>'2026-09-30','channel'=>'email','include_sent'=>true]);
    $actual=ComposeService::search('invoice',$input,1,true);
    check(count($actual)>19000,'Seleccion total de cerca de 20000 facturas');
    // A small current due date among a larger invoice history is closer to the
    // production report than a database containing only the selected due date.
    $pdo->exec("INSERT INTO dbo.FacturasTel(Id,IdCliente,V1_M_Final,Nombre,SNB,V1_FechaVto,Estado,Autorizada,mail_enviado)
        SELECT TOP 3500 'DEC'+OId,OId,100,'Factura diciembre','D'+OId,'20261204','I',1,0
        FROM dbo.clientes WHERE NULLIF(LTRIM(RTRIM(DirEMail)),'') IS NOT NULL;
        WITH n AS (SELECT TOP 100000 ROW_NUMBER() OVER(ORDER BY (SELECT NULL)) AS id FROM sys.all_objects a CROSS JOIN sys.all_objects b)
        INSERT INTO dbo.FacturasTel(Id,IdCliente,V1_M_Final,Nombre,SNB,V1_FechaVto,Estado,Autorizada,mail_enviado)
        SELECT 'HIST'+CONVERT(nvarchar(40),id),CONVERT(nvarchar(80),1+id%4000),100,'Historico','H'+CONVERT(nvarchar(40),id),'20250101','I',1,1 FROM n;
        CREATE INDEX IX_Test_InvoiceDueDate ON dbo.FacturasTel(V1_FechaVto);");
    $input=ComposeService::input(['due_date'=>'2026-12-04','channel'=>'email']);
    $listed=ComposeService::search('invoice',$input);
    $started=microtime(true);
    $selected=ComposeService::search('invoice',$input,1,true);
    $selectionMs=(microtime(true)-$started)*1000;
    check(count($selected)===3500 && $listed['total']===3500,'3500 facturas del 04/12/2026 entre mas de 120000 facturas');
    check(!array_diff(array_column($listed['rows'],'invoice_id'),$selected),'La seleccion incluye las filas de la busqueda inicial');
    $expected=InvoiceRepository::matchingIds('2026-12-04','',false,[],'email',true);
    sort($expected,SORT_STRING);sort($selected,SORT_STRING);
    check($expected===$selected,'Seleccion completa equivalente al listado ordenado');
    echo 'SCALE OK: seleccion de 3500 entre mas de 120000 facturas: '.round($selectionMs)." ms. Solo base temporal local.\n";
    echo 'SELECT ALL OK: 20000 facturas de fixture, filtros, email/WhatsApp/ambos, enviados y estados. Total de 11 consultas: anterior '.round($referenceMs).' ms; nueva '.round($fastMs)." ms. Base local; sin envios.\n";
} finally {if($pdo->inTransaction())$pdo->rollBack();}
