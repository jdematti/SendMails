<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/test_bootstrap.php';
function check(bool $condition,string $message):void { if(!$condition)throw new RuntimeException($message); }
function checkAffectedRows(PDO $pdo):void {
    $stmt=$pdo->prepare('UPDATE dbo.FacturasTel SET Estado=Estado WHERE Id=:id');
    $stmt->execute([':id'=>'F1000']);
    check($stmt->rowCount()===1,'Las consultas de lectura no deshabilitan el conteo usado al reclamar envios');
}
$pdo=Database::pdo(); Schema::ensure();
$database=(string)getenv('SENDMAILS_TEST_DATABASE');
if(!preg_match('/^SendMails_Agility_Test_[a-f0-9]{12}$/D',$database))throw new RuntimeException('Base inesperada');
$pdo->beginTransaction();
try {
    $_SESSION['branch_id']=1;
    $default=(string)$pdo->query("SELECT CONVERT(varchar(80),DATABASEPROPERTYEX(DB_NAME(),'Collation'))")->fetchColumn();
    $other=$default==='Modern_Spanish_CI_AS'?'SQL_Latin1_General_CP850_CI_AS':'Modern_Spanish_CI_AS';
    $pdo->exec("CREATE VIEW dbo.v_sendmail_clientes AS SELECT OId COLLATE $other AS OId,
        codigo_cliente,razon_social,plan_contratado,DirEMail,telefono_movil,localidad,provincia FROM dbo.clientes");
    $pk=(string)$pdo->query("SELECT name FROM sys.key_constraints WHERE parent_object_id=OBJECT_ID('dbo.FacturasTel') AND type='PK'")->fetchColumn();
    $pk=str_replace(']',']]',$pk);
    $pdo->exec("ALTER TABLE dbo.FacturasTel DROP CONSTRAINT [$pk];
        ALTER TABLE dbo.FacturasTel ALTER COLUMN Id nvarchar(80) COLLATE $other NOT NULL;
        ALTER TABLE dbo.FacturasTel ADD PRIMARY KEY(Id);
        DROP INDEX UX_SendMail_Unsubscribes_BranchEmail ON dbo.SendMail_Unsubscribes;
        ALTER TABLE dbo.SendMail_Unsubscribes ALTER COLUMN email_normalized nvarchar(320) COLLATE $other NOT NULL;
        CREATE UNIQUE INDEX UX_SendMail_Unsubscribes_BranchEmail ON dbo.SendMail_Unsubscribes(branch_id,email_normalized);
        INSERT INTO dbo.SendMail_Unsubscribes(branch_id,email,email_normalized) VALUES(1,'cliente1@example.invalid','cliente1@example.invalid');
        INSERT INTO dbo.SendMail_EmailExclusions(email,email_normalized) VALUES('cliente2@example.invalid','cliente2@example.invalid');");
    $ids=array_map('strval',range(1,1000));
    $conflict=false;
    try {
        $pdo->query("DECLARE @old_selection TABLE(oid nvarchar(4000)); INSERT INTO @old_selection VALUES('1');
            SELECT COUNT(*) FROM dbo.v_sendmail_clientes WHERE OId IN (SELECT oid FROM @old_selection)");
    } catch(PDOException $e) { $conflict=(int)($e->errorInfo[1]??0)===468; }
    check($conflict,'La fixture reproduce el conflicto de intercalacion original');
    $clients=ClientRepository::findByOids($ids);
    check(count($clients)===1000,'Clientes con intercalacion distinta');
    checkAffectedRows($pdo);
    $blocked=EmailExclusionRepository::campaignBlockedEmails(array_column($clients,'email'),1);
    check(isset($blocked['cliente1@example.invalid'],$blocked['cliente2@example.invalid']),'Bajas y exclusiones con distintas intercalaciones');
    checkAffectedRows($pdo);
    $template=InvoiceRepository::defaultTemplate();
    $invoices=InvoiceRepository::search('2026-09-30','I',true,$template,['selected_ids'=>array_map(static fn(string $id):string=>'F'.$id,$ids)],0,'email',1,false);
    check(count($invoices)===1000,'Facturas con intercalacion distinta');
    checkAffectedRows($pdo);
    $input=ComposeService::input(['name'=>'Intercalaciones','channel'=>'email','scheduled_at'=>'2026-11-19T12:00',
        'template_id'=>TemplateRepository::all(true)[0]['id'],'ids'=>$ids]);
    $review=ComposeService::review('campaign',$input);
    check($review['counts']['email']===998 && $review['counts']['email_excluded']===2,'Revision completa con intercalaciones mixtas');
    $pdo->exec("ALTER TABLE dbo.SendMail_InvoiceQueue ALTER COLUMN client_name nvarchar(250) COLLATE $other NULL;
        ALTER TABLE dbo.SendMail_InvoiceQueue ALTER COLUMN email_to nvarchar(320) COLLATE $other NOT NULL;
        ALTER TABLE dbo.SendMail_InvoiceQueue ALTER COLUMN last_error nvarchar(max) COLLATE $other NULL;
        ALTER TABLE dbo.SendMail_WhatsAppBatches DROP CONSTRAINT CK_SendMail_WhatsAppBatches_source;
        ALTER TABLE dbo.SendMail_WhatsAppBatches ALTER COLUMN source_type nvarchar(20) COLLATE $other NOT NULL;
        ALTER TABLE dbo.SendMail_WhatsAppBatches ADD CONSTRAINT CK_SendMail_WhatsAppBatches_source CHECK(source_type IN ('campaign','invoice'));
        ALTER TABLE dbo.SendMail_WhatsAppBatches ALTER COLUMN name nvarchar(180) COLLATE $other NOT NULL;
        INSERT INTO dbo.SendMail_WhatsAppBatches(branch_id,source_type,template_id,name,status)
            VALUES(1,'campaign',1,'Intercalacion WhatsApp','queued');
        DECLARE @batch int=SCOPE_IDENTITY();
        INSERT INTO dbo.SendMail_WhatsAppQueue(branch_id,batch_id,template_id,phone_to,status)
            VALUES(1,@batch,1,'5491100000001','pending');");
    $filters=ActivityRepository::filters([]);
    check(count(ActivityRepository::page($filters)['rows'])>0,'Pagina unificada con columnas de distinta intercalacion');
    check(ActivityRepository::batches($filters)!==[],'Lotes unificados con nombres y tipos de distinta intercalacion');
    check(ActivityRepository::summary($filters)['pending']>0,'Resumen unificado');
    $waFilters=ActivityRepository::filters(['kind'=>'campaign','channel'=>'whatsapp']);
    check(count(ActivityRepository::page($waFilters)['rows'])===1,'Cruce del tipo WhatsApp con tabla auxiliar de paginacion');
    checkAffectedRows($pdo);
    echo "COLLATIONS OK: clientes, facturas, bajas, exclusiones y revision con Modern_Spanish y SQL_Latin1_General_CP850. Base temporal; sin envios.\n";
} finally { if($pdo->inTransaction())$pdo->rollBack(); }
