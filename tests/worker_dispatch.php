<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
define('STORAGE_PATH',sys_get_temp_dir().'/sendmails-worker-test-'.bin2hex(random_bytes(8)));
mkdir(STORAGE_PATH,0700,true);
$_SESSION=['branch_id'=>99];
final class Database {
    public static function pdo() {
        return new class { public function query(string $sql) { return new class { public function fetchAll(int $mode):array { return [1,2]; } }; } };
    }
}
final class UnifiedQueueService {
    public const TYPE_ALL='';
    public static array $remaining=[1=>2,2=>2];
    public static array $sent=[];
    public static $afterSend = null;
    public static array $failBranches = [];
    public static function processStep(string $type,string $channel,bool $withCounts):array {
        $branch=$_SESSION['branch_id'];
        if (in_array($branch, self::$failBranches, true)) throw new RuntimeException('Error simulado de sucursal');
        if ($channel==='email' && self::$remaining[$branch]>0) {
            self::$remaining[$branch]--;
            self::$sent[]=['branch'=>$branch,'at'=>time()];
            if (self::$afterSend) (self::$afterSend)($branch);
            return ['processed'=>1,'sent'=>1,'failed'=>0,'skipped'=>0,'errors'=>[],'interval_seconds'=>2];
        }
        return ['processed'=>0,'sent'=>0,'failed'=>0,'skipped'=>0,'errors'=>[],'interval_seconds'=>0];
    }
}
require __DIR__.'/../app/WorkerControl.php';
require __DIR__.'/../app/WorkerRuntime.php';
function verify(bool $condition,string $message):void { if(!$condition)throw new RuntimeException($message); }
try {
    verify(WorkerRuntime::acquire(),'Adquirir bloqueo');
    $result=WorkerRuntime::process(4,10);
    WorkerRuntime::release('idle',$result['processed']);
    verify($result['sent']===4,'Procesamiento completo');
    verify(array_column(UnifiedQueueService::$sent,'branch')===[1,2,1,2],'Alternancia de sucursales');
    verify(UnifiedQueueService::$sent[2]['at']-UnifiedQueueService::$sent[0]['at']>=2,'Intervalo sucursal 1');
    verify(UnifiedQueueService::$sent[3]['at']-UnifiedQueueService::$sent[1]['at']>=2,'Intervalo sucursal 2');
    verify($_SESSION['branch_id']===99,'Restaurar contexto');
    $state=WorkerRuntime::status();
    verify(isset($state['next_by_channel']['1:email'],$state['next_by_channel']['2:email']),'Intervalos persistidos');
    verify(WorkerRuntime::serviceStatus(1)['healthy'], 'Sucursal activa en verde');
    WorkerControl::set(1,false,1,0);
    UnifiedQueueService::$remaining=[1=>2,2=>2]; UnifiedQueueService::$sent=[];
    verify(WorkerRuntime::acquire(),'Adquirir con una sucursal detenida');
    $result=WorkerRuntime::process(4,8); WorkerRuntime::release('idle',$result['processed']);
    verify(array_column(UnifiedQueueService::$sent,'branch')===[2,2], 'Detener una sucursal conserva las otras activas');
    verify(WorkerRuntime::serviceStatus(1)['state']==='stopped' && !WorkerRuntime::serviceStatus(1)['healthy'],'Sucursal detenida en rojo');
    WorkerControl::set(1,true,1,1);
    UnifiedQueueService::$remaining=[1=>2,2=>2]; UnifiedQueueService::$sent=[];
    UnifiedQueueService::$afterSend=static function(int $branch):void { if($branch===1)WorkerControl::set(1,false,1,2); };
    verify(WorkerRuntime::acquire(),'Adquirir para detener durante un envio simulado');
    $result=WorkerRuntime::process(4,8); WorkerRuntime::release('idle',$result['processed']);
    verify(array_column(UnifiedQueueService::$sent,'branch')===[1,2,2], 'Termina el envio en curso sin iniciar otro en esa sucursal');
    verify(UnifiedQueueService::$remaining[1]===1,'Conservar el pendiente al detener');
    UnifiedQueueService::$afterSend=null;
    WorkerControl::set(1,true,1,3);
    UnifiedQueueService::$remaining=[1=>1,2=>0]; UnifiedQueueService::$sent=[];
    verify(WorkerRuntime::acquire(),'Reanudar');
    $result=WorkerRuntime::process(1,8); WorkerRuntime::release('idle',$result['processed']);
    verify(array_column(UnifiedQueueService::$sent,'branch')===[1], 'Iniciar retoma pendientes');
    $rejected=false;
    try {WorkerControl::set(1,false,1,0);}catch(RuntimeException $e){$rejected=true;}
    verify($rejected && WorkerControl::read(1)['enabled'],'Rechazar control obsoleto sin cambiar estado');
    UnifiedQueueService::$failBranches=[1]; UnifiedQueueService::$remaining=[1=>1,2=>1];
    verify(WorkerRuntime::acquire(),'Adquirir para probar aislamiento de fallos');
    $result=WorkerRuntime::process(2,8); WorkerRuntime::release('idle',$result['processed']);
    verify(WorkerRuntime::serviceStatus(1)['state']==='error' && WorkerRuntime::serviceStatus(2)['healthy'],'Error en rojo sin detener otra sucursal');
    WorkerControl::set(1,false,1,4);
    $stale=WorkerRuntime::status();$stale['status']='running';$stale['active_branch']=1;
    file_put_contents(STORAGE_PATH.'/worker-status.json',json_encode($stale));
    verify(WorkerRuntime::serviceStatus(1)['state']==='stopped','Un heartbeat viejo no deja la sucursal deteniendose para siempre');
    echo "WORKER OK: bloqueo, sucursales alternadas, limites por canal, intervalos persistidos y contexto restaurado. Transporte simulado.\n";
} finally {
    WorkerRuntime::release();
    foreach (glob(STORAGE_PATH.'/*') as $file) if(is_file($file))unlink($file);
    rmdir(STORAGE_PATH);
}
