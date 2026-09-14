<?php

declare(strict_types=1);

final class InvoiceMailerService
{
    public static function previewContext(array $template, string $email = 'cliente@ejemplo.com'): array
    {
        $web = trim((string) ($template['web'] ?? 'infracomcoopelectric.com.ar'));
        if ($web === '') {
            $web = 'infracomcoopelectric.com.ar';
        }
        $branchId = 0;
        try {
            $branchId = (int) (BranchRepository::currentId() ?? BranchRepository::defaultId() ?? 0);
        } catch (Throwable $e) {
            $branchId = 0;
        }

        return [
            'client_name' => 'Cliente de ejemplo',
            'email' => $email,
            'email_to' => $email,
            'amount' => '12345.67',
            'amount_label' => '$ 12.345,67',
            'snb' => 'OLA00001',
            'due_date_label' => date('d/m/Y'),
            'invoice_id' => 'preview',
            'invoice_url' => 'https://facturas.' . $web . '/?l=demo&f=demo',
            'web' => (string) ($template['web'] ?? ''),
            'domicilio' => (string) ($template['domicilio'] ?? ''),
            'facebook' => (string) ($template['facebook'] ?? ''),
            'instagram' => (string) ($template['instagram'] ?? ''),
            'whatsapp' => (string) ($template['whatsapp'] ?? ''),
            'branch_id' => $branchId,
        ];
    }

    public static function render(string $content, array $invoice): string
    {
        $values = [
            '{{nombre}}' => (string) ($invoice['client_name'] ?? ''),
            '{{email}}' => (string) ($invoice['email'] ?? $invoice['email_to'] ?? ''),
            '{{importe}}' => (string) ($invoice['amount_label'] ?? InvoiceRepository::formatMoney($invoice['amount'] ?? 0)),
            '{{snb}}' => (string) ($invoice['snb'] ?? ''),
            '{{vencimiento}}' => (string) ($invoice['due_date_label'] ?? ''),
            '{{url_factura}}' => (string) ($invoice['invoice_url'] ?? ''),
            '{{web}}' => (string) ($invoice['web'] ?? ''),
            '{{domicilio}}' => (string) ($invoice['domicilio'] ?? ''),
            '{{facebook}}' => (string) ($invoice['facebook'] ?? ''),
            '{{instagram}}' => (string) ($invoice['instagram'] ?? ''),
            '{{whatsapp}}' => (string) ($invoice['whatsapp'] ?? ''),
        ];

        return strtr($content, $values);
    }

    public static function sendTemplatePreview(string $toEmail, array $template, ?int $templateId = null): void
    {
        $toEmail = trim($toEmail);
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Ingresa un email valido para la prueba.');
        }

        $subject = trim((string) ($template['subject'] ?? ''));
        $html = (string) ($template['html_body'] ?? '');
        if ($subject === '' || trim($html) === '') {
            throw new InvalidArgumentException('Asunto y HTML son obligatorios para enviar la prueba.');
        }

