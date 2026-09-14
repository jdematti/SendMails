<?php

declare(strict_types=1);

final class WhatsAppRepository
{
    public const SOURCE_CAMPAIGN = 'campaign';
    public const SOURCE_INVOICE = 'invoice';

    public static function templates(string $sourceType = ''): array
    {
        Schema::ensure();
        [$branchWhere, $branchParams] = BranchRepository::activeBranchWhere('t');
        $where = $branchWhere !== '' ? [$branchWhere] : [];
        $params = $branchParams;
        if ($sourceType !== '') {
            self::assertSourceType($sourceType);
            $where[] = 't.category = :category';
            $params[':category'] = self::categoryForSource($sourceType);
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = Database::pdo()->prepare(
            "SELECT t.* FROM dbo.SendMail_WhatsAppTemplates t $whereSql ORDER BY t.status, t.name, t.language"
        );
        $stmt->execute($params);
        return array_map([self::class, 'normalizeTemplateRow'], $stmt->fetchAll());
    }

    public static function readyTemplates(string $sourceType): array
    {
        return array_values(array_filter(self::templates($sourceType), static function (array $template): bool {
            return self::isTemplateReady($template);
        }));
    }

    public static function findTemplate(int $id): ?array
    {
        Schema::ensure();
        [$branchWhere, $branchParams] = BranchRepository::activeBranchWhere('t');
        $branchSql = $branchWhere !== '' ? ' AND ' . $branchWhere : '';
        $stmt = Database::pdo()->prepare("SELECT t.* FROM dbo.SendMail_WhatsAppTemplates t WHERE t.id = :id $branchSql");
        $stmt->execute([':id' => $id] + $branchParams);
        $row = $stmt->fetch();
        return is_array($row) ? self::normalizeTemplateRow($row) : null;
    }

    public static function syncTemplates(?int $branchId = null): int
    {
        Schema::ensure();
        $branchId = $branchId !== null && $branchId > 0 ? $branchId : (int) (BranchRepository::currentId() ?? 0);
        if ($branchId <= 0) {
            throw new RuntimeException('Selecciona una sucursal para sincronizar plantillas.');
        }
        $config = Settings::whatsapp($branchId);
        $remoteTemplates = WhatsAppBusinessService::fetchTemplates($config);
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            "MERGE dbo.SendMail_WhatsAppTemplates AS target
             USING (SELECT :branch_id AS branch_id, :meta_template_id AS meta_template_id) AS source
             ON target.branch_id = source.branch_id AND target.meta_template_id = source.meta_template_id
             WHEN MATCHED THEN UPDATE SET
                name = :name,
                language = :language,
                category = :category,
                status = :status,
                components_json = :components_json,
                synced_at = SYSDATETIME(),
                updated_at = SYSDATETIME()
             WHEN NOT MATCHED THEN INSERT
                (branch_id, meta_template_id, name, language, category, status, components_json, body_variables_json, is_active)
                VALUES (:branch_id, :meta_template_id, :name, :language, :category, :status, :components_json, :body_variables_json, 1);"
        );

        $count = 0;
        foreach ($remoteTemplates as $remote) {
            $metaId = trim((string) ($remote['id'] ?? ''));
            $name = trim((string) ($remote['name'] ?? ''));
            $language = trim((string) ($remote['language'] ?? ''));
            if ($metaId === '' || $name === '' || $language === '') {
                continue;
            }
            $components = is_array($remote['components'] ?? null) ? $remote['components'] : [];
            $componentJson = json_encode($components, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $temporary = ['components_json' => is_string($componentJson) ? $componentJson : '[]'];
            $parameterCount = self::bodyParameterCount($temporary);
            $category = strtoupper(trim((string) ($remote['category'] ?? '')));
            if ($category === 'UTILITY') {
                $utilityDefaults = [
                    0 => [],
                    1 => ['invoice_url'],
                    2 => ['client_name', 'invoice_url'],
                    3 => ['client_name', 'amount_label', 'invoice_url'],
                    4 => ['client_name', 'amount_label', 'due_date_label', 'invoice_url'],
                ];
                $defaults = $utilityDefaults[$parameterCount]
                    ?? ['client_name', 'snb', 'amount_label', 'due_date_label', 'invoice_url'];
            } else {
                $defaults = ['razon_social', 'codigo_cliente', 'plan_contratado', 'telefono_movil', 'localidad', 'provincia'];
            }
            $variableJson = json_encode(array_slice($defaults, 0, $parameterCount), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $stmt->execute([
                ':branch_id' => $branchId,
                ':meta_template_id' => $metaId,
                ':name' => $name,
                ':language' => $language,
                ':category' => $category,
                ':status' => strtoupper(trim((string) ($remote['status'] ?? 'UNKNOWN'))),
                ':components_json' => is_string($componentJson) ? $componentJson : '[]',
                ':body_variables_json' => is_string($variableJson) ? $variableJson : '[]',
            ]);
            $count++;
        }
        return $count;
    }

    public static function updateTemplateMapping(int $id, array $variables, bool $isActive): void
    {
        $template = self::findTemplate($id);
        if (!$template) {
            throw new RuntimeException('Plantilla de WhatsApp no encontrada.');
        }
        $allowed = [
            'codigo_cliente', 'razon_social', 'plan_contratado', 'email', 'telefono_movil', 'localidad', 'provincia',
            'client_name', 'amount', 'amount_label', 'snb', 'due_date', 'due_date_label', 'invoice_id', 'invoice_url',
        ];
        $variables = array_values(array_filter(array_map(static function ($value): string {
            return trim(is_scalar($value) ? (string) $value : '');
        }, $variables), static fn (string $value): bool => $value !== ''));
        foreach ($variables as $variable) {
            if (!in_array($variable, $allowed, true)) {
                throw new InvalidArgumentException('Variable de WhatsApp no permitida: ' . $variable);
            }
        }
        $expected = self::bodyParameterCount($template);
        if (count($variables) !== $expected) {
            throw new InvalidArgumentException('La plantilla requiere exactamente ' . $expected . ' variables de cuerpo.');
        }
        $json = json_encode($variables, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = Database::pdo()->prepare(
            'UPDATE dbo.SendMail_WhatsAppTemplates
             SET body_variables_json = :variables, is_active = :is_active, updated_at = SYSDATETIME()
             WHERE id = :id AND branch_id = :branch_id'
        );
        $stmt->execute([
            ':variables' => $json,
            ':is_active' => $isActive ? 1 : 0,
            ':id' => $id,
            ':branch_id' => (int) (BranchRepository::currentId() ?? 0),
        ]);
    }

    public static function bodyParameterCount(array $template): int
    {
        $components = json_decode((string) ($template['components_json'] ?? '[]'), true);
        if (!is_array($components)) {
            return 0;
        }
        foreach ($components as $component) {
            if (!is_array($component) || strtoupper((string) ($component['type'] ?? '')) !== 'BODY') {
                continue;
            }
            $text = (string) ($component['text'] ?? '');
            preg_match_all('/\{\{\s*[^}]+\s*\}\}/', $text, $matches);
            return count(array_unique($matches[0] ?? []));
        }
        return 0;
    }

    public static function templateVariables(array $template): array
    {
        $variables = json_decode((string) ($template['body_variables_json'] ?? '[]'), true);
        if (!is_array($variables)) {
            return [];
        }
        return array_values(array_filter(array_map('strval', $variables), static fn (string $value): bool => $value !== ''));
    }

    public static function isTemplateReady(array $template): bool
    {
        return strtoupper((string) ($template['status'] ?? '')) === 'APPROVED'
            && !empty($template['is_active'])
            && self::bodyParameterCount($template) === count(self::templateVariables($template));
    }

    public static function createBatch(string $sourceType, int $templateId, string $name, array $rows, ?string $scheduledAt = null): array
    {
        Schema::ensure();
        self::assertSourceType($sourceType);
        if (!$rows) {
            throw new RuntimeException('Selecciona al menos un destinatario.');
        }
        $template = self::findTemplate($templateId);
        if (!$template || !self::isTemplateReady($template) || (string) $template['category'] !== self::categoryForSource($sourceType)) {
            throw new RuntimeException('Selecciona una plantilla de WhatsApp aprobada y configurada para este envio.');
        }
        $branchId = BranchRepository::normalizeBranchIdFromRows($rows);
        if ($branchId <= 0) {
            throw new RuntimeException('Selecciona una sucursal antes de crear el envio de WhatsApp.');
        }
        $config = Settings::whatsapp($branchId);
        WhatsAppBusinessService::assertConfigured($config);

        $prepared = [];
        $invalid = 0;
        $duplicates = 0;
        $optedOut = 0;
        $seen = [];
        foreach ($rows as $row) {
            $raw = trim((string) ($row['telefono_movil'] ?? $row['phone'] ?? ''));
            $phone = WhatsAppPhone::normalize($raw, (string) ($config['country_code'] ?? '54'));
            if ($phone === null) {
                $invalid++;
                continue;
            }
            if ($sourceType === self::SOURCE_CAMPAIGN && self::isOptedOut($branchId, $phone, self::SOURCE_CAMPAIGN)) {
                $optedOut++;
                continue;
            }
            $sourceRecordId = trim((string) ($row['invoice_id'] ?? ''));
            $dedupeKey = $sourceType === self::SOURCE_INVOICE ? $sourceRecordId . '|' . $phone : $phone;
            if ($dedupeKey === '|' . $phone || isset($seen[$dedupeKey])) {
                $duplicates++;
                continue;
            }
            $seen[$dedupeKey] = true;
            $row['branch_id'] = $branchId;
            $row['phone_to'] = $phone;
            $prepared[] = ['row' => $row, 'raw' => $raw, 'phone' => $phone, 'source_record_id' => $sourceRecordId];
        }
        if (!$prepared) {
            throw new RuntimeException('No hay destinatarios con numero de WhatsApp valido para encolar.');
        }

        $name = trim($name);
        if ($name === '') {
            $name = ($sourceType === self::SOURCE_INVOICE ? 'Facturas WhatsApp' : 'Campana WhatsApp') . ' - ' . date('d/m/Y H:i');
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $batchStmt = $pdo->prepare(
                'INSERT INTO dbo.SendMail_WhatsAppBatches
                 (branch_id, source_type, template_id, name, status, total_queued, total_sent, total_failed, total_delivered, total_read)
                 OUTPUT INSERTED.id
                 VALUES (:branch_id, :source_type, :template_id, :name, :status, :total_queued, 0, 0, 0, 0)'
            );
            $batchStmt->execute([
                ':branch_id' => $branchId,
                ':source_type' => $sourceType,
                ':template_id' => $templateId,
                ':name' => $name,
                ':status' => 'queued',
                ':total_queued' => count($prepared),
            ]);
            $batchId = (int) $batchStmt->fetchColumn();
            $queueStmt = $pdo->prepare(
                'INSERT INTO dbo.SendMail_WhatsAppQueue
                 (branch_id, batch_id, template_id, client_oid, client_code, client_name, source_record_id, phone_raw, phone_to, context_json, status, attempts, scheduled_at)
                 VALUES
                 (:branch_id, :batch_id, :template_id, :client_oid, :client_code, :client_name, :source_record_id, :phone_raw, :phone_to, :context_json, :status, 0, COALESCE(CONVERT(datetime2(0), NULLIF(:scheduled_at, \'\'), 120), SYSDATETIME()))'
            );
            foreach ($prepared as $item) {
                $row = $item['row'];
                $queueStmt->execute([
                    ':branch_id' => $branchId,
                    ':batch_id' => $batchId,
                    ':template_id' => $templateId,
                    ':client_oid' => trim((string) ($row['oid'] ?? '')),
                    ':client_code' => trim((string) ($row['codigo_cliente'] ?? '')),
                    ':client_name' => trim((string) ($row['razon_social'] ?? $row['client_name'] ?? '')),
                    ':source_record_id' => $item['source_record_id'],
                    ':phone_raw' => $item['raw'],
                    ':phone_to' => $item['phone'],
                    ':context_json' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':status' => 'pending',
                    ':scheduled_at' => (string) ($scheduledAt ?? ''),
                ]);
            }
            $pdo->commit();
            return [
                'batch_id' => $batchId,
                'queued' => count($prepared),
                'invalid' => $invalid,
                'duplicates' => $duplicates,
                'opted_out' => $optedOut,
            ];
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function pending(string $sourceType = '', int $limit = 10): array
    {
        Schema::ensure();
        $limit = max(1, min($limit, 1000));
        $where = ["q.status = 'pending'", 'q.scheduled_at <= SYSDATETIME()', "b.status IN ('queued', 'processing')"];
        $params = [];
        if ($sourceType !== '') {
            self::assertSourceType($sourceType);
            $where[] = 'b.source_type = :source_type';
            $params[':source_type'] = $sourceType;
        }
        [$branchWhere, $branchParams] = BranchRepository::activeBranchWhere('q');
        if ($branchWhere !== '') {
            $where[] = $branchWhere;
            $params += $branchParams;
        }
        $stmt = Database::pdo()->prepare(
            "SELECT TOP $limit q.*, b.source_type, b.name AS batch_name, t.name AS template_name,
                    t.language, t.category, t.status AS template_status, t.components_json, t.body_variables_json
             FROM dbo.SendMail_WhatsAppQueue q
             INNER JOIN dbo.SendMail_WhatsAppBatches b ON b.id = q.batch_id AND b.branch_id = q.branch_id
             INNER JOIN dbo.SendMail_WhatsAppTemplates t ON t.id = q.template_id AND t.branch_id = q.branch_id
             WHERE " . implode(' AND ', $where) . '
             ORDER BY q.scheduled_at, q.id'
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function pendingCount(string $sourceType = ''): int
    {
        $where = ["q.status = 'pending'", 'q.scheduled_at <= SYSDATETIME()', "b.status IN ('queued', 'processing')"];
        $params = [];
        if ($sourceType !== '') {
            self::assertSourceType($sourceType);
            $where[] = 'b.source_type = :source_type';
            $params[':source_type'] = $sourceType;
        }
        [$branchWhere, $branchParams] = BranchRepository::activeBranchWhere('q');
        if ($branchWhere !== '') {
            $where[] = $branchWhere;
            $params += $branchParams;
        }
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM dbo.SendMail_WhatsAppQueue q
             INNER JOIN dbo.SendMail_WhatsAppBatches b ON b.id = q.batch_id AND b.branch_id = q.branch_id
             WHERE ' . implode(' AND ', $where)
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function markSending(int $id): bool
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE q SET status = 'sending', attempts = attempts + 1
             FROM dbo.SendMail_WhatsAppQueue q
             INNER JOIN dbo.SendMail_WhatsAppBatches b ON b.id = q.batch_id
             WHERE q.id = :id AND q.status = 'pending' AND b.status IN ('queued', 'processing')"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public static function markAccepted(int $id, string $providerMessageId, array $response): void
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE dbo.SendMail_WhatsAppQueue
             SET status = 'accepted', provider_message_id = :provider_message_id,
                 provider_response = :provider_response, accepted_at = SYSDATETIME(), last_error = NULL
             WHERE id = :id"
        );
        $stmt->execute([
            ':provider_message_id' => $providerMessageId,
            ':provider_response' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':id' => $id,
        ]);
    }

    public static function markFailed(int $id, string $error): void
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE dbo.SendMail_WhatsAppQueue SET status = 'failed', failed_at = SYSDATETIME(), last_error = :error WHERE id = :id"
        );
        $stmt->execute([':error' => $error, ':id' => $id]);
    }

    public static function reschedule(int $id, string $error, int $delaySeconds): void
    {
        $delaySeconds = max(5, min($delaySeconds, 3600));
        $stmt = Database::pdo()->prepare(
            "UPDATE dbo.SendMail_WhatsAppQueue
             SET status = 'pending', scheduled_at = DATEADD(SECOND, :delay_seconds, SYSDATETIME()), last_error = :error
             WHERE id = :id"
        );
        $stmt->execute([':delay_seconds' => $delaySeconds, ':error' => $error, ':id' => $id]);
    }

    public static function markSkipped(int $id, string $reason): void
    {
        $stmt = Database::pdo()->prepare("UPDATE dbo.SendMail_WhatsAppQueue SET status = 'skipped', last_error = :reason WHERE id = :id");
        $stmt->execute([':reason' => $reason, ':id' => $id]);
    }

    public static function updateBatchTotals(int $batchId): void
    {
        if ($batchId <= 0) {
            return;
        }
        $stmt = Database::pdo()->prepare(
            "UPDATE b
             SET total_sent = x.total_sent,
                 total_failed = x.total_failed,
                 total_delivered = x.total_delivered,
                 total_read = x.total_read,
                 status = CASE
                    WHEN b.status IN ('paused', 'stopped') THEN b.status
                    WHEN x.total_pending = 0 AND x.total_sending = 0 THEN 'completed'
                    WHEN x.total_sent > 0 OR x.total_failed > 0 OR x.total_sending > 0 THEN 'processing'
                    ELSE b.status END,
                 started_at = CASE WHEN b.started_at IS NULL AND (x.total_sent > 0 OR x.total_failed > 0 OR x.total_sending > 0) THEN SYSDATETIME() ELSE b.started_at END,
                 completed_at = CASE
                    WHEN b.status = 'stopped' THEN COALESCE(b.completed_at, SYSDATETIME())
                    WHEN b.status <> 'paused' AND x.total_pending = 0 AND x.total_sending = 0 THEN COALESCE(b.completed_at, SYSDATETIME())
                    ELSE b.completed_at END
             FROM dbo.SendMail_WhatsAppBatches b
             CROSS APPLY (
                SELECT
                    SUM(CASE WHEN status IN ('accepted', 'sent', 'delivered', 'read') THEN 1 ELSE 0 END) total_sent,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) total_failed,
                    SUM(CASE WHEN status IN ('delivered', 'read') THEN 1 ELSE 0 END) total_delivered,
                    SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) total_read,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) total_pending,
                    SUM(CASE WHEN status = 'sending' THEN 1 ELSE 0 END) total_sending
                FROM dbo.SendMail_WhatsAppQueue WHERE batch_id = b.id
             ) x
             WHERE b.id = :batch_id"
        );
        $stmt->execute([':batch_id' => $batchId]);
    }

    public static function updateProviderStatus(string $providerMessageId, string $status, ?DateTimeImmutable $eventAt, string $error = ''): void
    {
        $status = strtolower(trim($status));
        if (!in_array($status, ['sent', 'delivered', 'read', 'failed'], true) || $providerMessageId === '') {
            return;
        }
        $rowStmt = Database::pdo()->prepare('SELECT id, batch_id, status FROM dbo.SendMail_WhatsAppQueue WHERE provider_message_id = :provider_message_id');
        $rowStmt->execute([':provider_message_id' => $providerMessageId]);
        $row = $rowStmt->fetch();
        if (!is_array($row)) {
            return;
        }
        $current = (string) $row['status'];
        $rank = ['accepted' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4];
        if ($status !== 'failed' && ($rank[$status] ?? 0) < ($rank[$current] ?? 0)) {
            return;
        }
        if ($status === 'failed' && in_array($current, ['delivered', 'read'], true)) {
            return;
        }
        $column = $status === 'failed' ? 'failed_at' : $status . '_at';
        $eventSql = $eventAt ? $eventAt->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');
        $sql = "UPDATE dbo.SendMail_WhatsAppQueue SET status = :status, $column = CONVERT(datetime2(0), :event_at, 120)";
        if ($status === 'failed') {
            $sql .= ', last_error = :error';
        }
        $sql .= ' WHERE id = :id';
        $params = [':status' => $status, ':event_at' => $eventSql, ':id' => (int) $row['id']];
        if ($status === 'failed') {
            $params[':error'] = $error;
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        self::updateBatchTotals((int) $row['batch_id']);
    }

    public static function insertEvent(?int $branchId, string $providerMessageId, string $status, ?DateTimeImmutable $eventAt, string $payload): bool
    {
        $hash = hash('sha256', $providerMessageId . '|' . $status . '|' . ($eventAt ? $eventAt->format(DATE_ATOM) : '') . '|' . $payload);
        $stmt = Database::pdo()->prepare(
            'IF NOT EXISTS (SELECT 1 FROM dbo.SendMail_WhatsAppEvents WHERE event_hash = :event_hash)
             BEGIN
                INSERT INTO dbo.SendMail_WhatsAppEvents
                (branch_id, provider_message_id, event_status, event_at, event_hash, payload_json)
                VALUES (:branch_id, :provider_message_id, :event_status, CONVERT(datetime2(0), NULLIF(:event_at, \'\'), 120), :event_hash, :payload_json)
             END'
        );
        $stmt->execute([
            ':branch_id' => $branchId,
            ':provider_message_id' => $providerMessageId,
            ':event_status' => $status,
            ':event_at' => $eventAt ? $eventAt->format('Y-m-d H:i:s') : '',
            ':event_hash' => $hash,
            ':payload_json' => $payload,
        ]);
        return $stmt->rowCount() > 0;
    }

    public static function recordOptOut(int $branchId, string $phone, string $scope = self::SOURCE_CAMPAIGN, string $reason = ''): void
    {
        $stmt = Database::pdo()->prepare(
            'MERGE dbo.SendMail_WhatsAppOptOuts AS target
             USING (SELECT :branch_id branch_id, :phone_to phone_to, :scope scope) AS source
             ON target.branch_id = source.branch_id AND target.phone_to = source.phone_to AND target.scope = source.scope
             WHEN MATCHED THEN UPDATE SET reason = :reason, created_at = SYSDATETIME()
             WHEN NOT MATCHED THEN INSERT (branch_id, phone_to, scope, reason) VALUES (:branch_id, :phone_to, :scope, :reason);'
        );
        $stmt->execute([':branch_id' => $branchId, ':phone_to' => $phone, ':scope' => $scope, ':reason' => $reason]);
        $skip = Database::pdo()->prepare(
            "UPDATE q SET status = 'skipped', last_error = 'Destinatario dado de baja por WhatsApp.'
             FROM dbo.SendMail_WhatsAppQueue q
             INNER JOIN dbo.SendMail_WhatsAppBatches b ON b.id = q.batch_id
             WHERE q.branch_id = :branch_id AND q.phone_to = :phone_to AND q.status = 'pending' AND b.source_type = :scope"
        );
        $skip->execute([':branch_id' => $branchId, ':phone_to' => $phone, ':scope' => $scope]);
    }

    public static function isOptedOut(int $branchId, string $phone, string $scope = self::SOURCE_CAMPAIGN): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM dbo.SendMail_WhatsAppOptOuts WHERE branch_id = :branch_id AND phone_to = :phone_to AND scope = :scope'
        );
        $stmt->execute([':branch_id' => $branchId, ':phone_to' => $phone, ':scope' => $scope]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public static function queueItems(string $sourceType, string $status = '', int $limit = 100): array
    {
        self::assertSourceType($sourceType);
        $limit = max(1, min($limit, 500));
        $where = ['b.source_type = :source_type'];
        $params = [':source_type' => $sourceType];
        if ($status !== '') {
            $where[] = 'q.status = :status';
            $params[':status'] = $status;
        }
        [$branchWhere, $branchParams] = BranchRepository::activeBranchWhere('q');
        if ($branchWhere !== '') {
            $where[] = $branchWhere;
            $params += $branchParams;
        }
        $stmt = Database::pdo()->prepare(
            "SELECT TOP $limit q.*, b.name AS batch_name, b.source_type, t.name AS template_name, t.language
             FROM dbo.SendMail_WhatsAppQueue q
             INNER JOIN dbo.SendMail_WhatsAppBatches b ON b.id = q.batch_id
             INNER JOIN dbo.SendMail_WhatsAppTemplates t ON t.id = q.template_id
             WHERE " . implode(' AND ', $where) . '
             ORDER BY q.created_at DESC, q.id DESC'
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function batches(string $sourceType, string $status = '', int $limit = 100): array
    {
        self::assertSourceType($sourceType);
        $limit = max(1, min($limit, 500));
        $where = ['b.source_type = :source_type'];
        $params = [':source_type' => $sourceType];
        if ($status !== '') {
            $where[] = 'b.status = :status';
            $params[':status'] = $status;
        }
        [$branchWhere, $branchParams] = BranchRepository::activeBranchWhere('b');
        if ($branchWhere !== '') {
            $where[] = $branchWhere;
            $params += $branchParams;
        }
        $stmt = Database::pdo()->prepare(
            "SELECT TOP $limit b.*, t.name AS template_name, t.language,
                    MIN(q.scheduled_at) AS first_scheduled_at,
                    MAX(q.scheduled_at) AS last_scheduled_at,
                    SUM(CASE WHEN q.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN q.status = 'sending' THEN 1 ELSE 0 END) AS sending_count,
                    SUM(CASE WHEN q.status = 'skipped' THEN 1 ELSE 0 END) AS skipped_count
             FROM dbo.SendMail_WhatsAppBatches b
             INNER JOIN dbo.SendMail_WhatsAppTemplates t ON t.id = b.template_id
             LEFT JOIN dbo.SendMail_WhatsAppQueue q ON q.batch_id = b.id
             WHERE " . implode(' AND ', $where) . '
             GROUP BY b.id, b.branch_id, b.source_type, b.template_id, b.name, b.status,
                      b.total_queued, b.total_sent, b.total_failed, b.total_delivered, b.total_read,
                      b.created_at, b.started_at, b.completed_at, t.name, t.language
             ORDER BY b.created_at DESC, b.id DESC'
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function pauseBatch(int $batchId): void
    {
        self::changeBatchStatus($batchId, ['queued', 'processing'], 'paused', false);
    }

    public static function resumeBatch(int $batchId): void
    {
        self::changeBatchStatus($batchId, ['paused'], 'queued', false);
    }

    public static function stopBatch(int $batchId): void
    {
        self::changeBatchStatus($batchId, ['queued', 'processing', 'paused'], 'stopped', true);
    }

    private static function changeBatchStatus(int $batchId, array $allowed, string $newStatus, bool $skipPending): void
    {
        Schema::ensure();
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            [$branchWhere, $branchParams] = BranchRepository::activeBranchWhere('b');
            $branchSql = $branchWhere !== '' ? ' AND ' . $branchWhere : '';
            $stmt = $pdo->prepare("SELECT b.status FROM dbo.SendMail_WhatsAppBatches b WITH (UPDLOCK, HOLDLOCK) WHERE b.id = :id $branchSql");
            $stmt->execute([':id' => $batchId] + $branchParams);
            $current = $stmt->fetchColumn();
            if (!is_string($current) || !in_array($current, $allowed, true)) {
                throw new RuntimeException('El envio de WhatsApp no admite esa accion en su estado actual.');
            }
            $update = $pdo->prepare(
                "UPDATE dbo.SendMail_WhatsAppBatches
                 SET status = :status, completed_at = CASE WHEN :status_completed = 'stopped' THEN SYSDATETIME() ELSE completed_at END
                 WHERE id = :id"
            );
            $update->execute([':status' => $newStatus, ':status_completed' => $newStatus, ':id' => $batchId]);
            if ($skipPending) {
                $skip = $pdo->prepare("UPDATE dbo.SendMail_WhatsAppQueue SET status = 'skipped', last_error = 'Envio detenido por el usuario.' WHERE batch_id = :id AND status = 'pending'");
                $skip->execute([':id' => $batchId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        self::updateBatchTotals($batchId);
    }

    private static function normalizeTemplateRow(array $row): array
    {
        $row['category'] = strtoupper((string) ($row['category'] ?? ''));
        $row['status'] = strtoupper((string) ($row['status'] ?? ''));
        $row['parameter_count'] = self::bodyParameterCount($row);
        $row['variables'] = self::templateVariables($row);
        $row['is_ready'] = self::isTemplateReady($row);
        return $row;
    }

    private static function assertSourceType(string $sourceType): void
    {
        if (!in_array($sourceType, [self::SOURCE_CAMPAIGN, self::SOURCE_INVOICE], true)) {
            throw new InvalidArgumentException('Tipo de envio de WhatsApp invalido.');
        }
    }

    private static function categoryForSource(string $sourceType): string
    {
        return $sourceType === self::SOURCE_INVOICE ? 'UTILITY' : 'MARKETING';
    }
}
