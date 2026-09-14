<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/test_bootstrap.php';
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejects(callable $call, string $message): void
{
    try { $call(); } catch (Throwable $e) { echo "OK rechazo: $message\n"; return; }
    throw new RuntimeException('No rechazo: ' . $message);
}
$pdo = Database::pdo();
Schema::migrate();
Schema::migrate();
check($pdo->query("SELECT setting_value FROM dbo.SendMail_Settings WHERE setting_key='schema_version'")->fetchColumn() === Schema::VERSION, 'Version de esquema');
echo "OK migracion inicial e idempotencia\n";
$pdo->exec("IF OBJECT_ID('dbo.FacturasTel','U') IS NOT NULL DROP TABLE dbo.FacturasTel;
    IF OBJECT_ID('dbo.clientes','U') IS NOT NULL DROP TABLE dbo.clientes;
    CREATE TABLE dbo.clientes(OId nvarchar(80) PRIMARY KEY,codigo_cliente nvarchar(40),razon_social nvarchar(150),plan_contratado nvarchar(50),
        DirEMail nvarchar(320),telefono_movil nvarchar(30),localidad nvarchar(80),provincia nvarchar(80));
    CREATE TABLE dbo.FacturasTel(Id nvarchar(80) PRIMARY KEY,IdCliente nvarchar(80),V1_M_Final decimal(18,2),Nombre nvarchar(150),SNB nvarchar(80),
        V1_FechaVto datetime2,Estado char(1),Autorizada bit,mail_enviado bit,fecha_mail_enviado datetime2 NULL);
    WITH n AS (SELECT TOP 4000 ROW_NUMBER() OVER(ORDER BY (SELECT NULL)) AS id FROM sys.all_objects a CROSS JOIN sys.all_objects b)
    INSERT INTO dbo.clientes SELECT CONVERT(nvarchar(80),id),RIGHT('00000'+CONVERT(varchar,id),5),N'Cliente '+RIGHT('00000'+CONVERT(varchar,id),5),
        CASE WHEN id%2=0 THEN 'Plan A' ELSE 'Plan B' END,'cliente'+CONVERT(varchar,id)+'@example.invalid',
        '54911'+RIGHT('00000000'+CONVERT(varchar,id),8),'Localidad','Provincia' FROM n;
    INSERT INTO dbo.FacturasTel SELECT 'F'+OId,OId,1500,razon_social,codigo_cliente,'2026-09-30T12:00:00','I',1,0,NULL FROM dbo.clientes;");
if (!is_file(STORAGE_PATH . '/invoice_crypto.key')) file_put_contents(STORAGE_PATH . '/invoice_crypto.key', 'test-only-invoice-key-never-production');
// Store only dummy transport settings. Neither the worker nor an external transport is executed.
$stmt = $pdo->prepare("UPDATE dbo.SendMail_Settings SET setting_value=:value WHERE setting_key='smtp:1'");
$smtp = ['host'=>'127.0.0.1','port'=>9,'from_email'=>'sender@example.invalid','from_name'=>'Fixture','interval_seconds'=>1];
$stmt->execute([':value'=>json_encode($smtp)]);
if (!$stmt->rowCount()) { $stmt=$pdo->prepare("INSERT INTO dbo.SendMail_Settings(setting_key,setting_value) VALUES('smtp:1',:value)"); $stmt->execute([':value'=>json_encode($smtp)]); }
$page1 = ClientRepository::page('', []);
$page2 = ClientRepository::page('', [], 2);
check(count($page1['rows'])===50 && $page1['total']===4000, 'Paginacion 4000 clientes');
check(!array_intersect(array_column($page1['rows'],'oid'),array_column($page2['rows'],'oid')), 'Paginas sin solapamientos');
check(ClientRepository::page('', ['Plan A'])['total']===2000, 'Filtro de planes');
check(count(ClientRepository::findByOids(array_map('strval',range(1,4000))))===4000, 'Seleccion dividida bajo 2100 parametros');
echo "OK busqueda y seleccion de 4000 clientes\n";
$invoiceTemplate = InvoiceRepository::defaultTemplate();
$invoicePage = InvoiceRepository::search('2026-09-30','I',false,$invoiceTemplate,[],50,'email',2,false);
check(count($invoicePage)===50, 'Paginacion facturas');
check(InvoiceRepository::searchTotal('2026-09-30','I',false)===4000, 'Conteo facturas');
check(count(InvoiceRepository::matchingIds('2026-09-30','I',false))===4000, 'Todos resultados facturas');
rejects(static fn()=>InvoiceRepository::searchTotal('2026-02-31','I',false), 'fecha imposible');
echo "OK filtros, fecha por rango y paginacion de facturas\n";
$input = ComposeService::input(['name'=>'Prueba 4000','channel'=>'email','scheduled_at'=>'2026-09-14T10:00',
    'template_id'=>TemplateRepository::all(true)[0]['id'],'ids'=>array_map('strval',range(1,4000))]);
$start=microtime(true);
$review = ComposeService::review('campaign',$input);
check($review['counts']['email']===4000,'Revision email 4000');
$saved=DraftRepository::save(0,'campaign',$input['name'],['input'=>$input,'review'=>$review],0,'review');
$result=ComposeService::confirm('campaign',$saved['id'],$saved['revision']);
$batch=$result['links'][0]['id'];
check((int)$pdo->query("SELECT COUNT(*) FROM dbo.SendMail_Queue WHERE campaign_id=$batch")->fetchColumn()===4000,'Cola completa');
$repeat=ComposeService::confirm('campaign',$saved['id'],$saved['revision']);
check($repeat===$result,'Confirmacion idempotente');
echo 'OK revision + confirmacion de 4000: '.round((microtime(true)-$start)*1000)." ms (base temporal local)\n";
$before=QueueRepository::pending(1)[0]['subject'];
$pdo->exec("UPDATE dbo.SendMail_Templates SET subject='CAMBIO POSTERIOR' WHERE id=".(int)$input['template_id']);
check(QueueRepository::pending(1)[0]['subject']===$before,'Snapshot estable');
$meta=QueueRepository::pending(1,true)[0];
check(!isset($meta['html_body']) && !isset($meta['attachments_json']), 'Seleccion del worker sin payload');
$draft=DraftRepository::save(0,'campaign','Compartido',['input'=>$input],0);
DraftRepository::save($draft['id'],'campaign','Editado',['input'=>$input],$draft['revision']);
rejects(static fn()=>DraftRepository::save($draft['id'],'campaign','Viejo',['input'=>$input],$draft['revision']), 'revision concurrente');
$_SESSION['branch_id']=2;
rejects(static fn()=>DraftRepository::find($draft['id'],'campaign'), 'aislamiento de sucursal');
$_SESSION['branch_id']=1;
$testInvoice=$invoicePage[0];
$invoiceBatch=null;
InvoiceRepository::createQueue((int)$invoiceTemplate['id'],[$testInvoice],'Test factura',null,null,$invoiceBatch);
$queue=(int)$pdo->query("SELECT TOP 1 id FROM dbo.SendMail_InvoiceQueue WHERE batch_id=$invoiceBatch")->fetchColumn();
InvoiceRepository::markSent($queue,$testInvoice['invoice_id'],1,true);
$stmt=$pdo->prepare("SELECT mail_enviado FROM dbo.FacturasTel WHERE Id=:id");$stmt->execute([':id'=>$testInvoice['invoice_id']]);
check(!(bool)$stmt->fetchColumn(),'Test no marca factura');
check((int)$pdo->query("SELECT is_test FROM dbo.SendMail_InvoiceQueue WHERE id=$queue")->fetchColumn()===1,'Test identificado en cola');
$filters=ActivityRepository::filters([]);
check(ActivityRepository::summary($filters)['tests']>=1,'Metricas de prueba');
check(count(ActivityRepository::page($filters)['rows'])===50,'Historial paginado');
check(count(ActivityRepository::batches($filters))>=2,'Lotes unificados');
echo "OK copias fijas, concurrencia, sucursales, modo test y seguimiento\n";
rejects(static fn()=>BulkInsert::rows($pdo,'Users',[['id'=>1]]),'tabla fuera de colas');
echo "INTEGRACION OK. Sin envios de email ni WhatsApp.\n";
