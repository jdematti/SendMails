<?php

declare(strict_types=1);

final class UnifiedQueueService
{
    public const TYPE_ALL = '';
    public const TYPE_CAMPAIGNS = 'campaigns';
    public const TYPE_INVOICES = 'invoices';
    public const CHANNEL_ALL = 'all';
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_WHATSAPP = 'whatsapp';

    public static function normalizeType(string $type): string
    {
        return in_array($type, [self::TYPE_CAMPAIGNS, self::TYPE_INVOICES], true) ? $type : self::TYPE_ALL;
    }

    public static function normalizeChannel(string $channel): string
    {
        return in_array($channel, [self::CHANNEL_EMAIL, self::CHANNEL_WHATSAPP], true) ? $channel : self::CHANNEL_ALL;
    }

    public static function pendingCounts(string $channel = self::CHANNEL_ALL): array
    {
        $channel = self::normalizeChannel($channel);
        $campaigns = 0;
        $invoices = 0;
        if ($channel !== self::CHANNEL_WHATSAPP) {
            $campaigns += QueueRepository::pendingCount();
            $invoices += InvoiceRepository::pendingCount();
        }
        if ($channel !== self::CHANNEL_EMAIL) {
            $campaigns += WhatsAppRepository::pendingCount(WhatsAppRepository::SOURCE_CAMPAIGN);
            $invoices += WhatsAppRepository::pendingCount(WhatsAppRepository::SOURCE_INVOICE);
        }
        return [self::TYPE_CAMPAIGNS => $campaigns, self::TYPE_INVOICES => $invoices];
    }

    public static function pendingCountsByChannel(string $type): array
    {
        return [
            self::CHANNEL_EMAIL => self::pendingCount($type, self::CHANNEL_EMAIL),
            self::CHANNEL_WHATSAPP => self::pendingCount($type, self::CHANNEL_WHATSAPP),
            self::CHANNEL_ALL => self::pendingCount($type, self::CHANNEL_ALL),
        ];
    }

    public static function pendingCount(string $type = self::TYPE_ALL, string $channel = self::CHANNEL_ALL): int
    {
        $type = self::normalizeType($type);
        $counts = self::pendingCounts($channel);
        if ($type === self::TYPE_CAMPAIGNS) {
            return $counts[self::TYPE_CAMPAIGNS];
        }
        if ($type === self::TYPE_INVOICES) {
            return $counts[self::TYPE_INVOICES];
        }
        return $counts[self::TYPE_CAMPAIGNS] + $counts[self::TYPE_INVOICES];
    }

    public static function queueItems(string $type = self::TYPE_ALL, string $status = '', int $limit = 150, string $channel = self::CHANNEL_ALL): array
    {
        $type = self::normalizeType($type);
        $channel = self::normalizeChannel($channel);
        $limit = max(1, min($limit, 500));
        $items = [];

        if ($channel !== self::CHANNEL_WHATSAPP) {
            if ($type !== self::TYPE_INVOICES) {
                foreach (QueueRepository::queueItems($status, $limit) as $item) {
                    $items[] = self::normalizeCampaignEmail($item);
                }
            }
            if ($type !== self::TYPE_CAMPAIGNS) {
                foreach (InvoiceRepository::queueItems($status, $limit) as $item) {
                    $items[] = self::normalizeInvoiceEmail($item);
                }
            }
        }

        if ($channel !== self::CHANNEL_EMAIL) {
            if ($type !== self::TYPE_INVOICES) {
                foreach (WhatsAppRepository::queueItems(WhatsAppRepository::SOURCE_CAMPAIGN, $status, $limit) as $item) {
                    $items[] = self::normalizeWhatsApp($item, self::TYPE_CAMPAIGNS);
                }
            }
            if ($type !== self::TYPE_CAMPAIGNS) {
                foreach (WhatsAppRepository::queueItems(WhatsAppRepository::SOURCE_INVOICE, $status, $limit) as $item) {
                    $items[] = self::normalizeWhatsApp($item, self::TYPE_INVOICES);
                }
            }
        }

        usort($items, static function (array $a, array $b): int {
            $timeA = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
            $timeB = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
            return $timeA === $timeB ? ((int) $b['raw_id'] <=> (int) $a['raw_id']) : ($timeB <=> $timeA);
        });
        return array_slice($items, 0, $limit);
    }

