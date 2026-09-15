<?php

declare(strict_types=1);

final class InvoiceRepository
{
    public const BATCH_STATUS_QUEUED = 'queued';
    public const BATCH_STATUS_PROCESSING = 'processing';
    public const BATCH_STATUS_PAUSED = 'paused';
    public const BATCH_STATUS_STOPPED = 'stopped';
    public const BATCH_STATUS_COMPLETED = 'completed';

    public static function templates(bool $metadataOnly = false): array
    {
        Schema::ensure();
        [$branchWhere, $branchParams] = BranchRepository::activeBranchWhere();
        $whereSql = $branchWhere !== '' ? 'WHERE ' . $branchWhere : '';
        $columns = $metadataOnly ? "id, name, subject, is_active" : "*";
        $stmt = Database::pdo()->prepare(
            "SELECT $columns FROM dbo.SendMail_InvoiceTemplates
             $whereSql
             ORDER BY is_active DESC, id ASC"
        );
        $stmt->execute($branchParams);
        return $stmt->fetchAll();
    }

    public static function defaultTemplate(): array
    {
        Schema::ensure();
        [$branchWhere, $branchParams] = BranchRepository::activeBranchWhere();
        $branchSql = $branchWhere !== '' ? ' AND ' . $branchWhere : '';
        $stmt = Database::pdo()->prepare(
            "SELECT TOP 1 *
             FROM dbo.SendMail_InvoiceTemplates
             WHERE is_active = 1 $branchSql
             ORDER BY id ASC"
        );
        $stmt->execute($branchParams);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('No hay plantilla de facturas activa.');
        }

