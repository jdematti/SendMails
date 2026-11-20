<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
final class PipelineMeta {
    public static array $requests=[];
    public static function request(array $config,string $method,string $path,?array $payload=null,array $query=[]):array {
        if($method!=='POST'||!str_ends_with($path,'/messages'))throw new RuntimeException('Solicitud inesperada en transporte simulado');
        self::$requests[]=$payload;
        return ['messages'=>[['id'=>'wamid.pipeline.'.count(self::$requests)]]];
    }
}
$service=file_get_contents(__DIR__.'/../app/WhatsAppBusinessService.php');
$service=str_replace('self::request(','\\PipelineMeta::request(',$service,$replaced);
if($replaced<1)throw new RuntimeException('Cambio el transporte de Meta; revisar la prueba');
eval(preg_replace('/^<\?php\s*/','',$service));
require __DIR__.'/test_bootstrap.php';
function check(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
$pdo=Database::pdo();$_SESSION['branch_id']=1;
foreach(['Campaigns','InvoiceBatches','WhatsAppBatches'] as $suffix)$pdo->exec("UPDATE dbo.SendMail_$suffix SET status='paused'");
$control=WorkerControl::read(1);if(!$control['enabled'])WorkerControl::set(1,true,1,(int)$control['revision']);
Settings::saveWhatsapp(['graph_version'=>'v25.0','waba_id'=>'fixture-waba','phone_number_id'=>'fixture-phone','access_token'=>'fixture-token','is_enabled'=>true,'country_code'=>'54'],1);
$scheduled=(string)$pdo->query("SELECT CONVERT(varchar(16),DATEADD(minute,-2,SYSDATETIME()),126)")->fetchColumn();
$templateStmt=$pdo->prepare("INSERT INTO dbo.SendMail_WhatsAppTemplates(branch_id,meta_template_id,name,language,category,status,components_json,body_variables_json)
    OUTPUT INSERTED.id VALUES(1,:meta,:name,'es_AR',:category,'APPROVED',:components,:variables)");
foreach(['campaign'=>'razon_social','invoice'=>'url_factura'] as $kind=>$variable) {
    $templateStmt->execute([':meta'=>bin2hex(random_bytes(8)),':name'=>'pipeline_'.$kind,':category'=>$kind==='campaign'?'MARKETING':'UTILITY',
        ':components'=>json_encode([['type'=>'BODY','text'=>'Hola {{1}}']]),':variables'=>json_encode([$variable])]);
    $id=(int)$templateStmt->fetchColumn();
    $input=ComposeService::input(['name'=>'Pipeline WA '.$kind,'channel'=>'whatsapp','scheduled_at'=>$scheduled,'whatsapp_template_id'=>$id,
        'template_id'=>InvoiceRepository::defaultTemplate()['id'],'due_date'=>'2026-09-30','include_sent'=>true,
        'ids'=>$kind==='campaign'?['3980','3981']:['F3982']]);
    $review=ComposeService::review($kind,$input);
    $draft=DraftRepository::save(0,$kind,$input['name'],['input'=>$input,'review'=>$review],0,'review');
    $confirmed=ComposeService::confirm($kind,$draft['id'],$draft['revision']);
    check(ComposeService::confirm($kind,$draft['id'],$draft['revision'])===$confirmed,'Confirmacion WA idempotente');
    $pdo->exec("UPDATE dbo.SendMail_WhatsAppTemplates SET name='editada_despues' WHERE id=$id");
}
$skip=ClientRepository::findByOids(['3981'])[0];
$phone=WhatsAppPhone::normalizeRequired($skip['telefono_movil']);
WhatsAppRepository::recordOptOut(1,$phone);
try {
    check(WorkerRuntime::acquire(),'Bloqueo WA');
    $result=WorkerRuntime::process(0,0);WorkerRuntime::release();
    check($result['processed']===2&&$result['sent']===2&&$result['failed']===0,'Worker WA completo: '.json_encode($result));
    check(count(PipelineMeta::$requests)===2,'Dos aceptaciones simuladas por Meta');
    $names=array_column(array_column(PipelineMeta::$requests,'template'),'name');sort($names);
    check($names===['pipeline_campaign','pipeline_invoice'],'Conservar nombres de plantilla del snapshot');
    foreach(PipelineMeta::$requests as $request)check($request['template']['components'][0]['parameters'][0]['text']!=='','Variables de campaña y enlace de factura renderizados');
    $rows=$pdo->query("SELECT q.status,q.provider_message_id FROM dbo.SendMail_WhatsAppQueue q INNER JOIN dbo.SendMail_WhatsAppBatches b ON b.id=q.batch_id WHERE b.name LIKE 'Pipeline WA %'")->fetchAll();
    check(count(array_filter($rows,static fn(array $row):bool=>$row['status']==='accepted'&&$row['provider_message_id']!==null))===2,'Guardar estado e ID del proveedor');
    check(count(array_filter($rows,static fn(array $row):bool=>$row['status']==='skipped'))===1,'La baja omite el pendiente antes de enviarlo');
    check(WorkerRuntime::acquire(),'Segunda corrida WA');$again=WorkerRuntime::process(0,0);WorkerRuntime::release();
    check($again['processed']===0&&count(PipelineMeta::$requests)===2,'No duplicar aceptados ni omitidos');
    echo "WHATSAPP PIPELINE OK: preparacion, confirmacion, snapshots, variables, bajas, worker e IDs de Meta. HTTP simulado; sin red.\n";
} finally {WorkerRuntime::release();}
