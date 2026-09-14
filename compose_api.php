<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); throw new RuntimeException('Metodo no permitido.'); }
    $request = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($request) || !csrf_is_valid((string) ($request['csrf_token'] ?? ''))) {
        http_response_code(419); throw new RuntimeException('La sesion vencio. Recarga la pagina.');
    }
    $kind = (string) ($request['kind'] ?? '');
    if (!in_array($kind, ['campaign', 'invoice'], true)) throw new InvalidArgumentException('Tipo de envio invalido.');
    $input = ComposeService::input(is_array($request['input'] ?? null) ? $request['input'] : []);
    $id = max(0, (int) ($request['id'] ?? 0));
    $revision = max(0, (int) ($request['revision'] ?? 0));
    // Authentication and branch validation are complete. Other tabs need not wait for this query.
    session_write_close();
    switch ($request['action'] ?? '') {
        case 'search': $result = ComposeService::search($kind, $input, max(1, (int) ($request['page'] ?? 1))); break;
        case 'select_all': $result = ['ids' => ComposeService::search($kind, $input, 1, true)]; break;
        case 'load':
            $draft = DraftRepository::find($id, $kind);
            if ($draft['state'] === 'sent') throw new RuntimeException('Este borrador ya fue confirmado.');
            $result = ['id' => $id, 'revision' => (int) $draft['revision'], 'input' => $draft['payload']['input']];
            break;
        case 'save': $result = DraftRepository::save($id, $kind, $input['name'], ['input' => $input], $revision); break;
        case 'review':
            $review = ComposeService::review($kind, $input);
            $result = DraftRepository::save($id, $kind, $input['name'], ['input' => $input, 'review' => $review], $revision, 'review')
                + ['counts' => $review['counts'], 'preview' => $review['preview'], 'scheduled_at' => $review['scheduled_at']];
            break;
        case 'confirm': $result = ComposeService::confirm($kind, $id, $revision); break;
        default: throw new InvalidArgumentException('Accion invalida.');
    }
    echo json_encode(['ok' => true] + $result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if (http_response_code() < 400) http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
