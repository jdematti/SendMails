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
    public static function processStep(string $type,string $channel,bool $withCounts):array {
        $branch=$_SESSION['branch_id'];
        if ($channel==='email' && self::$remaining[$branch]>0) {
            self::$remaining[$branch]--;
            self::$sent[]=['branch'=>$branch,'at'=>time()];
            return ['processed'=>1,'sent'=>1,'failed'=>0,'skipped'=>0,'errors'=>[],'interval_seconds'=>2];
        }
        return ['processed'=>0,'sent'=>0,'failed'=>0,'skipped'=>0,'errors'=>[],'interval_seconds'=>0];
    }
}
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
    echo "WORKER OK: bloqueo, sucursales alternadas, limites por canal, intervalos persistidos y contexto restaurado. Transporte simulado.\n";
} finally {
    WorkerRuntime::release();
    foreach (glob(STORAGE_PATH.'/*') as $file) if(is_file($file))unlink($file);
    rmdir(STORAGE_PATH);
}
