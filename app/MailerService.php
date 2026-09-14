<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

final class MailerService
{
    public static function render(string $content, array $client): string
    {
        $unsubscribeUrl = (string) ($client['url_baja'] ?? $client['unsubscribe_url'] ?? '');
        $values = [
            '{{codigo_cliente}}' => (string) ($client['codigo_cliente'] ?? $client['client_code'] ?? ''),
            '{{razon_social}}' => (string) ($client['razon_social'] ?? $client['client_name'] ?? ''),
            '{{plan}}' => (string) ($client['plan_contratado'] ?? ''),
            '{{email}}' => (string) ($client['email'] ?? $client['email_to'] ?? ''),
            '{{telefono_movil}}' => (string) ($client['telefono_movil'] ?? ''),
            '{{localidad}}' => (string) ($client['localidad'] ?? ''),
            '{{provincia}}' => (string) ($client['provincia'] ?? ''),
            '{{url_baja}}' => $unsubscribeUrl,
            '{{unsubscribe_url}}' => $unsubscribeUrl,
        ];

        return strtr($content, $values);
    }

    public static function renderCampaignHtml(string $content, array $client): string
    {
        $unsubscribeUrl = (string) ($client['url_baja'] ?? $client['unsubscribe_url'] ?? '');
        $hasPlaceholder = self::hasUnsubscribePlaceholder($content);
        $html = self::render($content, $client);

        if ($unsubscribeUrl === '' || $hasPlaceholder) {
            return $html;
        }

        return self::appendUnsubscribeFooter($html, $unsubscribeUrl);
    }

    public static function processPending(int $limit = 10): array
    {
        $items = QueueRepository::pending($limit);
        $result = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => [], 'interval_seconds' => 0];