        foreach (['from_email', 'reply_to', 'bcc'] as $emailKey) {
            $email = trim((string) ($template[$emailKey] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('El email ' . $email . ' no es valido.');
            }
        }

        $context = self::previewContext($template, $toEmail);
        $branchId = (int) ($context['branch_id'] ?? 0);
        $smtp = Settings::smtp($branchId > 0 ? $branchId : null);
        if (trim((string) ($smtp['host'] ?? '')) === '' || trim((string) ($smtp['from_email'] ?? '')) === '') {
            throw new RuntimeException('La configuracion SMTP de la sucursal esta incompleta.');
        }

        $renderedSubject = self::render(repair_mojibake($subject), $context);
        $renderedHtml = self::render(repair_mojibake($html), $context);
        $started = microtime(true);

        try {
            MailerService::sendCustom($smtp, $toEmail, 'Prueba de plantilla', $renderedSubject, $renderedHtml, [
                'from_email' => trim((string) ($template['from_email'] ?? '')) ?: (string) ($smtp['from_email'] ?? ''),
                'from_name' => trim((string) ($template['from_name'] ?? '')) ?: (string) ($smtp['from_name'] ?? ''),
                'reply_to' => trim((string) ($template['reply_to'] ?? '')) ?: (string) ($smtp['reply_to'] ?? ''),
                'bcc' => trim((string) ($template['bcc'] ?? '')),
            ]);
            InvoiceRepository::insertLog([
                'branch_id' => $branchId,
                'template_id' => $templateId,
                'invoice_id' => 'preview',
                'snb' => (string) $context['snb'],
                'client_name' => 'Prueba de plantilla',
                'email_to' => $toEmail,
                'email_subject' => $renderedSubject,
                'status' => 'sent',
                'smtp_host' => $smtp['host'] ?? null,
                'provider_response' => 'PHPMailer send() OK',
                'html_snapshot' => $renderedHtml,
                'context_json' => json_encode(array_merge($context, ['source' => 'invoice_template_preview']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        } catch (Throwable $e) {
            InvoiceRepository::insertLog([
                'branch_id' => $branchId,
                'template_id' => $templateId,
                'invoice_id' => 'preview',
                'snb' => (string) $context['snb'],
                'client_name' => 'Prueba de plantilla',
                'email_to' => $toEmail,
                'email_subject' => $renderedSubject,
                'status' => 'failed',
                'smtp_host' => $smtp['host'] ?? null,
                'error_message' => $e->getMessage(),
                'html_snapshot' => $renderedHtml,
                'context_json' => json_encode(array_merge($context, ['source' => 'invoice_template_preview']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
            throw $e;
        }
    }

    public static function processPending(int $limit = 10): array
    {
        $items = InvoiceRepository::pending($limit);
        $result = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'errors' => [], 'interval_seconds' => 0];

        $processedInRun = 0;
        foreach ($items as $item) {
            $smtp = Settings::smtp((int) ($item['branch_id'] ?? 0));
            $interval = max(0, min((int) ($smtp['interval_seconds'] ?? 0), 3600));
            if ($processedInRun > 0 && $interval > 0) {
                sleep($interval);
            }

            if (!InvoiceRepository::markSending((int) $item['id'])) {
                continue;
            }

            $result['interval_seconds'] = $interval;
            $processedInRun++;
            $result['processed']++;
            InvoiceRepository::updateInvoiceBatchTotals((int) ($item['batch_id'] ?? 0));
            $started = microtime(true);

            $context = json_decode((string) ($item['context_json'] ?? ''), true);
            if (!is_array($context)) {
                $context = [];
            }
            $context = array_merge($context, [
                'client_name' => $context['client_name'] ?? $item['client_name'] ?? '',
                'email' => $context['email'] ?? $item['email_to'] ?? '',
                'email_to' => $item['email_to'] ?? '',
                'amount' => $context['amount'] ?? $item['amount'] ?? 0,
                'amount_label' => $context['amount_label'] ?? InvoiceRepository::formatMoney($item['amount'] ?? 0),
                'snb' => $context['snb'] ?? $item['snb'] ?? '',
                'due_date_label' => $context['due_date_label'] ?? $item['due_date_label'] ?? '',
                'invoice_url' => $context['invoice_url'] ?? $item['invoice_url'] ?? '',
                'web' => $item['web'] ?? '',
                'domicilio' => $item['domicilio'] ?? '',
                'facebook' => $item['facebook'] ?? '',
                'instagram' => $item['instagram'] ?? '',
                'whatsapp' => $item['whatsapp'] ?? '',
            ]);

            $subject = self::render(repair_mojibake((string) $item['subject']), $context);
            $html = self::render(repair_mojibake((string) $item['html_body']), $context);
            $toEmail = (string) $item['email_to'];
            $toName = (string) $item['client_name'];

            $delivered = false;
            try {
                if (trim((string) ($smtp['host'] ?? '')) === '' || trim((string) ($smtp['from_email'] ?? '')) === '') {
                    throw new RuntimeException('La configuracion SMTP de la sucursal esta incompleta.');
                }

                if ((bool) $item['test_mode']) {
                    $testEmail = trim((string) ($item['test_email'] ?? ''));
                    if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
                        throw new RuntimeException('La plantilla de facturas esta en modo test pero no tiene un email de prueba valido.');
                    }
                    $toEmail = $testEmail;
                    $subject = '[TEST] ' . $subject;
                }

                MailerService::sendCustom($smtp, $toEmail, $toName, $subject, $html, [
                    'from_email' => trim((string) ($item['from_email'] ?? '')) ?: (string) ($smtp['from_email'] ?? ''),
                    'from_name' => trim((string) ($item['from_name'] ?? '')) ?: (string) ($smtp['from_name'] ?? ''),
                    'reply_to' => trim((string) ($item['reply_to'] ?? '')) ?: (string) ($smtp['reply_to'] ?? ''),
                    'bcc' => trim((string) ($item['bcc'] ?? '')),
                ]);

                $delivered = true;
                InvoiceRepository::markSent((int) $item['id'], (string) $item['invoice_id'], (int) ($item['branch_id'] ?? 0), (bool) $item['test_mode']);
                InvoiceRepository::insertLog([
                    'branch_id' => $item['branch_id'] ?? null,
                    'queue_id' => (int) $item['id'],
                    'template_id' => (int) $item['template_id'],
                    'invoice_id' => $item['invoice_id'],
                    'snb' => $item['snb'],
                    'client_name' => $item['client_name'],
                    'email_to' => $toEmail,
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
                if ($delivered) {
                    // Keep sent/sending for review: a retry could duplicate the actual email.
                    InvoiceRepository::markNeedsReview((int) $item['id'], $e->getMessage());
                    $result['errors'][] = 'Envio realizado; revisar su registro: ' . $e->getMessage();
                    InvoiceRepository::updateInvoiceBatchTotals((int) ($item['batch_id'] ?? 0));
                    continue;
                }

                $message = $e->getMessage();
                InvoiceRepository::markFailed((int) $item['id'], $message);
                InvoiceRepository::insertLog([
                    'branch_id' => $item['branch_id'] ?? null,
                    'queue_id' => (int) $item['id'],
                    'template_id' => (int) $item['template_id'],
                    'invoice_id' => $item['invoice_id'],
                    'snb' => $item['snb'],
                    'client_name' => $item['client_name'],
                    'email_to' => $toEmail,
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

            InvoiceRepository::updateInvoiceBatchTotals((int) ($item['batch_id'] ?? 0));
        }

        return $result;
    }
}
