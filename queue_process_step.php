<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

set_time_limit(0);
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo no permitido.']);
    exit;
}

$token = $_POST['csrf_token'] ?? null;
if (!csrf_is_valid(is_string($token) ? $token : null)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF invalido.']);
    exit;
}

try {
    $type = UnifiedQueueService::normalizeType(post_string('type'));
    $channel = UnifiedQueueService::normalizeChannel(post_string('channel'));
    $result = UnifiedQueueService::processStep($type, $channel);
    echo json_encode([
        'ok' => true,
        'processed' => (int) $result['processed'],
        'sent' => (int) $result['sent'],
        'failed' => (int) $result['failed'],
        'skipped' => (int) ($result['skipped'] ?? 0),
        'errors' => $result['errors'],
        'pending' => (int) $result['pending'],
        'type' => $result['type'],
        'channel' => $result['channel'],
        'interval_seconds' => (int) ($result['interval_seconds'] ?? 0),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
