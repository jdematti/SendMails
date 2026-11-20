<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
class ReuseTestMailer extends PHPMailer\PHPMailer\PHPMailer {
    public static array $messages = [];
    public static int $created = 0;
    public static int $closed = 0;
    public static bool $fail = false;
    public function __construct($exceptions = null) { parent::__construct($exceptions); self::$created++; }
    public function smtpClose() { self::$closed++; }
    public function send() {
        if (self::$fail) throw new RuntimeException('Simulated failure');
        $this->preSend();
        self::$messages[] = ['to'=>$this->getToAddresses(), 'bcc'=>$this->getBccAddresses(),
            'reply'=>$this->getReplyToAddresses(), 'headers'=>$this->getCustomHeaders(),
            'attachments'=>$this->getAttachments(), 'sender'=>$this->Sender, 'keep'=>$this->SMTPKeepAlive];
        return true;
    }
}
class CampaignAttachments { public static function addToMail($mail, $attachments): void {} }
$source = file_get_contents(__DIR__ . '/../app/MailerService.php');
$source = str_replace('new PHPMailer(true)', 'new \\ReuseTestMailer(true)', $source, $count);
if ($count !== 1) throw new RuntimeException('Constructor changed');
eval(preg_replace('/^<\?php\s*/', '', $source));
function verify(bool $ok): void { if (!$ok) throw new RuntimeException('Assertion failed'); }
$smtp = ['host'=>'smtp.example.invalid', 'port'=>587, 'username'=>'', 'password'=>'', 'encryption'=>'tls', 'from_email'=>'first@example.invalid'];
MailerService::sendCustom($smtp, 'one@example.invalid', 'One', 'First', '<p>First</p>', ['bcc'=>'hidden@example.invalid', 'reply_to'=>'reply@example.invalid', 'unsubscribe_url'=>'https://example.invalid/unsubscribe']);
// Seed an attachment to verify cleanup without requiring application storage.
$property = new ReflectionProperty(MailerService::class, 'smtpMailer');
$property->getValue()->addStringAttachment('secret', 'first.txt');
MailerService::sendCustom($smtp, 'two@example.invalid', 'Two', 'Second', '<p>Second</p>', ['from_email'=>'second@example.invalid']);
verify(ReuseTestMailer::$created === 1);
$second = ReuseTestMailer::$messages[1];
verify($second['to'] === [['two@example.invalid', 'Two']] && !$second['bcc'] && !$second['reply'] && !$second['headers'] && !$second['attachments']);
verify($second['sender'] === 'second@example.invalid' && $second['keep']);
$smtp['password'] = 'changed';
MailerService::sendCustom($smtp, 'three@example.invalid', '', 'Third', 'Third');
verify(ReuseTestMailer::$created === 2);
$age = new ReflectionProperty(MailerService::class, 'smtpOpenedAt');
$age->setValue(null, microtime(true) - 541);
MailerService::sendCustom($smtp, 'four@example.invalid', '', 'Fourth', 'Fourth');
verify(ReuseTestMailer::$created === 3);
ReuseTestMailer::$fail = true;
try { MailerService::sendCustom($smtp, 'fail@example.invalid', '', 'Failure', 'Failure'); throw new LogicException('Expected failure'); }
catch (RuntimeException $e) { verify($e->getMessage() === 'Simulated failure'); }
verify($property->getValue() === null && count(ReuseTestMailer::$messages) === 4);
ReuseTestMailer::$fail = false;
MailerService::sendCustom($smtp, 'five@example.invalid', '', 'Fifth', 'Fifth');
verify(ReuseTestMailer::$created === 4);
echo "OK: reuse, message isolation, configuration changes, expiry, failure without retry, recovery. No SMTP network calls.\n";
