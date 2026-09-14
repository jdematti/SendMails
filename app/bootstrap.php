<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') session_start();

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
configure_server_timezone();
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/SecretBox.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/BranchRepository.php';
require_once __DIR__ . '/SchemaNotReady.php';
require_once __DIR__ . '/MessageSnapshot.php';
require_once __DIR__ . '/AgilityMigration.php';
require_once __DIR__ . '/Schema.php';
require_once __DIR__ . '/UserRepository.php';
require_once __DIR__ . '/UnsubscribeRepository.php';
require_once __DIR__ . '/EmailExclusionRepository.php';
require_once __DIR__ . '/ClientRepository.php';
require_once __DIR__ . '/CampaignAttachments.php';
require_once __DIR__ . '/TemplateRepository.php';
require_once __DIR__ . '/QueueRepository.php';
require_once __DIR__ . '/InvoiceCrypto.php';
require_once __DIR__ . '/InvoiceRepository.php';
require_once __DIR__ . '/WhatsAppPhone.php';
require_once __DIR__ . '/WhatsAppApiException.php';
require_once __DIR__ . '/WhatsAppRepository.php';
require_once __DIR__ . '/WhatsAppBusinessService.php';
require_once __DIR__ . '/MailerService.php';
require_once __DIR__ . '/InvoiceMailerService.php';
require_once __DIR__ . '/WhatsAppMailerService.php';
require_once __DIR__ . '/UnifiedQueueService.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/BulkInsert.php';
require_once __DIR__ . '/LocalCache.php';
require_once __DIR__ . '/DraftRepository.php';
require_once __DIR__ . '/TemplateDraft.php';
require_once __DIR__ . '/ComposeService.php';
require_once __DIR__ . '/WorkerRuntime.php';
require_once __DIR__ . '/ActivityRepository.php';

if (!is_dir(STORAGE_PATH)) {
    mkdir(STORAGE_PATH, 0775, true);
}

if (PHP_SAPI !== 'cli' && is_file(STORAGE_PATH . '/maintenance.flag')) {
    http_response_code(503);
    header('Retry-After: 60');
    exit('SendMails se esta actualizando. Volve a intentar en unos minutos.');
}
try {
    Auth::enforceRequest();
} catch (SchemaNotReady $e) {
    http_response_code(503);
    exit(e($e->getMessage()));
}
