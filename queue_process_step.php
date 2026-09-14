<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');
http_response_code(409);
echo json_encode(['ok' => false, 'error' => 'Los envios se procesan automaticamente. Consulta el seguimiento y el estado del worker en activity.php.']);
