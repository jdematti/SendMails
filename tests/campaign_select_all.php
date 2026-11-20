<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/test_bootstrap.php';
function check(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
$pdo=Database::pdo();Schema::ensure();$_SESSION['branch_id']=1;
$pdo->beginTransaction();
try {
    $pdo->exec("WITH n AS (SELECT TOP 16000 ROW_NUMBER() OVER(ORDER BY (SELECT NULL)) AS id FROM sys.all_objects a CROSS JOIN sys.all_objects b)
        INSERT INTO dbo.clientes(OId,codigo_cliente,razon_social,plan_contratado,DirEMail,telefono_movil,localidad,provincia)
        SELECT CONVERT(nvarchar(80),100000+id),'B'+CONVERT(nvarchar(40),id),'Cliente masivo '+CONVERT(nvarchar(40),id),
            CASE WHEN id%2=0 THEN 'Plan A' ELSE 'Plan B' END,
            CASE WHEN id%4=0 THEN NULL ELSE 'masivo'+CONVERT(nvarchar(40),id)+'@example.invalid' END,
            CASE WHEN id%3=0 THEN NULL ELSE '5491100000001' END,'Localidad','Provincia' FROM n;
        UPDATE dbo.clientes SET razon_social=NULL WHERE OId='100001';
        UPDATE dbo.clientes SET DirEMail=NULL,telefono_movil=NULL WHERE OId='100002';");
    $oldMs=0;$newMs=0;
    foreach([['',[]],['',['Plan A']],['',['Plan A','Plan B']],['Cliente',[]],['masivo',['Plan B']],
        ['masivo1@',[]],['54911',[]],['Sin razon social',[]],['No existe',[]]] as [$term,$plans]) {
        $started=microtime(true);$expected=ClientRepository::page($term,$plans,1,true);$oldMs+=(microtime(true)-$started)*1000;
        $started=microtime(true);$actual=ComposeService::search('campaign',ComposeService::input(['q'=>$term,'plans'=>$plans]),1,true);$newMs+=(microtime(true)-$started)*1000;
        sort($expected,SORT_STRING);sort($actual,SORT_STRING);
        check($actual===$expected,'Seleccion total equivalente para busqueda y planes: '.json_encode([$term,$plans]));
    }
    $ids=ComposeService::search('campaign',ComposeService::input([]),1,true);
    check(count($ids)>18000,'Seleccion grande conserva clientes con email o telefono');
    check(!in_array('100002',$ids,true),'Excluir clientes sin contacto');
    echo 'CAMPAIGN SELECT ALL OK: 20000 clientes de fixture, busqueda, planes y contactos equivalentes. Total de 9 consultas: anterior '.round($oldMs).' ms; nueva '.round($newMs)." ms. Base local; sin envios.\n";
} finally {if($pdo->inTransaction())$pdo->rollBack();}
