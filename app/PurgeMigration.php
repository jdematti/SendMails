<?php
declare(strict_types=1);

final class PurgeMigration
{
    public static function run(PDO $pdo): void
    {
        $indexes = [
            ['Queue', 'PurgeBatch', 'campaign_id,branch_id', 'status,created_at,scheduled_at,sent_at'],
            ['InvoiceQueue', 'PurgeBatch', 'batch_id,branch_id', 'status,created_at,scheduled_at,sent_at'],
            ['WhatsAppQueue', 'PurgeBatch', 'batch_id,branch_id', 'status,created_at,scheduled_at,sent_at,accepted_at,delivered_at,read_at,failed_at,provider_message_id'],
            ['Log', 'PurgeCampaign', 'campaign_id,branch_id,created_at', ''],
            ['Log', 'PurgeQueue', 'queue_id,branch_id,created_at', 'campaign_id'],
            ['InvoiceLog', 'PurgeQueue', 'queue_id,branch_id,created_at', ''],
            ['WhatsAppEvents', 'PurgeMessage', 'provider_message_id,branch_id,created_at', ''],
        ];
        foreach ($indexes as [$suffix, $purpose, $keys, $included]) {
            $table = 'dbo.SendMail_' . $suffix;
            $name = 'IX_SendMail_' . $suffix . '_' . $purpose;
            $include = $included !== '' ? " INCLUDE ($included)" : '';
            $pdo->exec("IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='$name' AND object_id=OBJECT_ID('$table'))
                CREATE INDEX $name ON $table($keys)$include");
        }
    }
}
