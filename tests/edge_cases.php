<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/test_bootstrap.php';
function check(bool $condition,string $message):void { if (!$condition) throw new RuntimeException($message); }
function rejects(callable $call,string $message):void { try { $call(); } catch(Throwable $e) { echo "OK rechazo: $message\n"; return; } throw new RuntimeException($message); }
$pdo=Database::pdo(); Schema::ensure();
$template=TemplateRepository::all(true)[0];
$input=ComposeService::input(['name'=>'Manual sin cliente','channel'=>'email','template_id'=>$template['id'],'scheduled_at'=>'2026-09-14T10:00',
    'manual_emails'=>'manual@example.invalid manual@example.invalid','ids'=>[]]);
$review=ComposeService::review('campaign',$input);
check($review['counts']['email']===1 && $review['counts']['email_duplicates']===1,'Manual-only y deduplicacion');
$classified=ComposeService::classify('campaign',[
    ['email'=>'wrong','telefono_movil'=>'wrong'],
    ['email'=>'blocked@example.invalid','telefono_movil'=>'5491100000001'],
    ['email'=>'ok@example.invalid','telefono_movil'=>'5491100000002'],
    ['email'=>'ok@example.invalid','telefono_movil'=>'5491100000002']], 'both',
    ['blocked@example.invalid'=>true],['5491100000001'=>true]);
check($classified['counts']===['selected'=>4,'email'=>1,'whatsapp'=>1,'email_invalid'=>1,'email_excluded'=>1,'email_duplicates'=>1,'phone_invalid'=>1,'phone_excluded'=>1,'phone_duplicates'=>1], 'Resumen exacto de exclusiones');
Settings::saveWhatsapp(['graph_version'=>'v25.0','waba_id'=>'fixture','phone_number_id'=>'fixture','access_token'=>'fixture-only','is_enabled'=>true],1);
$stmt=$pdo->prepare("INSERT INTO dbo.SendMail_WhatsAppTemplates(branch_id,meta_template_id,name,language,category,status,components_json,body_variables_json,is_active)
    OUTPUT INSERTED.id VALUES(1,:meta,'fixture_template','es_AR','MARKETING','APPROVED',:components,'[]',1)");
$stmt->execute([':meta'=>bin2hex(random_bytes(8)),':components'=>json_encode([['type'=>'BODY','text'=>'Mensaje de prueba']])]);
$waId=(int)$stmt->fetchColumn();
$input=ComposeService::input(['name'=>'Dual atomico','channel'=>'both','template_id'=>$template['id'],'whatsapp_template_id'=>$waId,'scheduled_at'=>'2026-09-14T10:00','ids'=>['1','2']]);
$review=ComposeService::review('campaign',$input);
$draft=DraftRepository::save(0,'campaign',$input['name'],['input'=>$input,'review'=>$review],0,'review');
$before=(int)$pdo->query('SELECT COUNT(*) FROM dbo.SendMail_Queue')->fetchColumn();
$pdo->exec("CREATE TRIGGER dbo.fixture_reject_whatsapp ON dbo.SendMail_WhatsAppQueue AFTER INSERT AS THROW 51000, 'Fallo simulado de WhatsApp', 1;");
try { rejects(static fn()=>ComposeService::confirm('campaign',$draft['id'],$draft['revision']),'fallo segundo canal'); }
finally { $pdo->exec('DROP TRIGGER dbo.fixture_reject_whatsapp'); }
check((int)$pdo->query('SELECT COUNT(*) FROM dbo.SendMail_Queue')->fetchColumn()===$before,'Rollback email si falla WhatsApp');
$result=ComposeService::confirm('campaign',$draft['id'],$draft['revision']);
check(count($result['links'])===2,'Confirmacion de ambos canales');
$row=WhatsAppRepository::pending('campaign',1)[0];
check($row['template_name']==='fixture_template','Snapshot WhatsApp');
$pdo->exec("UPDATE dbo.SendMail_WhatsAppTemplates SET name='edited_template' WHERE id=$waId");
check(WhatsAppRepository::pending('campaign',1)[0]['template_name']==='fixture_template','Snapshot WhatsApp estable');
$source=TemplateRepository::find((int)$template['id']);
$context=TemplateDraft::load('template',0,(int)$source['id'],$source);
TemplateDraft::store($context,$source,0);
$stale=$context;
TemplateDraft::store($context,array_replace($source,['name'=>'Cambio compartido']),$context['revision']);
rejects(static function()use(&$stale,$source):void { TemplateDraft::store($stale,$source,$stale['revision']); },'borrador de plantilla concurrente');
rejects(static fn()=>TemplateDraft::publish($stale,static fn()=>0),'publicacion obsoleta');
$snapshot=MessageSnapshot::capture(['subject'=>'snapshot','html_body'=>'html','attachments_json'=>'[]','id'=>999,'branch_id'=>999]);
$safe=MessageSnapshot::apply(['id'=>12,'branch_id'=>1,'message_snapshot'=>$snapshot]);
check($safe['id']===12 && $safe['branch_id']===1,'Snapshot no altera identificadores ni permisos');
// Upgrade an already populated installation; retain unrelated user/branch assignments.
$pdo->exec("UPDATE dbo.SendMail_Campaigns SET message_snapshot=NULL WHERE status='queued';
    UPDATE dbo.SendMail_InvoiceBatches SET message_snapshot=NULL WHERE status IN ('queued','processing');
    UPDATE dbo.SendMail_InvoiceTemplates SET test_mode=1;
    UPDATE dbo.SendMail_InvoiceQueue SET is_test=0 WHERE status='pending';
    UPDATE dbo.SendMail_Settings SET setting_value='previous' WHERE setting_key='schema_version';");
Schema::migrate();
check((int)$pdo->query("SELECT COUNT(*) FROM dbo.SendMail_Campaigns WHERE status='queued' AND message_snapshot IS NULL")->fetchColumn()===0,'Migracion captura pendientes');
check((int)$pdo->query("SELECT COUNT(*) FROM dbo.SendMail_InvoiceQueue WHERE status='pending' AND is_test=0")->fetchColumn()===0,'Migracion identifica pruebas pendientes');
$fixtureLock=fopen(STORAGE_PATH.'/worker.lock','c+'); flock($fixtureLock,LOCK_EX);
check(!WorkerRuntime::acquire(),'Un solo worker a la vez');
flock($fixtureLock,LOCK_UN);fclose($fixtureLock);
check(WorkerRuntime::acquire(),'Worker puede adquirir bloqueo libre');
WorkerRuntime::release('idle',0);
check(WorkerRuntime::status()['healthy'],'Heartbeat visible');
file_put_contents(STORAGE_PATH.'/maintenance.flag','fixture');
check(!WorkerRuntime::acquire(),'Mantenimiento impide nuevos workers');
unlink(STORAGE_PATH.'/maintenance.flag');
echo "EDGE CASES OK: canales atomicos, snapshots, manuales, exclusiones, concurrencia y mantenimiento. Sin transportes externos.\n";
