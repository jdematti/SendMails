<?php
declare(strict_types=1);

final class ComposeService
{
    public static function input(array $data): array
    {
        $result = [];
        foreach (['name', 'channel', 'scheduled_at', 'q', 'due_date', 'status', 'snb', 'email', 'phone', 'client_name', 'manual_emails'] as $key) {
            $result[$key] = is_scalar($data[$key] ?? null) ? trim((string) $data[$key]) : '';
        }
        if (!in_array($result['channel'], ['email', 'whatsapp', 'both'], true)) $result['channel'] = 'email';
        foreach (['template_id', 'whatsapp_template_id'] as $key) $result[$key] = max(0, (int) ($data[$key] ?? 0));
        foreach (['ids', 'plans'] as $key) {
            $values = is_array($data[$key] ?? null) ? $data[$key] : [];
            $result[$key] = array_values(array_unique(array_map('strval', array_filter($values, 'is_scalar'))));
        }
        if (count($result['ids']) > 20000 || count($result['plans']) > 100) throw new InvalidArgumentException('La seleccion supera el limite permitido.');
        $result['include_sent'] = !empty($data['include_sent']);
        $result['name'] = mb_substr($result['name'], 0, 150);
        return $result;
    }

    private static function invoiceFilters(array $input): array
    {
        return ['snb' => $input['snb'], 'email' => $input['email'], 'phone' => $input['phone'], 'name' => $input['client_name']];
    }

