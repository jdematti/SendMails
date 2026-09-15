<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405); header('Allow: GET'); throw new RuntimeException('Método no permitido.');
    }
    if (!Auth::currentUser()) { http_response_code(401); throw new RuntimeException('Iniciá sesión nuevamente.'); }
    $branch = (int) BranchRepository::currentId();
    if ($branch <= 0 || $branch !== (int) query_string('branch_id')) {
        http_response_code(409); throw new RuntimeException('La sucursal cambió. Recargá la plantilla.');
    }
    session_write_close();
    $draftId = (int) query_string('draft_id');
    if ($draftId > 0) {
        $draft = DraftRepository::find($draftId, 'template');
        if ($draft['state'] === 'sent') throw new RuntimeException('El borrador ya fue publicado.');
        $template = $draft['payload']['template'] ?? [];
    } else {
        $template = TemplateRepository::find((int) query_string('template_id'));
        if (!$template) throw new RuntimeException('Plantilla no encontrada.');
    }
    $files = CampaignAttachments::fromJson($template['attachments_json'] ?? null);
    $index = filter_var(query_string('index'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
    if ($index === false || !isset($files[$index])) throw new RuntimeException('Adjunto no encontrado.');
    $file = $files[$index];
    $fingerprint = hash('sha256', $file['name'] . "\0" . $file['content']);
    if (!hash_equals($fingerprint, query_string('fingerprint'))) {
        http_response_code(409); throw new RuntimeException('El adjunto cambió. Guardá tus cambios en un borrador o recargá la plantilla para ver la versión actual.');
    }
    header('Content-Type: ' . $file['type']);
    header('Content-Disposition: attachment; filename="adjunto"; filename*=UTF-8\'\'' . rawurlencode($file['name']));
    header("Content-Security-Policy: sandbox; default-src 'none'");
    header('Content-Length: ' . $file['size']);
    echo base64_decode($file['content'], true);
} catch (Throwable $e) {
    if (http_response_code() < 400) http_response_code(404);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error'=>$e instanceof PDOException ? 'No se pudo cargar el adjunto. Intentá nuevamente.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
