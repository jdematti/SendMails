<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$status = query_string('status');
$params = ['type' => UnifiedQueueService::TYPE_INVOICES];
if (in_array($status, ['pending', 'sent', 'failed', 'skipped'], true)) {
    $params['status'] = $status;
}

redirect('queue.php?' . http_build_query($params));