        return $row;
    }

    public static function findTemplate(int $id): ?array
    {
        Schema::ensure();
        [$branchWhere, $branchParams] = BranchRepository::activeBranchWhere();
        $branchSql = $branchWhere !== '' ? ' AND ' . $branchWhere : '';
        $stmt = Database::pdo()->prepare("SELECT * FROM dbo.SendMail_InvoiceTemplates WHERE id = :id $branchSql");
        $stmt->execute([':id' => $id] + $branchParams);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public static function saveTemplate(?int $id, array $data): int
    {
        Schema::ensure();
        $payload = self::normalizeTemplate($data);
        $branchId = BranchRepository::currentId();
        if ($branchId === null || $branchId <= 0) {
            throw new RuntimeException('Selecciona una sucursal antes de guardar plantillas.');
        }
        $pdo = Database::pdo();

        if ($id !== null) {
            $stmt = $pdo->prepare(
                'UPDATE dbo.SendMail_InvoiceTemplates
                 SET name = :name,
                     subject = :subject,
                     html_body = :html_body,
                     from_email = :from_email,
                     from_name = :from_name,
                     reply_to = :reply_to,
                     bcc = :bcc,
                     web = :web,
                     loc_prefix = :loc_prefix,
                     domicilio = :domicilio,
                     facebook = :facebook,
                     instagram = :instagram,
                     whatsapp = :whatsapp,
                     test_mode = :test_mode,
                     test_email = :test_email,
                     is_active = :is_active,
                     updated_at = SYSDATETIME(), content_revision = content_revision + 1
                 WHERE id = :id AND branch_id = :branch_id'
            );
            $payload[':id'] = $id;
            $payload[':branch_id'] = $branchId;
            $stmt->execute($payload);
            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('Plantilla de facturas no encontrada para la sucursal activa.');
            }
            return $id;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO dbo.SendMail_InvoiceTemplates
             (branch_id, name, subject, html_body, from_email, from_name, reply_to, bcc, web, loc_prefix, domicilio, facebook, instagram, whatsapp, test_mode, test_email, is_active)
             OUTPUT INSERTED.id
             VALUES
             (:branch_id, :name, :subject, :html_body, :from_email, :from_name, :reply_to, :bcc, :web, :loc_prefix, :domicilio, :facebook, :instagram, :whatsapp, :test_mode, :test_email, :is_active)'
        );
        $payload[':branch_id'] = $branchId;
        $stmt->execute($payload);
        return (int) $stmt->fetchColumn();
    }

    public static function search(string $dueDate, string $status, bool $includeSent, array $template, array $filters = [], int $limit = 250, string $channel = 'email', int $page = 1, bool $withTokens = true): array
    {
        Schema::ensure();
        $channel = self::normalizeDeliveryChannel($channel);
        $phoneExpression = self::invoicePhoneExpression();
        $criteria = self::invoiceSearchCriteria($dueDate, $status, $includeSent, $filters, $channel, $phoneExpression);
        if ($limit > 0) $limit = max(20, min($limit, 10000));
        $numbering = $limit > 0 ? ', ROW_NUMBER() OVER (ORDER BY F.SNB, F.Id) AS page_row_number' : '';

        $branchId = (int) (BranchRepository::currentId() ?? 0);
        $sql = "SELECT
                    CAST($branchId AS int) AS branch_id,
                    LOWER(LTRIM(RTRIM(C.DirEMail))) COLLATE Modern_Spanish_CI_AS AS email,
                    NULLIF($phoneExpression, '') AS telefono_movil,
                    CAST(F.V1_M_Final AS decimal(18,2)) AS amount,
                    LTRIM(RTRIM(F.Nombre)) AS client_name,
                    LTRIM(RTRIM(F.SNB)) AS snb,
                    CONVERT(varchar(10), F.V1_FechaVto, 103) AS due_date_label,
                    CONVERT(varchar(10), F.V1_FechaVto, 23) AS due_date,
                    CONVERT(nvarchar(80), F.Id) AS invoice_id,
                    F.Estado AS status,
                    ISNULL(F.mail_enviado, 0) AS mail_sent,
                    CASE WHEN F.fecha_mail_Enviado IS NULL THEN '' ELSE CONVERT(varchar(10), F.fecha_mail_Enviado, 103) END AS sent_label
                    $numbering
                FROM FacturasTel F
                INNER JOIN clientes C ON C.OId COLLATE Modern_Spanish_CI_AS = F.IdCliente
                WHERE " . implode(' AND ', $criteria['where']);
        if ($limit > 0) {
            $criteria['params'][':page_start'] = (max(1, $page) - 1) * $limit;
            $criteria['params'][':page_end'] = $criteria['params'][':page_start'] + $limit;
            $sql = 'WITH numbered_invoices AS (' . $sql . ') SELECT * FROM numbered_invoices
                WHERE page_row_number > :page_start AND page_row_number <= :page_end ORDER BY page_row_number';
        } else {
            $sql .= ' ORDER BY F.SNB, F.Id';
        }

        $stmt = self::sourcePdo()->prepare($sql);
        $stmt->execute($criteria['params']);
        $rows = [];
        $index = 0;
        while ($row = $stmt->fetch()) {
            unset($row['page_row_number']);
            $row = self::normalizeInvoiceRow($row, $template);
            $hasEmail = filter_var((string) $row['email'], FILTER_VALIDATE_EMAIL) !== false;
            $hasPhone = trim((string) ($row['telefono_movil'] ?? '')) !== '';
            $row['valid_email'] = $hasEmail;
            $row['valid_phone'] = WhatsAppPhone::normalize((string) ($row['telefono_movil'] ?? '')) !== null;
            if ($withTokens) $row['selection_token'] = self::selectionToken($row, $index++);

            $rows[] = $row;
        }

        return $rows;
    }

    public static function matchingIds(string $dueDate, string $status, bool $includeSent, array $filters = [], string $channel = 'email'): array
    {
        Schema::ensure();
        $criteria = self::invoiceSearchCriteria($dueDate, $status, $includeSent, $filters, self::normalizeDeliveryChannel($channel), self::invoicePhoneExpression());
        $stmt = self::sourcePdo()->prepare('SELECT CONVERT(nvarchar(80), F.Id) FROM FacturasTel F
            INNER JOIN clientes C ON C.OId COLLATE Modern_Spanish_CI_AS = F.IdCliente
            WHERE ' . implode(' AND ', $criteria['where']) . ' ORDER BY F.SNB, F.Id');
        $stmt->execute($criteria['params']);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function searchTotal(string $dueDate, string $status, bool $includeSent, array $filters = [], string $channel = 'email'): int
    {
        Schema::ensure();
        $channel = self::normalizeDeliveryChannel($channel);
        $phoneExpression = self::invoicePhoneExpression();
        $criteria = self::invoiceSearchCriteria($dueDate, $status, $includeSent, $filters, $channel, $phoneExpression);
        $sql = 'SELECT COUNT(*)
                FROM FacturasTel F
                INNER JOIN clientes C ON C.OId COLLATE Modern_Spanish_CI_AS = F.IdCliente
                WHERE ' . implode(' AND ', $criteria['where']);

        $stmt = self::sourcePdo()->prepare($sql);
        $stmt->execute($criteria['params']);
        return (int) $stmt->fetchColumn();
    }

    public static function recentDueDates(int $limit = 10): array
    {
        Schema::ensure();
        $limit = max(1, min($limit, 50));

        $sql = "SELECT TOP $limit
                    CONVERT(varchar(10), x.due_date, 23) AS due_date,
                    CONVERT(varchar(10), x.due_date, 103) AS due_date_label
                FROM (
                    SELECT DISTINCT CONVERT(date, V1_FechaVto) AS due_date
                    FROM FacturasTel
                    WHERE Autorizada = 1
                      AND V1_FechaVto IS NOT NULL
                ) x
                ORDER BY x.due_date DESC";

        return self::sourcePdo()->query($sql)->fetchAll();
    }

    private static function invoiceSearchCriteria(string $dueDate, string $status, bool $includeSent, array $filters, string $channel, string $phoneExpression): array
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);
        if (!$date || $date->format('Y-m-d') !== $dueDate) {
            throw new InvalidArgumentException('Selecciona un vencimiento valido.');
        }

        $where = [
            'F.V1_FechaVto >= CONVERT(date, :due_date, 112)',
            'F.V1_FechaVto < CONVERT(date, :next_date, 112)',
            'F.Autorizada = 1',
            "F.Estado <> 'A'",
        ];
        $validEmailSql = "NULLIF(LTRIM(RTRIM(C.DirEMail)), '') IS NOT NULL";
        $validPhoneSql = "NULLIF($phoneExpression, '') IS NOT NULL";
        if ($channel === 'email') {
            $where[] = $validEmailSql;
        } elseif ($channel === 'whatsapp') {
            $where[] = $validPhoneSql;
        } else {
            $where[] = "($validEmailSql OR $validPhoneSql)";
        }
        $params = [
            ':due_date' => $date->format('Ymd'),
            ':next_date' => $date->modify('+1 day')->format('Ymd'),
        ];

        if ($status !== '') {
            if (!in_array($status, ['I', 'P'], true)) {
                throw new InvalidArgumentException('Estado invalido.');
            }
            $where[] = 'F.Estado = :status';
            $params[':status'] = $status;
        }

        if (!$includeSent && $channel !== 'whatsapp') {
            $where[] = 'ISNULL(F.mail_enviado, 0) = 0';
        }

        if (isset($filters['ids'])) {
            $keys = [];
            foreach (array_slice($filters['ids'], 0, 500) as $i => $id) {
                $keys[] = ":id$i"; $params[":id$i"] = (string) $id;
            }
            $where[] = $keys ? 'CONVERT(nvarchar(80), F.Id) IN (' . implode(',', $keys) . ')' : '1=0';
        }
        $snb = trim((string) ($filters['snb'] ?? ''));
        if ($snb !== '') {
            $where[] = 'LTRIM(RTRIM(F.SNB)) LIKE :snb';
            $params[':snb'] = '%' . $snb . '%';
        }

        $email = trim((string) ($filters['email'] ?? ''));
        if ($email !== '') {
            $where[] = 'C.DirEMail LIKE :email';
            $params[':email'] = '%' . $email . '%';
        }

        $phone = trim((string) ($filters['phone'] ?? ''));
        if ($phone !== '') {
            $where[] = "$phoneExpression LIKE :phone";
            $params[':phone'] = '%' . $phone . '%';
        }

        $name = trim((string) ($filters['name'] ?? ''));
        if ($name !== '') {
            $where[] = 'F.Nombre LIKE :name';
            $params[':name'] = '%' . $name . '%';
        }

        return [
            'where' => $where,
            'params' => $params,
        ];
    }

    public static function findBySelectionTokens(array $tokens): array
    {
        $items = [];
        foreach ($tokens as $token) {
            if (!is_scalar($token)) {
                continue;
            }

            $parts = explode('.', (string) $token, 2);
            if (count($parts) !== 2) {
                continue;
            }

            [$encoded, $signature] = $parts;
            $expected = hash_hmac('sha256', $encoded, csrf_token());
            if ($encoded === '' || $signature === '' || !hash_equals($expected, $signature)) {
                continue;
            }

            $json = self::base64UrlDecode($encoded);
            if (!is_string($json)) {
                continue;
            }

            $payload = json_decode($json, true);
            if (!is_array($payload)) {
                continue;
            }

            $tokenBranchId = (int) ($payload['branch_id'] ?? 0);
            $currentBranchId = (int) (BranchRepository::currentId() ?? 0);
            if ($tokenBranchId > 0 && $currentBranchId > 0 && $tokenBranchId !== $currentBranchId) {
                continue;
            }

            $email = trim((string) ($payload['email'] ?? ''));
            $phone = trim((string) ($payload['telefono_movil'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) && $phone === '') {
                continue;
            }

            $key = (string) ($payload['invoice_id'] ?? '') . '|' . (string) ($payload['snb'] ?? '');
            if ($key === '|' || isset($items[$key])) {
                continue;
            }

            $items[$key] = $payload;
        }

        return array_values($items);
    }

    public static function createQueue(int $templateId, array $invoices, string $name = '', ?string $scheduledAt = null, ?string $snapshot = null, ?int &$createdBatchId = null): int
    {
        Schema::ensure();
        if (!$invoices) {
            throw new RuntimeException('Selecciona al menos una factura.');
        }

        $firstInvoice = $invoices[0];
        $branchId = BranchRepository::normalizeBranchIdFromRows($invoices);
        if ($branchId <= 0) {
            throw new RuntimeException('Selecciona una sucursal antes de crear el envio.');
        }
        $dueDate = trim((string) ($firstInvoice['due_date'] ?? ''));
        $dueDateLabel = trim((string) ($firstInvoice['due_date_label'] ?? ''));
        $name = trim($name);
        if ($name === '') {
            $name = 'Facturas';
            if ($dueDateLabel !== '') {
                $name .= ' vto ' . $dueDateLabel;
            }
            $name .= ' - ' . date('d/m/Y H:i');
        }

        $snapshot = $snapshot ?? MessageSnapshot::capture(self::findTemplate($templateId) ?? [], false);
        $isTest = !empty(json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR)['test_mode']);
        $pdo = Database::pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();

        try {
            $batchStmt = $pdo->prepare(
                'INSERT INTO dbo.SendMail_InvoiceBatches
                 (branch_id, template_id, name, due_date, due_date_label, status, total_queued, total_sent, total_failed)
                 OUTPUT INSERTED.id
                 VALUES
                 (:branch_id, :template_id, :name, CONVERT(date, NULLIF(:due_date, \'\'), 23), :due_date_label, :status, :total_queued, 0, 0)'
            );
            $batchStmt->execute([
                ':branch_id' => $branchId,
                ':template_id' => $templateId,
                ':name' => $name,
                ':due_date' => $dueDate,
                ':due_date_label' => $dueDateLabel,
                ':status' => 'queued',
                ':total_queued' => count($invoices),
            ]);
            $batchId = (int) $batchStmt->fetchColumn();
            $createdBatchId = $batchId;
            $snapshotStmt = $pdo->prepare('UPDATE dbo.SendMail_InvoiceBatches SET message_snapshot = :snapshot WHERE id = :id');
            $snapshotStmt->execute([':snapshot' => $snapshot, ':id' => $batchId]);

            $queueRows = [];

            $count = 0;
            foreach ($invoices as $invoice) {
                $queueRows[] = [
                    'branch_id' => $branchId,
                    'batch_id' => $batchId,
                    'template_id' => $templateId,
                    'invoice_id' => (string) ($invoice['invoice_id'] ?? ''),
                    'snb' => (string) ($invoice['snb'] ?? ''),
                    'client_name' => (string) ($invoice['client_name'] ?? ''),
                    'email_to' => (string) ($invoice['email'] ?? ''),
                    'is_test' => $isTest ? 1 : 0,
                    'amount' => (string) ($invoice['amount'] ?? '0'),
                    'due_date' => trim((string) ($invoice['due_date'] ?? '')) ?: null,
                    'due_date_label' => (string) ($invoice['due_date_label'] ?? ''),
                    'invoice_url' => (string) ($invoice['invoice_url'] ?? ''),
                    'context_json' => json_encode($invoice, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'status' => 'pending',
                    'scheduled_at' => $scheduledAt ?? date('Y-m-d H:i:s'),
                ];
                $count++;
            }

            BulkInsert::rows($pdo, 'SendMail_InvoiceQueue', $queueRows);
            if ($ownsTransaction) $pdo->commit();
            return $count;
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function pending(int $limit, bool $metadataOnly = false): array
    {
        Schema::ensure();
        $columns = $metadataOnly ? 'q.id, q.scheduled_at, q.branch_id' : 'q.*, b.message_snapshot';
        $limit = max(1, min($limit, 10000));
        [$branchWhere, $branchParams] = BranchRepository::activeBranchWhere('q');
        $branchSql = $branchWhere !== '' ? ' AND ' . $branchWhere : '';
        $sql = "SELECT TOP $limit $columns
                FROM dbo.SendMail_InvoiceQueue q
                INNER JOIN dbo.SendMail_InvoiceTemplates t ON t.id = q.template_id
                LEFT JOIN dbo.SendMail_InvoiceBatches b ON b.id = q.batch_id
                WHERE q.status = 'pending'
                  AND q.scheduled_at <= SYSDATETIME()
                  $branchSql
                  AND (q.batch_id IS NULL OR b.status IN ('queued', 'processing'))
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
             FROM dbo.SendMail_InvoiceQueue q
             LEFT JOIN dbo.SendMail_InvoiceBatches b ON b.id = q.batch_id
             WHERE q.status = 'pending'
               AND q.scheduled_at <= SYSDATETIME()
               $branchSql
               AND (q.batch_id IS NULL OR b.status IN ('queued', 'processing'))"
        );
        $stmt->execute($branchParams);
        return (int) $stmt->fetchColumn();
    }

    public static function markSending(int $id): bool
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE q
             SET status = 'sending', attempts = attempts + 1
             FROM dbo.SendMail_InvoiceQueue q
             LEFT JOIN dbo.SendMail_InvoiceBatches b ON b.id = q.batch_id
             WHERE q.id = :id
               AND q.status = 'pending'
               AND (q.batch_id IS NULL OR b.status IN ('queued', 'processing'))"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public static function markSent(int $id, string $invoiceId, ?int $branchId = null, bool $isTest = false): void
    {
        $stmt = Database::pdo()->prepare("UPDATE dbo.SendMail_InvoiceQueue SET status = 'sent', is_test = :is_test, sent_at = SYSDATETIME(), last_error = NULL WHERE id = :id");
        $stmt->execute([':id' => $id, ':is_test' => $isTest ? 1 : 0]);
        if (!$isTest) self::markInvoiceSent($invoiceId, $branchId);
    }

    public static function markFailed(int $id, string $error): void
    {
        $stmt = Database::pdo()->prepare("UPDATE dbo.SendMail_InvoiceQueue SET status = 'failed', last_error = :error WHERE id = :id");
        $stmt->execute([':id' => $id, ':error' => $error]);
    }

    public static function markNeedsReview(int $id, string $error): void
    {
        $stmt = Database::pdo()->prepare("UPDATE dbo.SendMail_InvoiceQueue SET status='sending', last_error=:error WHERE id=:id");
        $stmt->execute([':id'=>$id, ':error'=>'El proveedor acepto el email; revisar la factura y su registro antes de reenviar. ' . $error]);
    }

    public static function updateInvoiceBatchTotals(int $batchId): void
    {
        if ($batchId <= 0) {
            return;
        }

        $stmt = Database::pdo()->prepare(
            "UPDATE b
             SET total_sent = x.total_sent,
                 total_failed = x.total_failed,
                 status = CASE
                    WHEN b.status IN ('paused', 'stopped') THEN b.status
                    WHEN x.total_pending = 0 AND x.total_sending = 0 THEN 'completed'
                    WHEN x.total_sent > 0 OR x.total_failed > 0 OR x.total_sending > 0 OR x.total_skipped > 0 THEN 'processing'
                    ELSE b.status
                 END,
                 started_at = CASE WHEN b.started_at IS NULL AND (x.total_sent > 0 OR x.total_failed > 0 OR x.total_sending > 0 OR x.total_skipped > 0) THEN SYSDATETIME() ELSE b.started_at END,
                 completed_at = CASE
                    WHEN b.status = 'stopped' THEN COALESCE(b.completed_at, SYSDATETIME())
                    WHEN b.status <> 'paused' AND x.total_pending = 0 AND x.total_sending = 0 THEN SYSDATETIME()
                    ELSE b.completed_at
                 END
             FROM dbo.SendMail_InvoiceBatches b
             CROSS APPLY (
                SELECT
                    COALESCE(SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END), 0) AS total_sent,
                    COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) AS total_failed,
                    COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS total_pending,
                    COALESCE(SUM(CASE WHEN status = 'sending' THEN 1 ELSE 0 END), 0) AS total_sending,
                    COALESCE(SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END), 0) AS total_skipped
                FROM dbo.SendMail_InvoiceQueue
                WHERE batch_id = b.id
             ) x
             WHERE b.id = :batch_id"
        );
        $stmt->execute([':batch_id' => $batchId]);
    }

    public static function insertLog(array $data): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO dbo.SendMail_InvoiceLog
             (branch_id, queue_id, template_id, invoice_id, snb, client_name, email_to, email_subject, status, smtp_host, error_message, provider_response, html_snapshot, context_json, duration_ms)
             VALUES
             (:branch_id, :queue_id, :template_id, :invoice_id, :snb, :client_name, :email_to, :email_subject, :status, :smtp_host, :error_message, :provider_response, :html_snapshot, :context_json, :duration_ms)'
        );
        $stmt->execute([
            ':branch_id' => $data['branch_id'] ?? BranchRepository::currentId(),
            ':queue_id' => $data['queue_id'] ?? null,
            ':template_id' => $data['template_id'] ?? null,
            ':invoice_id' => $data['invoice_id'] ?? null,
            ':snb' => $data['snb'] ?? null,
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

    public static function queueItems(string $status = '', int $limit = 150): array
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
                q.template_id,
                q.invoice_id,
                q.snb,
                q.client_name,
                q.email_to,
                q.amount,
                q.due_date,
                q.due_date_label,
                q.invoice_url,
                q.status,
                q.attempts,
                q.scheduled_at,
                q.sent_at,
                q.last_error,
                q.created_at,
                t.name AS template_name,
                b.name AS batch_name
             FROM dbo.SendMail_InvoiceQueue q
             INNER JOIN dbo.SendMail_InvoiceTemplates t ON t.id = q.template_id
             LEFT JOIN dbo.SendMail_InvoiceBatches b ON b.id = q.batch_id
             $whereSql
             ORDER BY q.created_at DESC, q.id DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function invoiceBatchStatusCounts(): array
    {
        Schema::ensure();
        $counts = [
            self::BATCH_STATUS_QUEUED => 0,
            self::BATCH_STATUS_PROCESSING => 0,
            self::BATCH_STATUS_PAUSED => 0,
            self::BATCH_STATUS_STOPPED => 0,
            self::BATCH_STATUS_COMPLETED => 0,
        ];

        [$branchWhere, $branchParams] = BranchRepository::activeBranchWhere();
        $whereSql = $branchWhere !== '' ? 'WHERE ' . $branchWhere : '';
        $stmt = Database::pdo()->prepare("SELECT status, COUNT(*) AS total FROM dbo.SendMail_InvoiceBatches $whereSql GROUP BY status");
        $stmt->execute($branchParams);
        foreach ($stmt->fetchAll() as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    public static function invoiceBatches(string $status = '', int $limit = 50): array
    {
        Schema::ensure();
        $limit = max(1, min($limit, 200));
        $params = [];
        $where = [];
        if ($status !== '') {
            $where[] = 'b.status = :status';
            $params[':status'] = $status;
        }
        [$branchWhere, $branchParams] = BranchRepository::activeBranchWhere('b');
        if ($branchWhere !== '') {
            $where[] = $branchWhere;
            $params += $branchParams;
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = Database::pdo()->prepare(
            "SELECT TOP $limit
                b.id,
                b.name,
                b.due_date,
                b.due_date_label,
                b.status,
                b.total_queued,
                b.total_sent,
                b.total_failed,
                b.created_at,
                b.started_at,
                b.completed_at,
                t.name AS template_name,
                MIN(q.scheduled_at) AS first_scheduled_at,
                MAX(q.scheduled_at) AS last_scheduled_at,
                COUNT(q.id) AS queue_total,
                COALESCE(SUM(CASE WHEN q.status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_count,
                COALESCE(SUM(CASE WHEN q.status = 'sending' THEN 1 ELSE 0 END), 0) AS sending_count,
                COALESCE(SUM(CASE WHEN q.status = 'sent' THEN 1 ELSE 0 END), 0) AS sent_count,
                COALESCE(SUM(CASE WHEN q.status = 'failed' THEN 1 ELSE 0 END), 0) AS failed_count,
                COALESCE(SUM(CASE WHEN q.status = 'skipped' THEN 1 ELSE 0 END), 0) AS skipped_count
             FROM dbo.SendMail_InvoiceBatches b
             INNER JOIN dbo.SendMail_InvoiceTemplates t ON t.id = b.template_id
             LEFT JOIN dbo.SendMail_InvoiceQueue q ON q.batch_id = b.id
             $whereSql
             GROUP BY
                b.id,
                b.name,
                b.due_date,
                b.due_date_label,
                b.status,
                b.total_queued,
                b.total_sent,
                b.total_failed,
                b.created_at,
                b.started_at,
                b.completed_at,
                t.name
             ORDER BY b.created_at DESC, b.id DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function pauseInvoiceBatch(int $batchId): void
    {
        self::changeInvoiceBatchStatus(
            $batchId,
            [self::BATCH_STATUS_QUEUED, self::BATCH_STATUS_PROCESSING],
            self::BATCH_STATUS_PAUSED,
            'Solo se pueden pausar envios de facturas en cola o en proceso.'
        );
    }

    public static function resumeInvoiceBatch(int $batchId): void
    {
        Schema::ensure();
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $batch = self::lockInvoiceBatch($batchId);
            if (!$batch) {
                throw new RuntimeException('Envio de facturas no encontrado.');
            }
            if ((string) $batch['status'] !== self::BATCH_STATUS_PAUSED) {
                throw new RuntimeException('Solo se pueden reanudar envios de facturas pausados.');
            }

            $hasProgressStmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM dbo.SendMail_InvoiceQueue
                 WHERE batch_id = :batch_id
                   AND status IN ('sent', 'failed', 'sending', 'skipped')"
            );
            $hasProgressStmt->execute([':batch_id' => $batchId]);
            $nextStatus = (int) $hasProgressStmt->fetchColumn() > 0
                ? self::BATCH_STATUS_PROCESSING
                : self::BATCH_STATUS_QUEUED;

            $stmt = $pdo->prepare(
                "UPDATE dbo.SendMail_InvoiceBatches
                 SET status = :status, completed_at = NULL
                 WHERE id = :batch_id"
            );
            $stmt->execute([
                ':status' => $nextStatus,
                ':batch_id' => $batchId,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function stopInvoiceBatch(int $batchId): void
    {
        Schema::ensure();
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $batch = self::lockInvoiceBatch($batchId);
            if (!$batch) {
                throw new RuntimeException('Envio de facturas no encontrado.');
            }

            $status = (string) $batch['status'];
            if (!in_array($status, [self::BATCH_STATUS_QUEUED, self::BATCH_STATUS_PROCESSING, self::BATCH_STATUS_PAUSED], true)) {
                throw new RuntimeException('Solo se pueden detener envios de facturas en cola, en proceso o pausados.');
            }

            $queueStmt = $pdo->prepare(
                "UPDATE dbo.SendMail_InvoiceQueue
                 SET status = 'skipped', last_error = 'Envio de facturas detenido.'
                 WHERE batch_id = :batch_id
                   AND status = 'pending'"
            );
            $queueStmt->execute([':batch_id' => $batchId]);

            $stmt = $pdo->prepare(
                "UPDATE dbo.SendMail_InvoiceBatches
                 SET status = 'stopped',
                     completed_at = SYSDATETIME()
                 WHERE id = :batch_id"
            );
            $stmt->execute([':batch_id' => $batchId]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function deleteQueuedInvoiceBatch(int $batchId): void
    {
        Schema::ensure();
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $batch = self::lockInvoiceBatch($batchId);
            if (!$batch) {
                throw new RuntimeException('Envio de facturas no encontrado.');
            }
            if ((string) $batch['status'] !== self::BATCH_STATUS_QUEUED) {
                throw new RuntimeException('Solo se pueden eliminar envios de facturas en estado queued.');
            }

            $nonPendingStmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM dbo.SendMail_InvoiceQueue
                 WHERE batch_id = :batch_id
                   AND status <> 'pending'"
            );
            $nonPendingStmt->execute([':batch_id' => $batchId]);
            if ((int) $nonPendingStmt->fetchColumn() > 0) {
                throw new RuntimeException('El envio ya tiene movimientos y no se puede eliminar.');
            }

            $stmt = $pdo->prepare(
                'DELETE l
                 FROM dbo.SendMail_InvoiceLog l
                 INNER JOIN dbo.SendMail_InvoiceQueue q ON q.id = l.queue_id
                 WHERE q.batch_id = :batch_id'
            );
            $stmt->execute([':batch_id' => $batchId]);

            $stmt = $pdo->prepare('DELETE FROM dbo.SendMail_InvoiceQueue WHERE batch_id = :batch_id');
            $stmt->execute([':batch_id' => $batchId]);

            $stmt = $pdo->prepare('DELETE FROM dbo.SendMail_InvoiceBatches WHERE id = :batch_id AND status = :status');
            $stmt->execute([
                ':batch_id' => $batchId,
                ':status' => self::BATCH_STATUS_QUEUED,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('No se pudo eliminar el envio de facturas.');
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function formatMoney($amount): string
    {
        return '$ ' . number_format((float) $amount, 2, ',', '.');
    }

    private static function changeInvoiceBatchStatus(int $batchId, array $allowedStatuses, string $newStatus, string $errorMessage): void
    {
        Schema::ensure();
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $batch = self::lockInvoiceBatch($batchId);
            if (!$batch) {
                throw new RuntimeException('Envio de facturas no encontrado.');
            }
            if (!in_array((string) $batch['status'], $allowedStatuses, true)) {
                throw new RuntimeException($errorMessage);
            }

            $stmt = $pdo->prepare(
                'UPDATE dbo.SendMail_InvoiceBatches SET status = :status WHERE id = :batch_id'
            );
            $stmt->execute([
                ':status' => $newStatus,
                ':batch_id' => $batchId,
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function lockInvoiceBatch(int $batchId): ?array
    {
        [$branchWhere, $branchParams] = BranchRepository::activeBranchWhere();
        $branchSql = $branchWhere !== '' ? ' AND ' . $branchWhere : '';
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM dbo.SendMail_InvoiceBatches WITH (UPDLOCK, HOLDLOCK) WHERE id = :batch_id $branchSql"
        );
        $stmt->execute([':batch_id' => $batchId] + $branchParams);
        $batch = $stmt->fetch();
        return is_array($batch) ? $batch : null;
    }

    private static function normalizeTemplate(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $subject = trim((string) ($data['subject'] ?? ''));
        $html = (string) ($data['html_body'] ?? '');
        $web = trim((string) ($data['web'] ?? ''));
        $locPrefix = trim((string) ($data['loc_prefix'] ?? ''));

        if ($name === '' || $subject === '' || trim($html) === '' || $web === '' || $locPrefix === '') {
            throw new InvalidArgumentException('Nombre, asunto, HTML, web y prefijo de localidad son obligatorios.');
        }

        foreach (['from_email', 'reply_to', 'bcc', 'test_email'] as $emailKey) {
            $email = trim((string) ($data[$emailKey] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('El email ' . $email . ' no es valido.');
            }
        }

        if (!empty($data['test_mode']) && trim((string) ($data['test_email'] ?? '')) === '') {
            throw new InvalidArgumentException('El modo test requiere un email de prueba.');
        }

        return [
            ':name' => $name,
            ':subject' => $subject,
            ':html_body' => $html,
            ':from_email' => trim((string) ($data['from_email'] ?? '')),
            ':from_name' => trim((string) ($data['from_name'] ?? '')),
            ':reply_to' => trim((string) ($data['reply_to'] ?? '')),
            ':bcc' => trim((string) ($data['bcc'] ?? '')),
            ':web' => $web,
            ':loc_prefix' => $locPrefix,
            ':domicilio' => trim((string) ($data['domicilio'] ?? '')),
            ':facebook' => trim((string) ($data['facebook'] ?? '')),
            ':instagram' => trim((string) ($data['instagram'] ?? '')),
            ':whatsapp' => trim((string) ($data['whatsapp'] ?? '')),
            ':test_mode' => !empty($data['test_mode']) ? 1 : 0,
            ':test_email' => trim((string) ($data['test_email'] ?? '')),
            ':is_active' => !empty($data['is_active']) ? 1 : 0,
        ];
    }

    private static function normalizeInvoiceRow(array $row, array $template): array
    {
        $invoiceId = trim((string) ($row['invoice_id'] ?? ''));
        $web = (string) ($template['web'] ?? '');
        $locPrefix = (string) ($template['loc_prefix'] ?? '');
        $url = InvoiceCrypto::invoiceUrl($web, $locPrefix, $invoiceId);

        return [
            'branch_id' => (int) ($row['branch_id'] ?? 0),
            'invoice_id' => $invoiceId,
            'email' => trim((string) ($row['email'] ?? '')),
            'telefono_movil' => trim((string) ($row['telefono_movil'] ?? '')),
            'amount' => (string) ($row['amount'] ?? '0'),
            'amount_label' => self::formatMoney($row['amount'] ?? 0),
            'client_name' => repair_mojibake(trim((string) ($row['client_name'] ?? ''))),
            'snb' => trim((string) ($row['snb'] ?? '')),
            'due_date' => trim((string) ($row['due_date'] ?? '')),
            'due_date_label' => trim((string) ($row['due_date_label'] ?? '')),
            'status' => trim((string) ($row['status'] ?? '')),
            'mail_sent' => (int) ($row['mail_sent'] ?? 0),
            'sent_label' => trim((string) ($row['sent_label'] ?? '')),
            'invoice_url' => $url,
        ];
    }

    private static function selectionToken(array $invoice, int $index): string
    {
        $payload = [
            'branch_id' => (int) ($invoice['branch_id'] ?? BranchRepository::currentId() ?? 0),
            'invoice_id' => (string) ($invoice['invoice_id'] ?? ''),
            'email' => (string) ($invoice['email'] ?? ''),
            'telefono_movil' => (string) ($invoice['telefono_movil'] ?? ''),
            'amount' => (string) ($invoice['amount'] ?? '0'),
            'amount_label' => (string) ($invoice['amount_label'] ?? ''),
            'client_name' => (string) ($invoice['client_name'] ?? ''),
            'snb' => (string) ($invoice['snb'] ?? ''),
            'due_date' => (string) ($invoice['due_date'] ?? ''),
            'due_date_label' => (string) ($invoice['due_date_label'] ?? ''),
            'invoice_url' => (string) ($invoice['invoice_url'] ?? ''),
            'index' => $index,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('No se pudo preparar la seleccion de factura.');
        }

        $encoded = self::base64UrlEncode($json);
        return $encoded . '.' . hash_hmac('sha256', $encoded, csrf_token());
    }

    private static function normalizeDeliveryChannel(string $channel): string
    {
        return in_array($channel, ['email', 'whatsapp', 'both'], true) ? $channel : 'email';
    }

    private static function invoicePhoneExpression(): string
    {
        static $expressions = [];
        $branchId = (int) (BranchRepository::currentId() ?? 0);
        if (isset($expressions[$branchId])) {
            return $expressions[$branchId];
        }

        $stmt = self::sourcePdo()->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'clientes' AND TABLE_SCHEMA = 'dbo'"
        );
        $stmt->execute();
        $lookup = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
            $lookup[strtolower((string) $column)] = (string) $column;
        }
        foreach (['telefono_movil', 'telefonomovil', 'telmovil', 'movil', 'celular', 'telefono'] as $candidate) {
            if (isset($lookup[$candidate])) {
                $column = '[' . str_replace(']', ']]', $lookup[$candidate]) . ']';
                return $expressions[$branchId] = "LTRIM(RTRIM(CONVERT(nvarchar(80), C.$column)))";
            }
        }
        return $expressions[$branchId] = "''";
    }

    private static function markInvoiceSent(string $invoiceId, ?int $branchId = null): void
    {
        $stmt = self::sourcePdo($branchId)->prepare(
            "UPDATE FacturasTel
             SET mail_enviado = 1,
                 fecha_mail_enviado = SYSDATETIME()
             WHERE estado <> 'A' AND CONVERT(nvarchar(80), Id) = :invoice_id"
        );
        $stmt->execute([':invoice_id' => $invoiceId]);
    }

    private static function sourcePdo(?int $branchId = null): PDO
    {
        return BranchRepository::pdo($branchId);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value)
    {
        $base64 = strtr($value, '-_', '+/');
        $padding = strlen($base64) % 4;
        if ($padding > 0) {
            $base64 .= str_repeat('=', 4 - $padding);
        }

        return base64_decode($base64, true);
    }
}