    public static function processPending(string $type, int $limit, string $channel = self::CHANNEL_ALL): array
    {
        $limit = max(1, min($limit, 10000));
        $summary = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []];
        for ($i = 0; $i < $limit; $i++) {
            $result = self::processStep($type, $channel);
            if ((int) $result['processed'] === 0) {
                break;
            }
            foreach (['processed', 'sent', 'failed', 'skipped'] as $key) {
                $summary[$key] += (int) ($result[$key] ?? 0);
            }
            $summary['errors'] = array_merge($summary['errors'], $result['errors']);
            $interval = max(0, min((int) ($result['interval_seconds'] ?? 0), 3600));
            if ($interval > 0 && $i + 1 < $limit && (int) ($result['pending'] ?? 0) > 0) {
                sleep($interval);
            }
        }
        return $summary;
    }

    public static function processStep(string $type, string $channel = self::CHANNEL_ALL): array
    {
        $type = self::normalizeType($type);
        $channel = self::normalizeChannel($channel);
        $target = self::nextPendingTarget($type, $channel);
        if ($target === null) {
            return [
                'ok' => true, 'processed' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0,
                'errors' => [], 'pending' => self::pendingCount($type, $channel), 'type' => null,
                'channel' => null, 'interval_seconds' => 0,
            ];
        }

        if ($target['channel'] === self::CHANNEL_WHATSAPP) {
            $source = $target['type'] === self::TYPE_INVOICES ? WhatsAppRepository::SOURCE_INVOICE : WhatsAppRepository::SOURCE_CAMPAIGN;
            $result = WhatsAppMailerService::processPending($source, 1);
        } elseif ($target['type'] === self::TYPE_INVOICES) {
            $result = InvoiceMailerService::processPending(1);
        } else {
            $result = MailerService::processPending(1);
        }

        return [
            'ok' => true,
            'processed' => (int) $result['processed'],
            'sent' => (int) $result['sent'],
            'failed' => (int) $result['failed'],
            'skipped' => (int) ($result['skipped'] ?? 0),
            'errors' => $result['errors'],
            'pending' => self::pendingCount($type, $channel),
            'type' => $target['type'],
            'channel' => $target['channel'],
            'interval_seconds' => max(0, min((int) ($result['interval_seconds'] ?? 0), 3600)),
        ];
    }

    private static function nextPendingTarget(string $type, string $channel): ?array
    {
        $targets = [];
        $add = static function (array &$targets, string $typeValue, string $channelValue, array $rows): void {
            if (!$rows) {
                return;
            }
            $targets[] = [
                'type' => $typeValue,
                'channel' => $channelValue,
                'at' => strtotime((string) ($rows[0]['scheduled_at'] ?? '')) ?: 0,
            ];
        };

        if ($channel !== self::CHANNEL_WHATSAPP) {
            if ($type !== self::TYPE_INVOICES) {
                $add($targets, self::TYPE_CAMPAIGNS, self::CHANNEL_EMAIL, QueueRepository::pending(1));
            }
            if ($type !== self::TYPE_CAMPAIGNS) {
                $add($targets, self::TYPE_INVOICES, self::CHANNEL_EMAIL, InvoiceRepository::pending(1));
            }
        }
        if ($channel !== self::CHANNEL_EMAIL) {
            if ($type !== self::TYPE_INVOICES) {
                $add($targets, self::TYPE_CAMPAIGNS, self::CHANNEL_WHATSAPP, WhatsAppRepository::pending(WhatsAppRepository::SOURCE_CAMPAIGN, 1));
            }
            if ($type !== self::TYPE_CAMPAIGNS) {
                $add($targets, self::TYPE_INVOICES, self::CHANNEL_WHATSAPP, WhatsAppRepository::pending(WhatsAppRepository::SOURCE_INVOICE, 1));
            }
        }
        if (!$targets) {
            return null;
        }
        usort($targets, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
        return $targets[0];
    }

    private static function normalizeCampaignEmail(array $item): array
    {
        return [
            'type' => self::TYPE_CAMPAIGNS, 'type_label' => 'Campaña', 'channel' => self::CHANNEL_EMAIL,
            'channel_label' => 'Email', 'display_id' => 'C-' . (string) $item['id'], 'raw_id' => (int) $item['id'],
            'created_at' => (string) $item['created_at'], 'scheduled_at' => (string) $item['scheduled_at'],
            'client_name' => (string) $item['client_name'], 'client_meta' => array_filter([(string) $item['client_code']]),
            'recipient' => (string) $item['email_to'], 'email_to' => (string) $item['email_to'],
            'provider_message_id' => '', 'template_label' => (string) $item['template_name'],
            'status' => (string) $item['status'], 'last_error' => (string) ($item['last_error'] ?? ''),
        ];
    }

    private static function normalizeInvoiceEmail(array $item): array
    {
        $invoiceMeta = 'Factura ' . (string) $item['invoice_id'];
        if (trim((string) ($item['due_date_label'] ?? '')) !== '') {
            $invoiceMeta .= ' · Vto ' . (string) $item['due_date_label'];
        }
        return [
            'type' => self::TYPE_INVOICES, 'type_label' => 'Factura', 'channel' => self::CHANNEL_EMAIL,
            'channel_label' => 'Email', 'display_id' => 'F-' . (string) $item['id'], 'raw_id' => (int) $item['id'],
            'created_at' => (string) $item['created_at'], 'scheduled_at' => (string) $item['scheduled_at'],
            'client_name' => (string) $item['client_name'],
            'client_meta' => [$invoiceMeta, 'SNB ' . (string) $item['snb'] . ' · Importe ' . InvoiceRepository::formatMoney($item['amount'])],
            'recipient' => (string) $item['email_to'], 'email_to' => (string) $item['email_to'], 'provider_message_id' => '',
            'template_label' => trim((string) ($item['batch_name'] ?? '')) !== '' ? (string) $item['batch_name'] : 'Facturas',
            'status' => (string) $item['status'], 'last_error' => (string) ($item['last_error'] ?? ''),
        ];
    }

    private static function normalizeWhatsApp(array $item, string $type): array
    {
        $meta = array_filter([(string) ($item['client_code'] ?? '')]);
        if ($type === self::TYPE_INVOICES) {
            $context = json_decode((string) ($item['context_json'] ?? '{}'), true);
            $context = is_array($context) ? $context : [];
            $meta = array_filter([
                'Factura ' . (string) ($item['source_record_id'] ?? ''),
                trim('SNB ' . (string) ($context['snb'] ?? '') . ' · Importe ' . (string) ($context['amount_label'] ?? '')),
            ]);
        }
        return [
            'type' => $type, 'type_label' => $type === self::TYPE_INVOICES ? 'Factura' : 'Campaña',
            'channel' => self::CHANNEL_WHATSAPP, 'channel_label' => 'WhatsApp',
            'display_id' => ($type === self::TYPE_INVOICES ? 'W-F-' : 'W-C-') . (string) $item['id'],
            'raw_id' => (int) $item['id'], 'created_at' => (string) $item['created_at'],
            'scheduled_at' => (string) $item['scheduled_at'], 'client_name' => (string) ($item['client_name'] ?? ''),
            'client_meta' => $meta, 'recipient' => '+' . (string) $item['phone_to'], 'email_to' => '',
            'provider_message_id' => (string) ($item['provider_message_id'] ?? ''),
            'template_label' => (string) ($item['template_name'] ?? $item['batch_name'] ?? ''),
            'status' => (string) $item['status'], 'last_error' => (string) ($item['last_error'] ?? ''),
        ];
    }
}
