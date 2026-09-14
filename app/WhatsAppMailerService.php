<?php

declare(strict_types=1);

final class WhatsAppMailerService
{
    private const MAX_ATTEMPTS = 5;

    public static function processPending(string $sourceType = '', int $limit = 1): array
    {
        $limit = max(1, min($limit, 1000));
        $summary = [
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
            'interval_seconds' => 0,
        ];

        foreach (WhatsAppRepository::pending($sourceType, $limit) as $item) {
            $queueId = (int) $item['id'];
            $batchId = (int) $item['batch_id'];
            if (!WhatsAppRepository::markSending($queueId)) {
                continue;
            }
            $summary['processed']++;

            try {
                $context = json_decode((string) ($item['context_json'] ?? '{}'), true);
                if (!is_array($context)) {
                    $context = [];
                }
                $branchId = (int) $item['branch_id'];
                $phone = (string) $item['phone_to'];
                if ((string) $item['source_type'] === WhatsAppRepository::SOURCE_CAMPAIGN
                    && WhatsAppRepository::isOptedOut($branchId, $phone, WhatsAppRepository::SOURCE_CAMPAIGN)) {
                    WhatsAppRepository::markSkipped($queueId, 'Destinatario dado de baja por WhatsApp.');
                    $summary['skipped']++;
                    continue;
                }

                $config = Settings::whatsapp($branchId);
                $response = WhatsAppBusinessService::sendTemplate($config, $phone, $item, $context);
                $providerId = trim((string) ($response['messages'][0]['id'] ?? ''));
                if ($providerId === '') {
                    throw new RuntimeException('Meta acepto la solicitud sin devolver un identificador de mensaje.');
                }
                WhatsAppRepository::markAccepted($queueId, $providerId, $response);
                $summary['sent']++;
            } catch (Throwable $e) {
                $attempts = (int) ($item['attempts'] ?? 0) + 1;
                if ($e instanceof WhatsAppApiException && $e->isRetryable() && $attempts < self::MAX_ATTEMPTS) {
                    $delay = min(3600, 30 * (2 ** max(0, $attempts - 1)));
                    WhatsAppRepository::reschedule($queueId, $e->getMessage(), $delay);
                    $summary['errors'][] = 'WhatsApp ' . $phone . ': reprogramado en ' . $delay . ' segundos. ' . $e->getMessage();
                } else {
                    WhatsAppRepository::markFailed($queueId, $e->getMessage());
                    $summary['failed']++;
                    $summary['errors'][] = 'WhatsApp ' . ((string) ($item['phone_to'] ?? '')) . ': ' . $e->getMessage();
                }
            } finally {
                WhatsAppRepository::updateBatchTotals($batchId);
            }
        }

        return $summary;
    }
}
