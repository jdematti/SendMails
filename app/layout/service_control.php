<?php
try { $serviceState = WorkerRuntime::serviceStatus((int) $currentBranch['id']); }
catch (Throwable $e) { $serviceState = ['healthy'=>false,'label'=>'Con error','message'=>'No se pudo leer el estado del proceso automático.','detail'=>$e->getMessage(),'last_activity'=>null,'enabled'=>true,'revision'=>-1,'state'=>'error']; }
?>
<button type="button" id="serviceStatusButton" class="service-status <?= $serviceState['healthy'] ? 'is-ok' : 'is-error' ?>" aria-haspopup="dialog" aria-controls="serviceDialog" title="Estado del proceso automático de <?= e($currentBranch['name']) ?>">Proceso: <?= e($serviceState['label']) ?></button>
<dialog id="serviceDialog" class="service-dialog" aria-labelledby="serviceTitle" data-branch="<?= (int) $currentBranch['id'] ?>" data-csrf="<?= e(csrf_token()) ?>">
    <div class="service-dialog-head"><h2 id="serviceTitle">Proceso automático</h2><button type="button" class="btn secondary" id="closeService" aria-label="Cerrar estado del proceso">Cerrar</button></div>
    <p class="service-branch">Sucursal: <strong><?= e($currentBranch['name']) ?></strong></p>
    <p id="serviceMessage" role="status"><?= e($serviceState['message']) ?></p>
    <p id="serviceLastActivity" class="hint">Última actividad: <?= e($serviceState['last_activity'] ?? 'Sin registro') ?></p>
    <p id="serviceDetail" class="alert error"<?= empty($serviceState['detail']) ? ' hidden' : '' ?>><?= e($serviceState['detail'] ?? '') ?></p>
    <p>Este control afecta solo a esta sucursal. Detener deja terminar el mensaje en curso; iniciar retoma los pendientes en la próxima ejecución automática, normalmente dentro de un minuto.</p>
    <p id="serviceNotice" role="status" hidden></p>
    <div class="actions"><button type="button" id="startService" class="btn">Iniciar</button><button type="button" id="stopService" class="btn danger">Detener</button></div>
</dialog>
<script type="application/json" id="serviceInitialState"><?= json_encode($serviceState, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
