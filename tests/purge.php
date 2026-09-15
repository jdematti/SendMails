<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/test_bootstrap.php';
function check(bool $condition,string $message):void { if(!$condition)throw new RuntimeException($message); }
function rejects(callable $call,string $contains):void {
    try{$call();}catch(Throwable $e){check(str_contains($e->getMessage(),$contains),'Error inesperado: '.$e->getMessage());return;}
    throw new RuntimeException('Se esperaba rechazo: '.$contains);
}
$pdo=Database::pdo(); Schema::ensure();
// Upgrade an already migrated installation without touching pending snapshots.
$indexes=$pdo->query("SELECT OBJECT_NAME(object_id) AS table_name,name FROM sys.indexes WHERE name LIKE 'IX_SendMail_%_Purge%'")->fetchAll();
check(count($indexes)===7,'Indices de purga en instalacion nueva');
foreach($indexes as $index)$pdo->exec('DROP INDEX ['.$index['name'].'] ON dbo.['.$index['table_name'].']');
$pdo->exec("UPDATE dbo.SendMail_Settings SET setting_value='20260914-1' WHERE setting_key='schema_version'");
Schema::migrate(); Schema::migrate();
check((int)$pdo->query("SELECT COUNT(*) FROM sys.indexes WHERE name LIKE 'IX_SendMail_%_Purge%'")->fetchColumn()===7,'Actualizar indices de purga de forma idempotente');
$_SESSION['branch_id']=1;
$suffix=bin2hex(random_bytes(6));
$branchStmt=$pdo->prepare("INSERT INTO dbo.SendMail_Branches(name,server,database_name,username,password_cipher) OUTPUT INSERTED.id VALUES(:name,'fixture','fixture','fixture','fixture')");
$branchStmt->execute([':name'=>'Sucursal purga '.$suffix]);$otherBranch=(int)$branchStmt->fetchColumn();
$template=(int)TemplateRepository::all(true)[0]['id'];
$invoiceTemplate=(int)InvoiceRepository::defaultTemplate()['id'];
$sources=[
    'campaign_email'=>['SendMail_Campaigns','SendMail_Queue','campaign_id','SendMail_Log'],
    'invoice_email'=>['SendMail_InvoiceBatches','SendMail_InvoiceQueue','batch_id','SendMail_InvoiceLog'],
    'whatsapp'=>['SendMail_WhatsAppBatches','SendMail_WhatsAppQueue','batch_id','SendMail_WhatsAppEvents'],
];
function fixture(string $source,int $branch,string $kind='campaign',string $state='completed',string $queueState='sent',string $finished='2020-02-01'):array {
    global $pdo,$sources,$template,$invoiceTemplate;
    [$batch,$queue,$foreign,$log]=$sources[$source];
    $extra=$source==='campaign_email' ? ",send_mode" : ($source==='whatsapp' ? ',source_type' : '');
    $extraValue=$source==='campaign_email' ? ",'individual'" : ($source==='whatsapp' ? ',:source_type' : '');
    $params=[':branch'=>$branch,':template'=>$source==='invoice_email'?$invoiceTemplate:$template,':name'=>'Fixture '.$source.' '.bin2hex(random_bytes(5)),':status'=>$state,':finished'=>$finished];
    if($source==='whatsapp')$params[':source_type']=$kind;
    $stmt=$pdo->prepare("INSERT INTO dbo.$batch(branch_id,template_id,name,status,created_at,completed_at,message_snapshot$extra)
        OUTPUT INSERTED.id VALUES(:branch,:template,:name,:status,'2020-01-01',:finished,'{}'$extraValue)");
    $stmt->execute($params);$id=(int)$stmt->fetchColumn();
    $provider='fixture-'.bin2hex(random_bytes(12));
    if($source==='whatsapp'){$extra=',phone_to,provider_message_id';$extraValue=",'5491100000001',:provider";}
    elseif($source==='invoice_email'){$extra=',email_to,invoice_id,snb,invoice_url';$extraValue=",'fixture@example.invalid','F1','00001','https://example.invalid'";}
    else{$extra=',email_to';$extraValue=",'fixture@example.invalid'";}
    $params=[':branch'=>$branch,':batch'=>$id,':template'=>$source==='invoice_email'?$invoiceTemplate:$template,':status'=>$queueState];
    if($source==='whatsapp')$params[':provider']=$provider;
    $stmt=$pdo->prepare("INSERT INTO dbo.$queue(branch_id,$foreign,template_id,status,created_at,scheduled_at,sent_at$extra)
        OUTPUT INSERTED.id VALUES(:branch,:batch,:template,:status,'2020-01-01','2020-01-01','2020-01-02'$extraValue)");
    $stmt->execute($params);$qid=(int)$stmt->fetchColumn();
    if($source==='whatsapp'){
        $stmt=$pdo->prepare("INSERT INTO dbo.$log(branch_id,provider_message_id,event_status,event_hash,payload_json,created_at) OUTPUT INSERTED.id VALUES(:branch,:provider,'sent',:hash,'{}','2020-01-02')");
        $stmt->execute([':branch'=>$branch,':provider'=>$provider,':hash'=>hash('sha256',$provider)]);
    }else{
        $extra=$source==='campaign_email'?',campaign_id':'';$extraValue=$source==='campaign_email'?',:batch':'';
        $stmt=$pdo->prepare("INSERT INTO dbo.$log(branch_id,queue_id,email_to,status,created_at$extra) OUTPUT INSERTED.id VALUES(:branch,:queue,'fixture@example.invalid','sent','2020-01-02'$extraValue)");
        $params=[':branch'=>$branch,':queue'=>$qid];if($source==='campaign_email')$params[':batch']=$id;$stmt->execute($params);
    }
    return ['source'=>$source,'id'=>$id,'queue_id'=>$qid,'log_id'=>(int)$stmt->fetchColumn()];
}
function existsFixture(array $row):bool { global $pdo,$sources;return (bool)$pdo->query('SELECT COUNT(*) FROM dbo.'.$sources[$row['source']][0].' WHERE id='.(int)$row['id'])->fetchColumn(); }
$eligible=[fixture('campaign_email',1),fixture('campaign_email',$otherBranch),fixture('invoice_email',1,'invoice'),fixture('whatsapp',1),fixture('whatsapp',$otherBranch,'invoice')];
$legacy=$eligible[0];
$pdo->exec("INSERT INTO dbo.SendMail_Log(branch_id,campaign_id,queue_id,email_to,status,created_at) VALUES
    (NULL,".$legacy['id'].",NULL,'fixture@example.invalid','sent','2020-01-02'),
    (NULL,NULL,".$legacy['queue_id'].",'fixture@example.invalid','sent','2020-01-02'),
    (1,0,".$legacy['queue_id'].",'fixture@example.invalid','sent','2020-01-02')");
$protected=[];
foreach(['queued','processing','paused'] as $status)$protected[]=fixture('campaign_email',1,'campaign',$status);
foreach(['pending','sending'] as $status)$protected[]=fixture('campaign_email',1,'campaign','completed',$status);
$protected[]=fixture('campaign_email',1,'campaign','completed','sent',date('Y-m-d'));
$protected[]=fixture('campaign_email',1,'campaign','completed','sent',PurgeRepository::maxCutoff());
$recent=fixture('campaign_email',1);$pdo->exec('UPDATE dbo.SendMail_Queue SET sent_at=SYSDATETIME() WHERE id='.$recent['queue_id']);$protected[]=$recent;
$recent=fixture('whatsapp',1);$pdo->exec('UPDATE dbo.SendMail_WhatsAppEvents SET created_at=SYSDATETIME() WHERE id='.$recent['log_id']);$protected[]=$recent;
$countsBefore=[];foreach(['clientes','FacturasTel','SendMail_Templates','SendMail_InvoiceTemplates','SendMail_Drafts','SendMail_Unsubscribes','SendMail_EmailExclusions','SendMail_WhatsAppOptOuts'] as $table)$countsBefore[$table]=(int)$pdo->query("SELECT COUNT(*) FROM dbo.$table")->fetchColumn();
rejects(static fn()=>PurgeRepository::filters(['cutoff'=>date('Y-m-d')]),'90 días');
$preview=PurgeRepository::preview([]);
$legacyRow=array_values(array_filter($preview['rows'],static fn(array $row):bool=>$row['source']==='campaign_email'&&(int)$row['id']===$legacy['id']))[0];
check((int)$legacyRow['records']===4,'Enlaces por campana o destinatario, sin duplicar y admitiendo logs antiguos sin sucursal');
foreach($eligible as $row)check((bool)array_filter($preview['rows'],static fn(array $item):bool=>$item['source']===$row['source']&&(int)$item['id']===$row['id']),'Debe incluir lote antiguo');
foreach($protected as $row)check(!array_filter($preview['rows'],static fn(array $item):bool=>$item['source']===$row['source']&&(int)$item['id']===$row['id']),'Debe preservar lote activo o reciente');
$single=PurgeRepository::preview(['branch_id'=>$otherBranch,'kind'=>'campaign','channel'=>'email']);
check(count($single['rows'])===1 && (int)$single['rows'][0]['id']===$eligible[1]['id'],'Filtrar sucursal, tipo y canal');
$pdo->exec('UPDATE dbo.SendMail_Campaigns SET status=\'paused\' WHERE id='.$eligible[1]['id']);
rejects(static fn()=>PurgeRepository::execute($preview),'historial cambió');
foreach($eligible as $row)check(existsFixture($row),'Rollback si cambia un lote de la vista previa');
$pdo->exec('UPDATE dbo.SendMail_Campaigns SET status=\'completed\' WHERE id='.$eligible[1]['id']);
$preview=PurgeRepository::preview([]);
$pdo->exec("CREATE TRIGGER dbo.fixture_reject_purge ON dbo.SendMail_InvoiceQueue AFTER DELETE AS THROW 51000,'Fallo de purga simulado',1;");
try{rejects(static fn()=>PurgeRepository::execute($preview),'Fallo de purga simulado');}finally{$pdo->exec('DROP TRIGGER dbo.fixture_reject_purge');}
foreach($eligible as $row)check(existsFixture($row),'Rollback completo ante fallo de borrado');
$pdo->exec("CREATE TRIGGER dbo.fixture_slow_purge ON dbo.SendMail_InvoiceQueue AFTER DELETE AS WAITFOR DELAY '00:00:20';");
$started=microtime(true);
try{rejects(static fn()=>PurgeRepository::execute($preview),'purga se revirtió');}finally{$pdo->exec('DROP TRIGGER dbo.fixture_slow_purge');}
check(microtime(true)-$started<30,'Interrumpir SQL antes del limite fatal de PHP');
check(!$pdo->inTransaction(),'Cerrar la transaccion despues de timeout');
foreach($eligible as $row)check(existsFixture($row),'Timeout revierte incluso lotes borrados antes de la demora');
$receiptCount=$pdo->prepare('SELECT COUNT(*) FROM dbo.SendMail_Settings WHERE setting_key=:key');
$receiptCount->execute([':key'=>'purge_receipt:'.$preview['token']]);
check((int)$receiptCount->fetchColumn()===0,'No guardar comprobante de una purga revertida');
$receipt=PurgeRepository::execute($preview);
check(PurgeRepository::execute($preview)===$receipt,'Confirmacion idempotente');
foreach($eligible as $row){
    check(!existsFixture($row),'Lote eliminado');
    check((int)$pdo->query('SELECT COUNT(*) FROM dbo.'.$sources[$row['source']][1].' WHERE id='.$row['queue_id'])->fetchColumn()===0,'Destinatario eliminado');
    check((int)$pdo->query('SELECT COUNT(*) FROM dbo.'.$sources[$row['source']][3].' WHERE id='.$row['log_id'])->fetchColumn()===0,'Registro asociado eliminado');
}
foreach($protected as $row)check(existsFixture($row),'Conservar todos los protegidos');
foreach($countsBefore as $table=>$count)check((int)$pdo->query("SELECT COUNT(*) FROM dbo.$table")->fetchColumn()===$count,'Preservar '.$table);
$stmt=$pdo->prepare("INSERT INTO dbo.SendMail_Users(username,full_name,email,password_hash,role) OUTPUT INSERTED.id VALUES(:name,'Usuario fixture',:email,'fixture','Usuario')");
$stmt->execute([':name'=>'purge_user_'.$suffix,':email'=>'purge_'.$suffix.'@example.invalid']);$nonAdmin=(int)$stmt->fetchColumn();
$_SESSION['auth_user_id']=$nonAdmin;
rejects(static fn()=>PurgeRepository::preview([]),'administradores');
rejects(static fn()=>PurgeRepository::execute($preview),'administradores');
$_SESSION['auth_user_id']=1;
$beyondLimit=PurgeRepository::LIMIT+1;
$pdo->exec("INSERT INTO dbo.SendMail_Campaigns(branch_id,template_id,name,send_mode,status,created_at,completed_at)
    SELECT TOP $beyondLimit $otherBranch,$template,'Limite fixture','individual','completed','2020-01-01','2020-02-01' FROM sys.all_objects");
$limited=PurgeRepository::preview(['branch_id'=>$otherBranch,'kind'=>'campaign','channel'=>'email']);
check(count($limited['rows'])===PurgeRepository::LIMIT && $limited['more'],'Limitar cantidad de lotes por operacion');
check(PurgeRepository::execute($limited)['totals']['batches']===PurgeRepository::LIMIT,'Procesar el bloque revisado');
$remaining=PurgeRepository::preview(['branch_id'=>$otherBranch,'kind'=>'campaign','channel'=>'email']);
check(count($remaining['rows'])===1 && !$remaining['more'],'Conservar el lote fuera del bloque');
PurgeRepository::execute($remaining);
// Large historic log plus real-sized batches, without external transports.
$pdo->exec("INSERT INTO dbo.SendMail_Log(branch_id,email_to,status,created_at,html_snapshot)
    SELECT TOP 50000 $otherBranch,'unrelated@example.invalid','sent','2020-01-01',REPLICATE('x',200)
    FROM sys.all_objects a CROSS JOIN sys.all_objects b");
$large=[];
foreach([4000,4000,6000] as $size){
    $row=fixture('campaign_email',$otherBranch);$id=$row['id'];$large[]=$id;
    $extra=$size-1;
    $pdo->exec("INSERT INTO dbo.SendMail_Queue(branch_id,campaign_id,template_id,email_to,status,created_at,scheduled_at,sent_at)
        SELECT TOP $extra $otherBranch,$id,$template,'fixture@example.invalid','sent','2020-01-01','2020-01-01','2020-01-02'
        FROM sys.all_objects a CROSS JOIN sys.all_objects b;
        INSERT INTO dbo.SendMail_Log(branch_id,campaign_id,queue_id,email_to,status,created_at)
        SELECT $otherBranch,$id,q.id,'fixture@example.invalid','sent','2020-01-02' FROM dbo.SendMail_Queue q
        WHERE q.campaign_id=$id AND q.id<>".$row['queue_id']);
}
$started=microtime(true);
foreach($large as $i=>$id){
    $block=PurgeRepository::preview(['branch_id'=>$otherBranch,'channel'=>'email','kind'=>'campaign']);
    check(count($block['rows'])===1 && (int)$block['rows'][0]['id']===$id,'Dividir por volumen manteniendo lotes completos y ordenados');
    check($block['more']===($i<2),'Indicar si queda historial por purgar');
    $result=PurgeRepository::execute($block);
    check((int)$result['totals']['recipients']===($i===2?6000:4000),'Eliminar todos los destinatarios revisados');
}
check((int)$pdo->query("SELECT COUNT(*) FROM dbo.SendMail_Log WHERE email_to='unrelated@example.invalid'")->fetchColumn()===50000,'No borrar historial ajeno al lote');
echo 'PURGE SCALE OK: 14000 destinatarios, 14000 logs asociados, 50000 logs ajenos; '.round(microtime(true)-$started,2)." s para las tres operaciones locales.\n";
$ui=fixture('campaign_email',1);$pdo->exec("UPDATE dbo.SendMail_Campaigns SET name='UI purge fixture' WHERE id=".$ui['id']);
$control=WorkerControl::read(1);
if(!$control['enabled'])$control=WorkerControl::set(1,true,1,(int)$control['revision']);
file_put_contents(STORAGE_PATH.'/worker-status.json',json_encode(['status'=>'idle','updated_at'=>time(),'active_branch'=>null,'branches'=>[1=>['checked_at'=>time(),'control_revision'=>$control['revision'],'error'=>'']]]));
echo "PURGE OK: 90 dias, todas las sucursales, estados protegidos, actividad reciente, filtros, rollback, idempotencia y permisos. Solo fixtures.\n";
