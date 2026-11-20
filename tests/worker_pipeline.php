<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__.'/../vendor/autoload.php';
// Use PHPMailer's real MIME generation and replace only the network delivery.
class PipelineMailer extends PHPMailer\PHPMailer\PHPMailer {
    public static array $accepted=[];
    public function send() {
        $this->preSend();
        $address=$this->getToAddresses()[0][0];
        if ($address==='pipeline-fail@example.invalid') throw new RuntimeException('Fallo SMTP simulado');
        self::$accepted[]=['address'=>$address,'subject'=>$this->Subject,'html'=>$this->Body,
            'attachments'=>$this->getAttachments(),'at'=>microtime(true),'mime'=>$this->getSentMIMEMessage()];
        return true;
    }
}
$service=file_get_contents(__DIR__.'/../app/MailerService.php');
$service=str_replace('new PHPMailer(true)','new \\PipelineMailer(true)',$service,$replaced);
if($replaced!==1)throw new RuntimeException('Cambio el constructor del transporte; revisar la prueba.');
eval(preg_replace('/^<\?php\s*/','',$service));
require __DIR__.'/test_bootstrap.php';
function check(bool $condition,string $message):void { if(!$condition)throw new RuntimeException($message); }
function prepare(string $kind,array $input):array {
    $input=ComposeService::input($input); $review=ComposeService::review($kind,$input);
    $draft=DraftRepository::save(0,$kind,$input['name'],['input'=>$input,'review'=>$review],0,'review');
    $result=ComposeService::confirm($kind,$draft['id'],$draft['revision']);
    check(ComposeService::confirm($kind,$draft['id'],$draft['revision'])===$result,'Confirmacion idempotente');
    return $result;
}
$pdo=Database::pdo(); $_SESSION['branch_id']=1;
// Only fixture batches exist here; keep unrelated pending fixtures out of this run.
foreach(['Campaigns','InvoiceBatches','WhatsAppBatches'] as $suffix)$pdo->exec("UPDATE dbo.SendMail_$suffix SET status='paused'");
Settings::saveSmtp(['host'=>'127.0.0.1','port'=>'9','from_email'=>'sender@example.invalid','from_name'=>'Fixture','interval_seconds'=>1],1);
check(Settings::smtp(1)['encryption']==='tls','SMTP conserva TLS si no se indica cifrado');
Settings::saveApp(['base_url'=>'https://sendmails.example.invalid']);
$control=WorkerControl::read(1);
if(!$control['enabled'])WorkerControl::set(1,true,1,(int)$control['revision']);
$past=(string)$pdo->query("SELECT CONVERT(varchar(16),DATEADD(minute,-2,SYSDATETIME()),126)")->fetchColumn();
$future=(string)$pdo->query("SELECT CONVERT(varchar(16),DATEADD(day,1,SYSDATETIME()),126)")->fetchColumn();
$template=TemplateRepository::save(null,'Pipeline campana','Snapshot original','<p>Hola {{razon_social}}</p>',true,
    [['name'=>'prueba.txt','content'=>base64_encode('Adjunto original')]]);
