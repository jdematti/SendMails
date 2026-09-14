<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
$kind = query_string('type') === 'invoices' ? 'invoice' : (query_string('type') === 'campaigns' ? 'campaign' : '');
redirect('activity.php?' . http_build_query(['kind' => $kind, 'channel' => query_string('channel')]));
