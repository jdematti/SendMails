<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
define('SENDMAILS_TEST_REAL_AUTH', true);
return require __DIR__ . '/fixture_router.php';