        $processedInRun = 0;
        foreach ($items as $item) {
            $smtp = Settings::smtp((int) ($item['branch_id'] ?? 0));
            $interval = max(0, min((int) ($smtp['interval_seconds'] ?? 0), 3600));
            if ($processedInRun > 0 && $interval > 0) {
                sleep($interval);
            }

            if (!QueueRepository::markSending((int) $item['id'])) {
                continue;
            }

            $result['interval_seconds'] = $interval;
            $processedInRun++;
            $result['processed']++;
            $started = microtime(true);

            $context = json_decode((string) ($item['context_json'] ?? ''), true);
            if (!is_array($context)) {
                $context = [];
            }
            $context = array_merge($context, [
                'codigo_cliente' => $context['codigo_cliente'] ?? $item['client_code'] ?? '',
                'razon_social' => $context['razon_social'] ?? $item['client_name'] ?? '',
                'email' => $context['email'] ?? $item['email_to'] ?? '',
                'client_oid' => $item['client_oid'] ?? '',
                'client_code' => $item['client_code'] ?? '',
                'client_name' => $item['client_name'] ?? '',
                'branch_id' => (int) ($item['branch_id'] ?? 0),
            ]);
            $context['unsubscribe_url'] = UnsubscribeRepository::urlFor($context, 'campaign');
            $context['url_baja'] = $context['unsubscribe_url'];
            $subject = self::render(repair_mojibake((string) $item['subject']), $context);
            $html = self::renderCampaignHtml(repair_mojibake((string) $item['html_body']), $context);

            if (UnsubscribeRepository::isUnsubscribed((string) $item['email_to'], (int) ($item['branch_id'] ?? 0))) {
                $message = 'Destinatario dado de baja.';
                QueueRepository::markSkipped((int) $item['id'], $message);
                QueueRepository::insertLog([
                    'branch_id' => $item['branch_id'] ?? null,
                    'queue_id' => (int) $item['id'],
                    'campaign_id' => (int) $item['campaign_id'],
                    'template_id' => (int) $item['template_id'],
                    'client_oid' => $item['client_oid'],
                    'client_code' => $item['client_code'],
                    'client_name' => $item['client_name'],
                    'email_to' => $item['email_to'],
                    'email_subject' => $subject,
                    'status' => 'skipped',
                    'smtp_host' => $smtp['host'],
                    'error_message' => $message,
                    'context_json' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                ]);
                QueueRepository::updateCampaignTotals((int) $item['campaign_id']);
                $result['skipped']++;
                continue;
            }

            if (EmailExclusionRepository::isExcluded((string) $item['email_to'])) {
                $message = 'Destinatario excluido de envios.';
                QueueRepository::markSkipped((int) $item['id'], $message);
                QueueRepository::insertLog([
                    'branch_id' => $item['branch_id'] ?? null,
                    'queue_id' => (int) $item['id'],
                    'campaign_id' => (int) $item['campaign_id'],
                    'template_id' => (int) $item['template_id'],
                    'client_oid' => $item['client_oid'],
                    'client_code' => $item['client_code'],
                    'client_name' => $item['client_name'],
                    'email_to' => $item['email_to'],
                    'email_subject' => $subject,
                    'status' => 'skipped',
                    'smtp_host' => $smtp['host'],
                    'error_message' => $message,
                    'context_json' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                ]);
                QueueRepository::updateCampaignTotals((int) $item['campaign_id']);
                $result['skipped']++;
                continue;
            }

            try {
                if (trim((string) $smtp['host']) === '' || trim((string) $smtp['from_email']) === '') {
                    throw new RuntimeException('La configuracion SMTP de la sucursal esta incompleta.');
                }

                self::sendMail($smtp, (string) $item['email_to'], (string) $item['client_name'], $subject, $html, [
                    'unsubscribe_url' => (string) $context['unsubscribe_url'],
                    'attachments' => CampaignAttachments::fromJson($item['attachments_json'] ?? null),
                ]);
                QueueRepository::markSent((int) $item['id']);
                QueueRepository::insertLog([
                    'branch_id' => $item['branch_id'] ?? null,
                    'queue_id' => (int) $item['id'],
                    'campaign_id' => (int) $item['campaign_id'],
                    'template_id' => (int) $item['template_id'],
                    'client_oid' => $item['client_oid'],
                    'client_code' => $item['client_code'],
                    'client_name' => $item['client_name'],
                    'email_to' => $item['email_to'],
                    'email_subject' => $subject,
                    'status' => 'sent',
                    'smtp_host' => $smtp['host'],
                    'provider_response' => 'PHPMailer send() OK',
                    'html_snapshot' => $html,
                    'context_json' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                ]);
                $result['sent']++;
            } catch (Throwable $e) {
                $message = $e->getMessage();
                QueueRepository::markFailed((int) $item['id'], $message);
                QueueRepository::insertLog([
                    'branch_id' => $item['branch_id'] ?? null,
                    'queue_id' => (int) $item['id'],
                    'campaign_id' => (int) $item['campaign_id'],
                    'template_id' => (int) $item['template_id'],
                    'client_oid' => $item['client_oid'],
                    'client_code' => $item['client_code'],
                    'client_name' => $item['client_name'],
                    'email_to' => $item['email_to'],
                    'email_subject' => $subject,
                    'status' => 'failed',
                    'smtp_host' => $smtp['host'],
                    'error_message' => $message,
                    'html_snapshot' => $html,
                    'context_json' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                ]);
                $result['failed']++;
                $result['errors'][] = $message;
            }

            QueueRepository::updateCampaignTotals((int) $item['campaign_id']);
        }

