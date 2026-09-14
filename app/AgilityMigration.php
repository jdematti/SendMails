<?php
declare(strict_types=1);

final class AgilityMigration
{
    public static function run(PDO $pdo): void
    {
        $pdo->exec("IF OBJECT_ID('dbo.SendMail_Drafts', 'U') IS NULL
            CREATE TABLE dbo.SendMail_Drafts (
                id int IDENTITY(1,1) NOT NULL PRIMARY KEY,
                branch_id int NOT NULL, kind nvarchar(30) NOT NULL,
                name nvarchar(150) NOT NULL, payload_json nvarchar(max) NOT NULL,
                revision int NOT NULL DEFAULT 1, state nvarchar(20) NOT NULL DEFAULT 'draft',
                updated_by int NOT NULL, updated_at datetime2(0) NOT NULL DEFAULT SYSDATETIME(),
                created_at datetime2(0) NOT NULL DEFAULT SYSDATETIME()
            )");
        $pdo->exec("IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_SendMail_Drafts_Branch' AND object_id = OBJECT_ID('dbo.SendMail_Drafts'))
            CREATE INDEX IX_SendMail_Drafts_Branch ON dbo.SendMail_Drafts(branch_id, kind, state, updated_at DESC)");
        foreach (['Campaigns', 'InvoiceBatches', 'WhatsAppBatches'] as $suffix) {
            $table = 'dbo.SendMail_' . $suffix;
            $pdo->exec("IF COL_LENGTH('$table', 'message_snapshot') IS NULL ALTER TABLE $table ADD message_snapshot nvarchar(max) NULL");
        }
        foreach (['Queue', 'InvoiceQueue'] as $suffix) {
            $table = 'dbo.SendMail_' . $suffix;
            $pdo->exec("IF COL_LENGTH('$table', 'is_test') IS NULL ALTER TABLE $table ADD is_test bit NOT NULL DEFAULT 0");
        }
        foreach (['Templates', 'InvoiceTemplates'] as $suffix) {
            $table = 'dbo.SendMail_' . $suffix;
            $pdo->exec("IF COL_LENGTH('$table', 'content_revision') IS NULL ALTER TABLE $table ADD content_revision int NOT NULL DEFAULT 1");
        }
        // Snapshot once per batch, including paused/scheduled batches. Never copy attachments per recipient.
        foreach ([
            ['Campaigns', 'Templates', false],
            ['InvoiceBatches', 'InvoiceTemplates', false],
            ['WhatsAppBatches', 'WhatsAppTemplates', true],
        ] as [$batch, $template, $whatsApp]) {
            $ids = $pdo->query("SELECT b.id FROM dbo.SendMail_$batch b
                WHERE b.message_snapshot IS NULL AND b.status IN ('queued','processing','paused')")->fetchAll(PDO::FETCH_COLUMN);
            $read = $pdo->prepare("SELECT t.* FROM dbo.SendMail_$batch b
                INNER JOIN dbo.SendMail_$template t ON t.id = b.template_id AND t.branch_id = b.branch_id
                WHERE b.id = :id");
            $write = $pdo->prepare("UPDATE dbo.SendMail_$batch SET message_snapshot = :snapshot WHERE id = :id");
            foreach ($ids as $id) {
                $read->execute([':id' => $id]);
                $row = $read->fetch();
                if (!$row) {
                    throw new RuntimeException("No se encontro la plantilla del lote $batch #$id. La migracion se revierte.");
                }
                $write->execute([':id' => $id, ':snapshot' => MessageSnapshot::capture($row, $whatsApp)]);
                if ($batch === 'InvoiceBatches') {
                    $tag = $pdo->prepare("UPDATE dbo.SendMail_InvoiceQueue SET is_test=:test WHERE batch_id=:id AND status='pending'");
                    $tag->execute([':id' => $id, ':test' => !empty($row['test_mode']) ? 1 : 0]);
                }
            }
        }
    }
}
