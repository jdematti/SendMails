<?php
declare(strict_types=1);
// Local, opt-in test router. Production never includes it.
if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(404); exit; }
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (strpos($path, '/assets/') === 0 || $path === '/favicon.png') return false;
$allowed = ['send.php','invoices.php','compose_api.php','activity.php','index.php','template_edit.php','invoice_template_edit.php','templates.php','invoice_templates.php','invoice_export.php','campaigns.php','invoice_sends.php','worker_control.php','purge.php'];
$page = ltrim($path, '/');
if (!in_array($page, $allowed, true)) { http_response_code(404); exit; }
if (($_POST['action'] ?? '') === 'send_preview') { http_response_code(403); exit('External transport disabled in fixtures.'); }
require __DIR__ . '/test_bootstrap.php';
Schema::ensure();
if ($page === 'send.php' || $page === 'invoices.php') {
    $composeKind = $page === 'send.php' ? 'campaign' : 'invoice';
    $page = 'compose.php';
}
$root = dirname(__DIR__);
$code = file_get_contents($root . '/' . $page);
$code = preg_replace('/require(?:_once)? __DIR__ \. \'\/app\/bootstrap\.php\';/', '', $code);
$code = str_replace('__DIR__', var_export($root, true), $code);
eval(preg_replace('/^<\?php\s*/', '', $code));
