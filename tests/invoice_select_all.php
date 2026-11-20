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
    echo 'SELECT ALL OK: 20000 facturas de fixture, filtros, email/WhatsApp/ambos, enviados y estados. Total de 11 consultas: anterior '.round($referenceMs).' ms; nueva '.round($fastMs)." ms. Base local; sin envios.\n";
} finally {if($pdo->inTransaction())$pdo->rollBack();}