$base=['name'=>'Pipeline','channel'=>'email','scheduled_at'=>$past,'template_id'=>$template];
$campaign=prepare('campaign',$base+['manual_emails'=>'pipeline-ok1@example.invalid pipeline-ok2@example.invalid']);
$planned=prepare('campaign',array_replace($base,['name'=>'Pipeline futuro','scheduled_at'=>$future,'manual_emails'=>'pipeline-future@example.invalid']));
prepare('campaign',$base+['manual_emails'=>'pipeline-fail@example.invalid']);
prepare('campaign',$base+['manual_emails'=>'pipeline-skip@example.invalid']);
$ambiguous=prepare('campaign',$base+['manual_emails'=>'pipeline-review@example.invalid']);
EmailExclusionRepository::setExcluded(['email'=>'pipeline-skip@example.invalid'],true);
TemplateRepository::save($template,'Editada despues','NO DEBE USARSE','<p>NO DEBE USARSE</p>',true,[]);
$invoice=InvoiceRepository::defaultTemplate();
$invoice=array_replace($invoice,['name'=>'Pipeline factura','subject'=>'Factura original {{snb}}','html_body'=>'<a href="{{url_factura}}">Factura {{nombre}}</a>','test_mode'=>0,'is_active'=>1]);
$realTemplate=InvoiceRepository::saveTemplate(null,$invoice);
$pdo->exec("UPDATE dbo.FacturasTel SET mail_enviado=0 WHERE Id IN ('F3989','F3990')");
$invoiceInput=['name'=>'Pipeline factura','channel'=>'email','scheduled_at'=>$past,'template_id'=>$realTemplate,'due_date'=>'2026-09-30','include_sent'=>true,'ids'=>['F3989']];
$real=prepare('invoice',$invoiceInput);
$testTemplate=InvoiceRepository::saveTemplate(null,array_replace($invoice,['name'=>'Pipeline prueba','test_mode'=>1,'test_email'=>'pipeline-test@example.invalid']));
$test=prepare('invoice',array_replace($invoiceInput,['template_id'=>$testTemplate,'ids'=>['F3990']]));
$trigger=false;
try {
    $pdo->exec("CREATE TRIGGER dbo.fixture_pipeline_log_error ON dbo.SendMail_Log AFTER INSERT AS
        BEGIN IF EXISTS(SELECT 1 FROM inserted WHERE email_to='pipeline-review@example.invalid' AND status='sent')
        THROW 50000,'Fallo de registro posterior a aceptacion simulada',1; END");
    $trigger=true;
    check(WorkerRuntime::acquire(),'El worker toma el bloqueo');
    $other=fopen(STORAGE_PATH.'/worker.lock','c+');
    check(!flock($other,LOCK_EX|LOCK_NB),'Se rechaza un segundo worker'); fclose($other);
    $result=WorkerRuntime::process(0,0); WorkerRuntime::release('idle',$result['processed']);
    check($result['processed']===7 && $result['sent']===4 && $result['failed']===1 && $result['skipped']===1,'Worker real procesa todos los casos: '.json_encode($result));
    check(count(PipelineMailer::$accepted)===5,'Solo cinco aceptaciones simuladas');
    foreach(PipelineMailer::$accepted as $i=>$message) {
        if($i)check($message['at']-PipelineMailer::$accepted[$i-1]['at']>=1.0,'Intervalo completo entre mensajes aceptados');
        check(!str_contains($message['subject'],'NO DEBE USARSE'),'Conservar snapshot al editar plantilla');
        check(!preg_match('/\{\{[^}]+\}\}/',$message['html']),'Variables renderizadas');
        if(str_starts_with($message['address'],'pipeline-ok')) {
            check(count($message['attachments'])===1 && $message['attachments'][0][1]==='prueba.txt','Conservar adjunto en snapshot');
            check(str_contains($message['mime'],'List-Unsubscribe:'),'Cabecera de baja');
        }
    }
    $rows=$pdo->query("SELECT email_to,status,last_error FROM dbo.SendMail_Queue WHERE email_to LIKE 'pipeline-%'")->fetchAll();
    $states=array_column($rows,'status','email_to');
    check($states['pipeline-ok1@example.invalid']==='sent' && $states['pipeline-fail@example.invalid']==='failed' && $states['pipeline-skip@example.invalid']==='skipped','Estados de campana');
    check($states['pipeline-review@example.invalid']==='sending','Aceptado sin registro queda para revision, sin reintento');
    $activity=ActivityRepository::summary(ActivityRepository::filters(['kind'=>'campaign','channel'=>'email','batch_id'=>$ambiguous['links'][0]['id']]));
    check($activity['attention']===1 && $activity['sending']===1,'Seguimiento advierte sobre la aceptacion sin registro');
    check($states['pipeline-future@example.invalid']==='pending','Conservar programados futuros');
    check((int)$pdo->query("SELECT mail_enviado FROM dbo.FacturasTel WHERE Id='F3989'")->fetchColumn()===1,'Factura real marca origen');
    check((int)$pdo->query("SELECT mail_enviado FROM dbo.FacturasTel WHERE Id='F3990'")->fetchColumn()===0,'Modo prueba no marca origen');
    check(in_array('pipeline-test@example.invalid',array_column(PipelineMailer::$accepted,'address'),true),'Redireccion de modo prueba');
    check(WorkerRuntime::acquire(),'Segunda corrida toma bloqueo');
    $again=WorkerRuntime::process(0,0); WorkerRuntime::release();
    check($again['processed']===0 && count(PipelineMailer::$accepted)===5,'Sin duplicados ni reintentos de resultados ambiguos');
    echo "PIPELINE OK: preparar, revisar, confirmar, snapshots, adjuntos, cola, worker real, intervalos, bloqueo, fallos, pruebas, programados y duplicados. Solo entrega SMTP simulada; sin red.\n";
} finally {
    WorkerRuntime::release();
    if($trigger)$pdo->exec('DROP TRIGGER dbo.fixture_pipeline_log_error');
}
