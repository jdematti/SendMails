<?php

declare(strict_types=1);

final class QueueRepository
{
    public const CAMPAIGN_STATUS_QUEUED = 'queued';
    public const CAMPAIGN_STATUS_PROCESSING = 'processing';
    public const CAMPAIGN_STATUS_PAUSED = 'paused';
    public const CAMPAIGN_STATUS_STOPPED = 'stopped';
    public const CAMPAIGN_STATUS_COMPLETED = 'completed';

    public static function dashboardStats(): array
    {
        Schema::ensure();
        $pdo = Database::pdo();
        [$queueBranchWhere, $queueBranchParams] = BranchRepository::activeBranchWhere();
        $queueBranchSql = $queueBranchWhere !== '' ? 'WHERE ' . $queueBranchWhere : '';
        $clientsWithEmail = ClientRepository::countWithEmail();
        $clientsWithValidEmail = ClientRepository::countWithValidEmail();
        $sentStmt = $pdo->prepare("SELECT COUNT(*) FROM dbo.SendMail_Log $queueBranchSql" . ($queueBranchSql === '' ? " WHERE status = 'sent'" : " AND status = 'sent'"));
        $sentStmt->execute($queueBranchParams);
        $failedStmt = $pdo->prepare("SELECT COUNT(*) FROM dbo.SendMail_Log $queueBranchSql" . ($queueBranchSql === '' ? " WHERE status = 'failed'" : " AND status = 'failed'"));
        $failedStmt->execute($queueBranchParams);
        $pendingStmt = $pdo->prepare("SELECT COUNT(*) FROM dbo.SendMail_Queue $queueBranchSql" . ($queueBranchSql === '' ? " WHERE status = 'pending'" : " AND status = 'pending'"));
        $pendingStmt->execute($queueBranchParams);
        return [
            'templates' => TemplateRepository::count(),
            'sent' => (int) $sentStmt->fetchColumn(),
            'failed' => (int) $failedStmt->fetchColumn(),
            'pending' => (int) $pendingStmt->fetchColumn(),
            'unsubscribed' => UnsubscribeRepository::countAll(),
            'clients_with_email' => $clientsWithEmail,
            'clients_without_email' => max(0, ClientRepository::countAll() - $clientsWithEmail),
            'clients_invalid_email' => max(0, $clientsWithEmail - $clientsWithValidEmail),
        ];
    }