    public static function search(string $kind, array $input, int $page = 1, bool $idsOnly = false): array
    {
        if ($kind === 'campaign') return ClientRepository::page($input['q'], $input['plans'], $page, $idsOnly);
        if ($input['due_date'] === '') return $idsOnly ? [] : ['rows' => [], 'total' => 0, 'page' => 1, 'size' => 50];
        $filters = self::invoiceFilters($input);
        if ($idsOnly) return InvoiceRepository::matchingIds($input['due_date'], $input['status'], $input['include_sent'], $filters, $input['channel']);
        $template = InvoiceRepository::findTemplate($input['template_id']);
        if (!$template) throw new RuntimeException('Selecciona una plantilla de facturas para preparar el enlace.');
        $rows = InvoiceRepository::search($input['due_date'], $input['status'], $input['include_sent'], $template, $filters, 50, $input['channel'], $page, false);
        $total = InvoiceRepository::searchTotal($input['due_date'], $input['status'], $input['include_sent'], $filters, $input['channel']);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'size' => 50];
    }

    private static function recipients(string $kind, array $input, array $template): array
    {
        if ($kind === 'campaign') {
            $rows = ClientRepository::findByOids($input['ids']);
            if ($input['channel'] !== 'whatsapp') {
                foreach (preg_split('/[\s,;]+/', $input['manual_emails'], -1, PREG_SPLIT_NO_EMPTY) as $email) {
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email manual invalido: ' . $email);
                    $rows[] = ['branch_id' => BranchRepository::currentId(), 'oid' => 'manual:' . hash('sha256', strtolower($email)),
                        'codigo_cliente' => 'Manual', 'razon_social' => 'Email manual', 'email' => $email, 'telefono_movil' => ''];
                }
            }
            return $rows;
        }
        $rows = [];
        foreach (array_chunk($input['ids'], 500) as $ids) {
            $filters = ['ids' => $ids];
            array_push($rows, ...InvoiceRepository::search($input['due_date'], $input['status'], $input['include_sent'], $template, $filters, 0, $input['channel'], 1, false));
        }
        return $rows;
    }

    public static function classify(string $kind, array $rows, string $channel, array $blockedEmails, array $blockedPhones, string $country = '54'): array
    {
        $emailRows = []; $phoneRows = []; $seenEmail = []; $seenPhone = [];
        $counts = ['selected' => count($rows), 'email' => 0, 'whatsapp' => 0,
            'email_invalid' => 0, 'email_excluded' => 0, 'email_duplicates' => 0,
            'phone_invalid' => 0, 'phone_excluded' => 0, 'phone_duplicates' => 0];
        foreach ($rows as $row) {
            if ($channel !== 'whatsapp') {
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                $key = ($kind === 'invoice' ? (string) ($row['invoice_id'] ?? '') . '|' : '') . $email;
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $counts['email_invalid']++;
                elseif ($kind === 'campaign' && !empty($blockedEmails[$email])) $counts['email_excluded']++;
                elseif (isset($seenEmail[$key])) $counts['email_duplicates']++;
                else { $seenEmail[$key] = true; $emailRows[] = $row; $counts['email']++; }
            }
            if ($channel !== 'email') {
                $phone = WhatsAppPhone::normalize((string) ($row['telefono_movil'] ?? ''), $country);
                $key = ($kind === 'invoice' ? (string) ($row['invoice_id'] ?? '') . '|' : '') . $phone;
                if ($phone === null) $counts['phone_invalid']++;
                elseif ($kind === 'campaign' && isset($blockedPhones[$phone])) $counts['phone_excluded']++;
                elseif (isset($seenPhone[$key])) $counts['phone_duplicates']++;
                else { $seenPhone[$key] = true; $phoneRows[] = $row; $counts['whatsapp']++; }
            }
        }
        return ['counts' => $counts, 'email_rows' => $emailRows, 'phone_rows' => $phoneRows];
    }

    public static function review(string $kind, array $input): array
    {
        if ($input['name'] === '') throw new RuntimeException('Ingresa un nombre para identificar el envio.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $input['scheduled_at']);
        if (!$date || $date->format('Y-m-d\TH:i') !== $input['scheduled_at']) throw new RuntimeException('Selecciona una fecha y hora valida.');
        $template = $kind === 'invoice' ? InvoiceRepository::findTemplate($input['template_id']) : TemplateRepository::find($input['template_id']);
        if (($kind === 'invoice' || $input['channel'] !== 'whatsapp') && (!$template || empty($template['is_active']))) {
            throw new RuntimeException('Selecciona una plantilla activa.');
        }
        if ($input['channel'] !== 'whatsapp') {
            $smtp = Settings::smtp();
            if (trim((string) ($smtp['host'] ?? '')) === '' || trim((string) ($smtp['from_email'] ?? '')) === '') {
                throw new RuntimeException('Completa la configuracion SMTP de esta sucursal antes de confirmar.');
            }
            if ($kind === 'invoice' && !empty($template['test_mode']) && !filter_var($template['test_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('La plantilla esta en modo prueba y necesita un email de prueba valido.');
            }
        }
        $wa = null; $config = []; $blockedPhones = [];
        if ($input['channel'] !== 'email') {
            $wa = WhatsAppRepository::findTemplate($input['whatsapp_template_id']);
            $category = $kind === 'invoice' ? 'UTILITY' : 'MARKETING';
            if (!$wa || !WhatsAppRepository::isTemplateReady($wa) || $wa['category'] !== $category) throw new RuntimeException('Selecciona una plantilla WhatsApp aprobada y configurada.');
            $config = Settings::whatsapp();
            WhatsAppBusinessService::assertConfigured($config);
            if ($kind === 'campaign') $blockedPhones = WhatsAppRepository::optedOutPhones((int) BranchRepository::currentId());
        }
        $rows = self::recipients($kind, $input, $template ?? []);
        if (!$rows) throw new RuntimeException('No hay destinatarios disponibles para esa seleccion.');
        $blockedEmails = [];
        if ($kind === 'campaign' && $input['channel'] !== 'whatsapp') {
            $emails = array_column($rows, 'email');
            $blockedEmails = UnsubscribeRepository::statusByEmail($emails);
            foreach (EmailExclusionRepository::statusByEmail($emails) as $email => $blocked) {
                $blockedEmails[$email] = $blocked || ($blockedEmails[$email] ?? false);
            }
        }
        $result = self::classify($kind, $rows, $input['channel'], $blockedEmails, $blockedPhones, (string) ($config['country_code'] ?? '54'));
        if (($input['channel'] !== 'whatsapp' && !$result['email_rows']) || ($input['channel'] !== 'email' && !$result['phone_rows'])) {
            throw new RuntimeException('No hay destinatarios habilitados para uno de los canales elegidos. Ajusta la seleccion o el canal.');
        }
        $example = $result['email_rows'][0] ?? $rows[0];
        $context = $kind === 'invoice' ? array_merge($template ?? [], $example) : $example;
        $render = $kind === 'invoice' ? [InvoiceMailerService::class, 'render'] : [MailerService::class, 'render'];
        $attachments = CampaignAttachments::fromJson($template['attachments_json'] ?? null);
        $result['preview'] = ['subject' => $render((string) ($template['subject'] ?? ''), $context),
            'html' => $render((string) ($template['html_body'] ?? ''), $context),
            'example' => (string) ($example['email'] ?? ''),
            'test' => $kind === 'invoice' && !empty($template['test_mode']) && $input['channel'] !== 'whatsapp',
            'test_email' => (string) ($template['test_email'] ?? ''),
            'attachments' => array_map(static fn(array $file): array => array_intersect_key($file, array_flip(['name', 'size', 'mime'])), $attachments),
            'whatsapp' => $wa ? ['name' => $wa['name'], 'components' => json_decode((string) $wa['components_json'], true)] : null];
        $result['snapshot'] = $template ? MessageSnapshot::capture($template) : null;
        $result['wa_snapshot'] = $wa ? MessageSnapshot::capture($wa, true) : null;
        $result['scheduled_at'] = $date->format('Y-m-d H:i:00');
        return $result;
    }

    public static function confirm(string $kind, int $id, int $revision): array
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $draft = DraftRepository::find($id, $kind, true);
            if ($draft['state'] === 'sent') { $pdo->commit(); return $draft['payload']['result']; }
            if ((int) $draft['revision'] !== $revision || $draft['state'] !== 'review') {
                throw new RuntimeException('El borrador cambio. Revisalo nuevamente antes de confirmar.');
            }
            $input = $draft['payload']['input']; $review = $draft['payload']['review'];
            $links = [];
            if ($input['channel'] !== 'whatsapp') {
                if ($kind === 'campaign') {
                    $batch = QueueRepository::createCampaign($input['template_id'], $input['name'], 'individual', $review['email_rows'], $review['scheduled_at'], $review['snapshot']);
                } else {
                    $batch = null;
                    InvoiceRepository::createQueue($input['template_id'], $review['email_rows'], $input['name'], $review['scheduled_at'], $review['snapshot'], $batch);
                }
                $links[] = ['kind' => $kind, 'channel' => 'email', 'id' => $batch];
            }
            if ($input['channel'] !== 'email') {
                $batch = WhatsAppRepository::createBatch($kind, $input['whatsapp_template_id'], $input['name'], $review['phone_rows'], $review['scheduled_at'], $review['wa_snapshot']);
                $links[] = ['kind' => $kind, 'channel' => 'whatsapp', 'id' => (int) $batch['batch_id']];
            }
            $result = ['links' => $links, 'url' => 'activity.php?kind=' . $kind . '&channel=' . $links[0]['channel'] . '&batch_id=' . $links[0]['id']];
            DraftRepository::save($id, $kind, $input['name'], ['input' => $input, 'result' => $result], $revision, 'sent');
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
