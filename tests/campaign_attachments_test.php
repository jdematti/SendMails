<?php

declare(strict_types=1);

// Run without bootstrap: no database changes and no outbound messages.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/CampaignAttachments.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejected(callable $operation): void
{
    try {
        $operation();
    } catch (InvalidArgumentException | RuntimeException $e) {
        return;
    }
    throw new RuntimeException('An invalid attachment was accepted.');
}

$binary = "Contenido adjunto\x00\x01\xff\r\n";
$files = [
    ['name' => 'Información.txt', 'content' => base64_encode('Oferta de septiembre')],
    ['name' => 'datos.bin', 'content' => base64_encode($binary)],
];
$stored = CampaignAttachments::toJson($files);
$restored = CampaignAttachments::fromJson($stored);
check(count($restored) === 2, 'Attachments must survive persistence.');
check(base64_decode($restored[1]['content'], true) === $binary, 'Binary bytes changed.');
check($restored[1]['size'] === strlen($binary), 'Size must come from content.');
check(CampaignAttachments::fromJson(null) === [], 'Legacy templates must work.');
check(CampaignAttachments::fromJson('[]') === [], 'Empty list must work.');
check(CampaignAttachments::withUploads($restored, [], []) === $restored, 'Editing HTML must retain attachments.');
$removed = CampaignAttachments::withUploads($restored, [], ['0']);
check(count($removed) === 1 && $removed[0]['name'] === 'datos.bin', 'Remove only selected attachment.');
check(CampaignAttachments::withUploads($restored, [], ['0', '1']) === [], 'Remove all attachments.');
$safe = CampaignAttachments::validate([['name' => "C:\\fakepath\\oferta\r\n.txt", 'content' => base64_encode('abc'), 'size' => 999]]);
check($safe[0]['name'] === 'oferta.txt' && $safe[0]['size'] === 3, 'Normalize filenames and ignore supplied size.');

rejected(static fn () => CampaignAttachments::fromJson('{bad json'));
rejected(static fn () => CampaignAttachments::fromJson('null'));
rejected(static fn () => CampaignAttachments::validate([['name' => 'x', 'content' => '!']]));
rejected(static fn () => CampaignAttachments::validate([['name' => 'x', 'content' => '']]));
rejected(static fn () => CampaignAttachments::validate(array_fill(0, 6, $files[0])));
$large = ['name' => 'large.bin', 'content' => base64_encode(str_repeat('x', 6 * 1024 * 1024))];
rejected(static fn () => CampaignAttachments::validate([$large, $large]));
unset($large);
rejected(static fn () => CampaignAttachments::withUploads([], ['name' => 'bad-shape'], []));
rejected(static fn () => CampaignAttachments::withUploads([], [
    'name' => ['x.txt'], 'tmp_name' => [__FILE__], 'error' => [UPLOAD_ERR_OK], 'size' => [1],
], []));
foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_TMP_DIR] as $error) {
    rejected(static fn () => CampaignAttachments::withUploads([], [
        'name' => ['x.txt'], 'tmp_name' => [''], 'error' => [$error], 'size' => [0],
    ], []));
}
check(CampaignAttachments::withUploads($restored, [
    'name' => [''], 'tmp_name' => [''], 'error' => [UPLOAD_ERR_NO_FILE], 'size' => [0],
], []) === $restored, 'Empty upload input must retain attachments.');

$mail = new \PHPMailer\PHPMailer\PHPMailer(true);
$mail->CharSet = 'UTF-8';
$mail->setFrom('sender@example.test');
$mail->addAddress('recipient@example.test');
$mail->Subject = 'Campaign MIME test';
$mail->isHTML(true);
$mail->Body = '<p>Oferta</p><img src="cid:logo">';
$mail->AltBody = 'Oferta';
$mail->addStringEmbeddedImage('image-content', 'logo', 'logo.png', 'base64', 'image/png');
CampaignAttachments::addToMail($mail, $restored);
check($mail->preSend(), 'MIME preparation failed.');
$mime = $mail->getSentMIMEMessage();
check(substr_count($mime, 'Content-Disposition: attachment;') === 2, 'MIME must contain two attachments.');
check(strpos($mime, 'Content-ID: <logo>') !== false, 'Inline images must remain embedded.');
check(strpos($mime, base64_encode($binary)) !== false, 'Attachment bytes missing from MIME.');
check(strpos($mime, 'multipart/mixed') !== false, 'Attachments require multipart/mixed.');
CampaignAttachments::addToMail($mail, []);
check(count($mail->getAttachments()) === 3, 'Empty attachments must not alter inline images.');
echo "OK: persistence, removal, limits, upload errors, filenames and MIME with inline images.\n";
