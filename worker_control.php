<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
try {
    $branch = (int) BranchRepository::currentId();
    $requestedBranch = (int) ($_GET['branch_id'] ?? 0);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($input) || !csrf_is_valid((string) ($input['csrf_token'] ?? ''))) {
            http_response_code(419); throw new RuntimeException('La sesión venció. Recargá la página.');
        }
        $requestedBranch = (int) ($input['branch_id'] ?? 0);
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405); throw new RuntimeException('Método no permitido.');
    }
    if ($branch <= 0 || $branch !== $requestedBranch) {
        http_response_code(409); throw new RuntimeException('La sucursal seleccionada cambió. Recargá la página para ver y controlar la sucursal actual.');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $input['action'] ?? '';
        if (!in_array($action, ['start','stop'], true)) throw new InvalidArgumentException('Acción inválida.');
        WorkerControl::set($branch, $action === 'start', (int) Auth::currentUser()['id'], (int) ($input['revision'] ?? -1));
    }
    session_write_close();
    echo json_encode(['ok'=>true, 'service'=>WorkerRuntime::serviceStatus($branch)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (http_response_code() < 400) http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