    public static function createCampaign(int $templateId, string $name, string $mode, array $clients, ?string $scheduledAt = null, ?string $snapshot = null): int
    {
        Schema::ensure();
        $clients = array_values(array_filter($clients, static function (array $client): bool {
            return filter_var((string) ($client['email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false;
        }));
        $clients = UnsubscribeRepository::filterSubscribedClients($clients);
        $clients = EmailExclusionRepository::filterAllowedRecipients($clients);
        if (!$clients) {
            throw new RuntimeException('No hay destinatarios habilitados para encolar.');
        }
        $branchId = BranchRepository::normalizeBranchIdFromRows($clients);
        if ($branchId <= 0) {
            throw new RuntimeException('Selecciona una sucursal antes de crear la campana.');
        }
        $snapshot = $snapshot ?? MessageSnapshot::capture(TemplateRepository::find($templateId) ?? [], false);
        $pdo = Database::pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();

        try {
            $campaignStmt = $pdo->prepare(
                'INSERT INTO dbo.SendMail_Campaigns (branch_id, template_id, name, send_mode, status, total_queued, total_sent, total_failed)
                 OUTPUT INSERTED.id
                 VALUES (:branch_id, :template_id, :name, :send_mode, :status, :total_queued, 0, 0)'
            );
            $campaignStmt->execute([
                ':branch_id' => $branchId,
                ':template_id' => $templateId,
                ':name' => $name,
                ':send_mode' => $mode,
                ':status' => 'queued',
                ':total_queued' => count($clients),
            ]);
            $campaignId = (int) $campaignStmt->fetchColumn();
            $snapshotStmt = $pdo->prepare('UPDATE dbo.SendMail_Campaigns SET message_snapshot = :snapshot WHERE id = :id');
            $snapshotStmt->execute([':snapshot' => $snapshot, ':id' => $campaignId]);

            $queueRows = [];

            foreach ($clients as $client) {
                $email = trim((string) ($client['email'] ?? ''));
                if ($email === '') {
                    continue;
                }
                $client['branch_id'] = $branchId;
                $queueRows[] = [
                    'branch_id' => $branchId,
                    'campaign_id' => $campaignId,
                    'template_id' => $templateId,
                    'client_oid' => trim((string) ($client['oid'] ?? '')),
                    'client_code' => (string) ($client['codigo_cliente'] ?? ''),
                    'client_name' => (string) ($client['razon_social'] ?? ''),
                    'email_to' => $email,
                    'context_json' => json_encode($client, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'status' => 'pending',
                    'scheduled_at' => $scheduledAt ?? date('Y-m-d H:i:s'),
                ];
            }

            BulkInsert::rows($pdo, 'SendMail_Queue', $queueRows);
            if ($ownsTransaction) $pdo->commit();
            return $campaignId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function pending(int $limit, bool $metadataOnly = false): array
    {
        Schema::ensure();
        $columns = $metadataOnly ? 'q.id, q.scheduled_at, q.branch_id' : 'q.*, c.message_snapshot';
        $limit = max(1, min($limit, 10000));
        [$branchWhere, $branchParams] = BranchRepository::activeBranchWhere('q');
        $branchSql = $branchWhere !== '' ? ' AND ' . $branchWhere : '';
        $sql = "SELECT TOP $limit $columns
                FROM dbo.SendMail_Queue q
                INNER JOIN dbo.SendMail_Templates t ON t.id = q.template_id AND t.branch_id = q.branch_id
                INNER JOIN dbo.SendMail_Campaigns c ON c.id = q.campaign_id AND c.branch_id = q.branch_id
                WHERE q.status = 'pending'
                  AND q.scheduled_at <= SYSDATETIME()
                  $branchSql
                  AND c.status IN ('queued', 'processing')
                ORDER BY q.scheduled_at, q.id";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($branchParams);
        return $metadataOnly ? $stmt->fetchAll() : array_map([MessageSnapshot::class, 'apply'], $stmt->fetchAll());
    }

    public static function pendingCount(): int
    {
        Schema::ensure();
        [$branchWhere, $branchParams] = BranchRepository::activeBranchWhere('q');
        $branchSql = $branchWhere !== '' ? ' AND ' . $branchWhere : '';
        $stmt = Database::pdo()->prepare(
            "SELECT COUNT(*)
             FROM dbo.SendMail_Queue q
             INNER JOIN dbo.SendMail_Campaigns c ON c.id = q.campaign_id AND c.branch_id = q.branch_id
             WHERE q.status = 'pending'
               AND q.scheduled_at <= SYSDATETIME()
               $branchSql
               AND c.status IN ('queued', 'processing')"
        );
        $stmt->execute($branchParams);
        return (int) $stmt->fetchColumn();
    }

    public static function markSending(int $id): bool
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE q
             SET status = 'sending', attempts = attempts + 1
             FROM dbo.SendMail_Queue q
             INNER JOIN dbo.SendMail_Campaigns c ON c.id = q.campaign_id AND c.branch_id = q.branch_id
             WHERE q.id = :id
               AND q.status = 'pending'
               AND c.status IN ('queued', 'processing')"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public static function markSent(int $id): void
    {
        $stmt = Database::pdo()->prepare("UPDATE dbo.SendMail_Queue SET status = 'sent', sent_at = SYSDATETIME(), last_error = NULL WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    public static function markFailed(int $id, string $error): void
    {
        $stmt = Database::pdo()->prepare("UPDATE dbo.SendMail_Queue SET status = 'failed', last_error = :error WHERE id = :id");
        $stmt->execute([':id' => $id, ':error' => $error]);
    }

    public static function markNeedsReview(int $id, string $error): void
    {
        $stmt = Database::pdo()->prepare("UPDATE dbo.SendMail_Queue SET status='sending', last_error=:error WHERE id=:id");
        $stmt->execute([':id'=>$id, ':error'=>'El proveedor acepto el email; revisar el registro antes de reenviar. ' . $error]);
    }

    public static function markSkipped(int $id, string $reason): void
    {
        $stmt = Database::pdo()->prepare("UPDATE dbo.SendMail_Queue SET status = 'skipped', last_error = :reason WHERE id = :id");
        $stmt->execute([':id' => $id, ':reason' => $reason]);
    }

    public static function insertLog(array $data): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO dbo.SendMail_Log
             (branch_id, queue_id, campaign_id, template_id, client_oid, client_code, client_name, email_to, email_subject, status, smtp_host, error_message, provider_response, html_snapshot, context_json, duration_ms)
             VALUES
             (:branch_id, :queue_id, :campaign_id, :template_id, :client_oid, :client_code, :client_name, :email_to, :email_subject, :status, :smtp_host, :error_message, :provider_response, :html_snapshot, :context_json, :duration_ms)'
        );
        $stmt->execute([
            ':branch_id' => $data['branch_id'] ?? BranchRepository::currentId(),
            ':queue_id' => $data['queue_id'] ?? null,
            ':campaign_id' => $data['campaign_id'] ?? null,
            ':template_id' => $data['template_id'] ?? null,
            ':client_oid' => $data['client_oid'] ?? null,
            ':client_code' => $data['client_code'] ?? null,
            ':client_name' => $data['client_name'] ?? null,
            ':email_to' => $data['email_to'] ?? '',
            ':email_subject' => $data['email_subject'] ?? null,
            ':status' => $data['status'] ?? 'failed',
            ':smtp_host' => $data['smtp_host'] ?? null,
            ':error_message' => $data['error_message'] ?? null,
            ':provider_response' => $data['provider_response'] ?? null,
            ':html_snapshot' => $data['html_snapshot'] ?? null,
            ':context_json' => $data['context_json'] ?? null,
            ':duration_ms' => $data['duration_ms'] ?? null,
        ]);
    }

    public static function updateCampaignTotals(int $campaignId): void
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE c
             SET total_sent = x.total_sent,
                 total_failed = x.total_failed,
                 status = CASE
                    WHEN c.status IN ('paused', 'stopped') THEN c.status
                    WHEN x.total_pending = 0 AND x.total_sending = 0 THEN 'completed'
                    WHEN x.total_sent > 0 OR x.total_failed > 0 OR x.total_sending > 0 THEN 'processing'
                    ELSE c.status
                 END,
                 started_at = CASE WHEN c.started_at IS NULL AND (x.total_sent > 0 OR x.total_failed > 0 OR x.total_sending > 0) THEN SYSDATETIME() ELSE c.started_at END,
                 completed_at = CASE
                    WHEN c.status = 'stopped' THEN COALESCE(c.completed_at, SYSDATETIME())
                    WHEN c.status <> 'paused' AND x.total_pending = 0 AND x.total_sending = 0 THEN SYSDATETIME()
                    ELSE c.completed_at
                 END
             FROM dbo.SendMail_Campaigns c
             CROSS APPLY (
                SELECT
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS total_sent,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS total_failed,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS total_pending,
                    SUM(CASE WHEN status = 'sending' THEN 1 ELSE 0 END) AS total_sending
                FROM dbo.SendMail_Queue
                WHERE campaign_id = c.id
             ) x
             WHERE c.id = :campaign_id"
        );
        $stmt->execute([':campaign_id' => $campaignId]);
    }

    public static function recentLogs(int $limit = 20, array $filters = []): array
    {
        Schema::ensure();
        $limit = in_array($limit, [20, 50, 100], true) ? $limit : 20;
        $where = [];
        $params = [];

        $client = trim((string) ($filters['client'] ?? ''));
        if ($client !== '') {
            $where[] = '(client_name LIKE :client_name OR client_code LIKE :client_code)';
            $params[':client_name'] = '%' . $client . '%';
            $params[':client_code'] = '%' . $client . '%';
        }

        $email = trim((string) ($filters['email'] ?? ''));
        if ($email !== '') {
            $where[] = 'email_to LIKE :email';
            $params[':email'] = '%' . $email . '%';
        }

        $subject = trim((string) ($filters['subject'] ?? ''));
        if ($subject !== '') {
            $where[] = 'email_subject LIKE :subject';
            $params[':subject'] = '%' . $subject . '%';
        }

        $date = trim((string) ($filters['date'] ?? ''));
        if ($date !== '') {
            $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsedDate instanceof DateTimeImmutable) {
                $where[] = 'created_at >= :date_from AND created_at < :date_to';
                $params[':date_from'] = $parsedDate->format('Y-m-d 00:00:00');
                $params[':date_to'] = $parsedDate->modify('+1 day')->format('Y-m-d 00:00:00');
            }
        }
        [$branchWhere, $branchParams] = BranchRepository::activeBranchWhere();
        if ($branchWhere !== '') {
            $where[] = $branchWhere;
            $params += $branchParams;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = Database::pdo()->prepare(
            "SELECT TOP $limit
                id,
                created_at,
                client_name,
                client_code,
                email_to,
                email_subject,
                smtp_host,
                status,
                error_message,
                duration_ms
             FROM dbo.SendMail_Log
             $whereSql
             ORDER BY created_at DESC, id DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function recentCampaigns(int $limit = 20): array
    {
        Schema::ensure();
        $limit = max(1, min($limit, 100));
        [$branchWhere, $branchParams] = BranchRepository::activeBranchWhere('c');
        $whereSql = $branchWhere !== '' ? 'WHERE ' . $branchWhere : '';
        $stmt = Database::pdo()->prepare(
            "SELECT TOP $limit c.*, t.name AS template_name
             FROM dbo.SendMail_Campaigns c
             INNER JOIN dbo.SendMail_Templates t ON t.id = c.template_id AND t.branch_id = c.branch_id
             $whereSql
             ORDER BY c.created_at DESC, c.id DESC"
        );
        $stmt->execute($branchParams);
        return $stmt->fetchAll();
    }

    public static function campaignStatusCounts(): array
    {
        Schema::ensure();
        $counts = [
            self::CAMPAIGN_STATUS_QUEUED => 0,
            self::CAMPAIGN_STATUS_PROCESSING => 0,
            self::CAMPAIGN_STATUS_PAUSED => 0,
            self::CAMPAIGN_STATUS_STOPPED => 0,
            self::CAMPAIGN_STATUS_COMPLETED => 0,
        ];

        [$branchWhere, $branchParams] = BranchRepository::activeBranchWhere();
        $whereSql = $branchWhere !== '' ? 'WHERE ' . $branchWhere : '';
        $stmt = Database::pdo()->prepare("SELECT status, COUNT(*) AS total FROM dbo.SendMail_Campaigns $whereSql GROUP BY status");
        $stmt->execute($branchParams);
        foreach ($stmt->fetchAll() as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    public static function campaigns(string $status = '', int $limit = 50): array
    {
        Schema::ensure();
        $limit = max(1, min($limit, 200));
        $params = [];
        $where = [];
        if ($status !== '') {
            $where[] = 'c.status = :status';
            $params[':status'] = $status;
        }
        [$branchWhere, $branchParams] = BranchRepository::activeBranchWhere('c');
        if ($branchWhere !== '') {
            $where[] = $branchWhere;
            $params += $branchParams;
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = Database::pdo()->prepare(
            "SELECT TOP $limit
                c.id,
                c.name,
                c.send_mode,
                c.status,
                c.total_queued,
                c.total_sent,
                c.total_failed,
                c.branch_id,
                c.created_at,
                c.started_at,
                c.completed_at,
                t.name AS template_name,
                MIN(q.scheduled_at) AS first_scheduled_at,
                MAX(q.scheduled_at) AS last_scheduled_at,
                COUNT(q.id) AS queue_total,
                COALESCE(SUM(CASE WHEN q.status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_count,
                COALESCE(SUM(CASE WHEN q.status = 'sending' THEN 1 ELSE 0 END), 0) AS sending_count,
                COALESCE(SUM(CASE WHEN q.status = 'sent' THEN 1 ELSE 0 END), 0) AS sent_count,
                COALESCE(SUM(CASE WHEN q.status = 'failed' THEN 1 ELSE 0 END), 0) AS failed_count,
                COALESCE(SUM(CASE WHEN q.status = 'skipped' THEN 1 ELSE 0 END), 0) AS skipped_count
             FROM dbo.SendMail_Campaigns c
             INNER JOIN dbo.SendMail_Templates t ON t.id = c.template_id AND t.branch_id = c.branch_id
             LEFT JOIN dbo.SendMail_Queue q ON q.campaign_id = c.id AND q.branch_id = c.branch_id
             $whereSql
             GROUP BY
                c.id,
                c.name,
                c.send_mode,
                c.status,
                c.total_queued,
                c.total_sent,
                c.total_failed,
                c.branch_id,
                c.created_at,
                c.started_at,
                c.completed_at,
                t.name
             ORDER BY c.created_at DESC, c.id DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function pauseCampaign(int $campaignId): void
    {
        self::changeCampaignStatus(
            $campaignId,
            [self::CAMPAIGN_STATUS_QUEUED, self::CAMPAIGN_STATUS_PROCESSING],
            self::CAMPAIGN_STATUS_PAUSED,
            'Solo se pueden pausar campanas en cola o en proceso.'
        );
    }

    public static function resumeCampaign(int $campaignId): void
    {
        Schema::ensure();
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $campaign = self::lockCampaign($campaignId);
            if (!$campaign) {
                throw new RuntimeException('Campana no encontrada.');
            }
            if ((string) $campaign['status'] !== self::CAMPAIGN_STATUS_PAUSED) {
                throw new RuntimeException('Solo se pueden reanudar campanas pausadas.');
            }

            $hasProgressStmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM dbo.SendMail_Queue
                 WHERE campaign_id = :campaign_id
                   AND status IN ('sent', 'failed', 'sending', 'skipped')"
            );
            $hasProgressStmt->execute([':campaign_id' => $campaignId]);
            $nextStatus = (int) $hasProgressStmt->fetchColumn() > 0
                ? self::CAMPAIGN_STATUS_PROCESSING
                : self::CAMPAIGN_STATUS_QUEUED;

            $stmt = $pdo->prepare(
                "UPDATE dbo.SendMail_Campaigns
                 SET status = :status, completed_at = NULL
                 WHERE id = :campaign_id"
            );
            $stmt->execute([
                ':status' => $nextStatus,
                ':campaign_id' => $campaignId,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function stopCampaign(int $campaignId): void
    {
        Schema::ensure();
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $campaign = self::lockCampaign($campaignId);
            if (!$campaign) {
                throw new RuntimeException('Campana no encontrada.');
            }

            $status = (string) $campaign['status'];
            if (!in_array($status, [self::CAMPAIGN_STATUS_QUEUED, self::CAMPAIGN_STATUS_PROCESSING, self::CAMPAIGN_STATUS_PAUSED], true)) {
                throw new RuntimeException('Solo se pueden detener campanas en cola, en proceso o pausadas.');
            }

            $queueStmt = $pdo->prepare(
                "UPDATE dbo.SendMail_Queue
                 SET status = 'skipped', last_error = 'Campana detenida.'
                 WHERE campaign_id = :campaign_id
                   AND status = 'pending'"
            );
            $queueStmt->execute([':campaign_id' => $campaignId]);

            $stmt = $pdo->prepare(
                "UPDATE dbo.SendMail_Campaigns
                 SET status = 'stopped',
                     completed_at = SYSDATETIME()
                 WHERE id = :campaign_id"
            );
            $stmt->execute([':campaign_id' => $campaignId]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function deleteQueuedCampaign(int $campaignId): void
    {
        Schema::ensure();
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $campaign = self::lockCampaign($campaignId);
            if (!$campaign) {
                throw new RuntimeException('Campana no encontrada.');
            }
            if ((string) $campaign['status'] !== self::CAMPAIGN_STATUS_QUEUED) {
                throw new RuntimeException('Solo se pueden eliminar campanas en estado queued.');
            }

            $nonPendingStmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM dbo.SendMail_Queue
                 WHERE campaign_id = :campaign_id
                   AND status <> 'pending'"
            );
            $nonPendingStmt->execute([':campaign_id' => $campaignId]);
            if ((int) $nonPendingStmt->fetchColumn() > 0) {
                throw new RuntimeException('La campana ya tiene movimientos y no se puede eliminar.');
            }

            $stmt = $pdo->prepare('DELETE FROM dbo.SendMail_Log WHERE campaign_id = :campaign_id');
            $stmt->execute([':campaign_id' => $campaignId]);

            $stmt = $pdo->prepare('DELETE FROM dbo.SendMail_Queue WHERE campaign_id = :campaign_id');
            $stmt->execute([':campaign_id' => $campaignId]);

            $stmt = $pdo->prepare('DELETE FROM dbo.SendMail_Campaigns WHERE id = :campaign_id AND status = :status');
            $stmt->execute([
                ':campaign_id' => $campaignId,
                ':status' => self::CAMPAIGN_STATUS_QUEUED,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('No se pudo eliminar la campana.');
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function changeCampaignStatus(int $campaignId, array $allowedStatuses, string $newStatus, string $errorMessage): void
    {
        Schema::ensure();
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $campaign = self::lockCampaign($campaignId);
            if (!$campaign) {
                throw new RuntimeException('Campana no encontrada.');
            }
            if (!in_array((string) $campaign['status'], $allowedStatuses, true)) {
                throw new RuntimeException($errorMessage);
            }

            $stmt = $pdo->prepare(
                'UPDATE dbo.SendMail_Campaigns SET status = :status WHERE id = :campaign_id'
            );
            $stmt->execute([
                ':status' => $newStatus,
                ':campaign_id' => $campaignId,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function lockCampaign(int $campaignId): ?array
    {
        [$branchWhere, $branchParams] = BranchRepository::activeBranchWhere();
        $branchSql = $branchWhere !== '' ? ' AND ' . $branchWhere : '';
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM dbo.SendMail_Campaigns WITH (UPDLOCK, HOLDLOCK) WHERE id = :campaign_id $branchSql"
        );
        $stmt->execute([':campaign_id' => $campaignId] + $branchParams);
        $campaign = $stmt->fetch();
        return is_array($campaign) ? $campaign : null;
    }

    public static function queueItems(string $status = '', int $limit = 100): array
    {
        Schema::ensure();
        $limit = max(1, min($limit, 500));
        $params = [];
        $where = [];
        if ($status !== '') {
            $where[] = 'q.status = :status';
            $params[':status'] = $status;
        }
        [$branchWhere, $branchParams] = BranchRepository::activeBranchWhere('q');
        if ($branchWhere !== '') {
            $where[] = $branchWhere;
            $params += $branchParams;
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = Database::pdo()->prepare(
            "SELECT TOP $limit
                q.id,
                q.branch_id,
                q.campaign_id,
                q.template_id,
                q.client_oid,
                q.client_code,
                q.client_name,
                q.email_to,
                q.status,
                q.attempts,
                q.scheduled_at,
                q.sent_at,
                q.last_error,
                q.created_at,
                t.name AS template_name
             FROM dbo.SendMail_Queue q
             INNER JOIN dbo.SendMail_Templates t ON t.id = q.template_id AND t.branch_id = q.branch_id
             $whereSql
             ORDER BY q.created_at DESC, q.id DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