        return $result;
    }

    public static function sendTest(array $smtp, string $toEmail): void
    {
        $toEmail = trim($toEmail);
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Ingresa un email valido para la prueba.');
        }

        if (trim((string) ($smtp['host'] ?? '')) === '' || trim((string) ($smtp['from_email'] ?? '')) === '') {
            throw new RuntimeException('La configuracion SMTP esta incompleta.');
        }

        $subject = 'Prueba de configuracion SMTP - SendMails';
        $html = '<p>Este es un correo de prueba enviado desde SendMails.</p>'
            . '<p>Fecha y hora del servidor: <strong>' . e(date('d/m/Y H:i:s P')) . '</strong></p>';

        $started = microtime(true);
        try {
            self::sendMail($smtp, $toEmail, 'Prueba SMTP', $subject, $html);
            QueueRepository::insertLog([
                'client_code' => 'test',
                'client_name' => 'Prueba SMTP',
                'email_to' => $toEmail,
                'email_subject' => $subject,
                'status' => 'sent',
                'smtp_host' => $smtp['host'] ?? null,
                'provider_response' => 'PHPMailer send() OK',
                'html_snapshot' => $html,
                'context_json' => json_encode(['source' => 'smtp_test'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        } catch (Throwable $e) {
            QueueRepository::insertLog([
                'client_code' => 'test',
                'client_name' => 'Prueba SMTP',
                'email_to' => $toEmail,
                'email_subject' => $subject,
                'status' => 'failed',
                'smtp_host' => $smtp['host'] ?? null,
                'error_message' => $e->getMessage(),
                'html_snapshot' => $html,
                'context_json' => json_encode(['source' => 'smtp_test'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
            throw $e;
        }
    }

    public static function sendTemplatePreview(string $toEmail, string $subject, string $html, ?int $templateId = null, array $attachments = []): void
    {
        $toEmail = trim($toEmail);
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Ingresa un email valido para la prueba.');
        }

        if (trim($subject) === '' || trim($html) === '') {
            throw new InvalidArgumentException('Asunto y HTML son obligatorios para enviar la prueba.');
        }

        $smtp = Settings::smtp();
        if (trim((string) ($smtp['host'] ?? '')) === '' || trim((string) ($smtp['from_email'] ?? '')) === '') {
            throw new RuntimeException('La configuracion SMTP esta incompleta.');
        }

        $context = [
            'codigo_cliente' => '0001',
            'razon_social' => 'Cliente de ejemplo',
            'plan_contratado' => 'Plan Premium',
            'email' => $toEmail,
            'telefono_movil' => '2284 000000',
            'localidad' => 'OLAVARRIA',
            'provincia' => 'BUENOS AIRES',
            'branch_id' => (int) (BranchRepository::currentId() ?? BranchRepository::defaultId() ?? 0),
        ];
        $context['unsubscribe_url'] = UnsubscribeRepository::urlFor($context, 'preview');
        $context['url_baja'] = $context['unsubscribe_url'];

        $renderedSubject = self::render(repair_mojibake($subject), $context);
        $renderedHtml = self::renderCampaignHtml(repair_mojibake($html), $context);
        $started = microtime(true);

        try {
            self::sendMail(
                $smtp,
                $toEmail,
                'Prueba de plantilla',
                $renderedSubject,
                $renderedHtml,
                ['unsubscribe_url' => (string) $context['unsubscribe_url'], 'attachments' => $attachments]
            );
            QueueRepository::insertLog([
                'template_id' => $templateId,
                'client_code' => 'test',
                'client_name' => 'Prueba de plantilla',
                'email_to' => $toEmail,
                'email_subject' => $renderedSubject,
                'status' => 'sent',
                'smtp_host' => $smtp['host'] ?? null,
                'provider_response' => 'PHPMailer send() OK',
                'html_snapshot' => $renderedHtml,
                'context_json' => json_encode(array_merge($context, ['source' => 'template_preview']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        } catch (Throwable $e) {
            QueueRepository::insertLog([
                'template_id' => $templateId,
                'client_code' => 'test',
                'client_name' => 'Prueba de plantilla',
                'email_to' => $toEmail,
                'email_subject' => $renderedSubject,
                'status' => 'failed',
                'smtp_host' => $smtp['host'] ?? null,
                'error_message' => $e->getMessage(),
                'html_snapshot' => $renderedHtml,
                'context_json' => json_encode(array_merge($context, ['source' => 'template_preview']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
            throw $e;
        }
    }

    public static function sendPasswordReset(string $toEmail, string $toName, string $resetUrl): void
    {
        $smtp = Settings::smtp();
        if (trim((string) ($smtp['host'] ?? '')) === '' || trim((string) ($smtp['from_email'] ?? '')) === '') {
            throw new RuntimeException('La configuracion SMTP esta incompleta.');
        }

        $subject = 'Recuperacion de contraseña - ' . APP_NAME;
        $html = '<p>Hola ' . e($toName) . ',</p>'
            . '<p>Recibimos una solicitud para recuperar tu contraseña de ' . e(APP_NAME) . '.</p>'
            . '<p>El link vence en ' . AUTH_PASSWORD_RESET_MINUTES . ' minutos.</p>'
            . '<p><a href="' . e($resetUrl) . '" target="_blank" style="display:inline-block;background:#14b8a6;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:8px;font-weight:bold;">Cambiar contraseña</a></p>'
            . '<p>Si no solicitaste este cambio, ignora este correo.</p>';

        self::sendMail($smtp, $toEmail, $toName, $subject, $html);
    }

    public static function sendCustom(array $smtp, string $toEmail, string $toName, string $subject, string $html, array $options = []): void
    {
        self::sendMail($smtp, $toEmail, $toName, $subject, $html, $options);
    }

    private static function sendMail(array $smtp, string $toEmail, string $toName, string $subject, string $html, array $options = []): void
    {
        $mail = new PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        $mail->isSMTP();
        $mail->Host = (string) $smtp['host'];
        $mail->Port = (int) $smtp['port'];
        $mail->SMTPAuth = trim((string) $smtp['username']) !== '';
        if ($mail->SMTPAuth) {
            $mail->Username = (string) $smtp['username'];
            $mail->Password = (string) $smtp['password'];
        }

        if (($smtp['encryption'] ?? 'tls') === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif (($smtp['encryption'] ?? '') === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }

        $fromEmail = trim((string) ($options['from_email'] ?? $smtp['from_email'] ?? ''));
        $fromName = trim((string) ($options['from_name'] ?? $smtp['from_name'] ?? ''));
        $replyTo = trim((string) ($options['reply_to'] ?? $smtp['reply_to'] ?? ''));
        $bcc = trim((string) ($options['bcc'] ?? ''));

        $mail->setFrom($fromEmail, $fromName);
        if ($replyTo !== '') {
            $mail->addReplyTo($replyTo);
        }
        if ($bcc !== '') {
            $mail->addBCC($bcc);
        }
        $mail->addAddress($toEmail, $toName);
        $unsubscribeUrl = trim((string) ($options['unsubscribe_url'] ?? ''));
        if ($unsubscribeUrl !== '' && strpos($unsubscribeUrl, "\n") === false && strpos($unsubscribeUrl, "\r") === false) {
            $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
        }
        $mail->isHTML(true);
        $preparedHtml = self::prepareInlineImages($mail, $html);
        $mail->Subject = $subject;
        $mail->Body = $preparedHtml;
        $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)));
        CampaignAttachments::addToMail($mail, $options['attachments'] ?? []);
        $mail->send();
    }

    private static function hasUnsubscribePlaceholder(string $html): bool
    {
        return strpos($html, '{{url_baja}}') !== false || strpos($html, '{{unsubscribe_url}}') !== false;
    }

    private static function appendUnsubscribeFooter(string $html, string $unsubscribeUrl): string
    {
        $footer = '<div style="margin:24px auto 0;padding:16px 12px;text-align:center;font-family:Arial,sans-serif;font-size:12px;line-height:1.5;color:#64748b;">'
            . 'Si no queres recibir mas estos correos, '
            . '<a href="' . e($unsubscribeUrl) . '" target="_blank" style="color:#0f766e;text-decoration:underline;">cancela tu suscripcion</a>.'
            . '</div>';

        if (stripos($html, '</body>') !== false) {
            return preg_replace('~</body>~i', $footer . '</body>', $html, 1) ?? ($html . $footer);
        }

        return $html . $footer;
    }

    private static function prepareInlineImages(PHPMailer $mail, string $html): string
    {
        $embedded = [];
        $prepared = preg_replace_callback(
            '~(\b(?:src|background)\s*=\s*)(["\'])data:(image/(?:png|jpe?g|gif|webp));base64,([^"\']+)\2~i',
            static function (array $matches) use ($mail, &$embedded): string {
                $base64 = preg_replace('/\s+/', '', $matches[4]);
                $binary = base64_decode($base64, true);
                if ($binary === false || $binary === '') {
                    return $matches[0];
                }

                $mime = self::detectImageMime($binary, $matches[3]);
                $cid = 'img_' . substr(hash('sha256', $binary), 0, 24);
                if (!isset($embedded[$cid])) {
                    $embedded[$cid] = true;
                    $mail->addStringEmbeddedImage(
                        $binary,
                        $cid,
                        $cid . self::extensionForMime($mime),
                        PHPMailer::ENCODING_BASE64,
                        $mime
                    );
                }

                return $matches[1] . $matches[2] . 'cid:' . $cid . $matches[2];
            },
            $html
        );

        return is_string($prepared) ? $prepared : $html;
    }

    private static function detectImageMime(string $binary, string $fallback): string
    {
        $mime = strtolower($fallback);
        if ($mime === 'image/jpg') {
            $mime = 'image/jpeg';
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_buffer($finfo, $binary);
                finfo_close($finfo);
                if (is_string($detected) && strncmp($detected, 'image/', 6) === 0) {
                    $mime = strtolower($detected);
                }
            }
        }

        return $mime === 'image/jpg' ? 'image/jpeg' : $mime;
    }

    private static function extensionForMime(string $mime): string
    {
        switch (strtolower($mime)) {
            case 'image/jpeg':
            case 'image/jpg':
                return '.jpg';
            case 'image/png':
                return '.png';
            case 'image/gif':
                return '.gif';
            case 'image/webp':
                return '.webp';
            default:
                return '.img';
        }
    }
}
