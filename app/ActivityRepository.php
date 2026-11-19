<?php
declare(strict_types=1);

final class ActivityRepository
{
    private static function source(array $filters, string $projection = 'full', bool $pageOnly = false): string
    {
        $queries = [];
        foreach (self::sources($filters) as [$queue, $batch, $batchKey, $kind, $channel, $recipient, $isTest]) {
            $columns = "q.branch_id, q.$batchKey AS batch_id, $kind AS kind, '$channel' AS channel, q.status";
            if ($projection === 'keys') {
                $columns .= ', q.id, q.created_at';
            } elseif ($projection === 'summary') {
                $columns .= ", q.scheduled_at, b.status AS batch_status, $isTest AS is_test,
                    CASE WHEN q.last_error IS NOT NULL THEN 1 ELSE 0 END AS attention";
            } else {
                $columns .= ", q.id, b.name, b.status AS batch_status, q.client_name, q.$recipient AS recipient,
                    q.scheduled_at, q.created_at, q.last_error, $isTest AS is_test";
            }
            $sql = "SELECT $columns FROM dbo.$queue q
                INNER JOIN dbo.$batch b ON b.id=q.$batchKey AND b.branch_id=q.branch_id";
            if ($pageOnly) $sql .= " INNER JOIN @activity_page p ON p.id=q.id AND p.branch_id=q.branch_id AND p.kind=$kind AND p.channel='$channel'";
            $sql .= ' WHERE q.branch_id=' . (int) $filters['branch'];
            if (!empty($filters['batch_id'])) $sql .= ' AND q.' . $batchKey . '=' . (int) $filters['batch_id'];
            if ($channel === 'whatsapp' && !empty($filters['kind'])) {
                $kindLiteral = in_array($filters['kind'], ['campaign','invoice'], true) ? $filters['kind'] : '';
                $sql .= " AND b.source_type='$kindLiteral'";
            }
            $queries[] = $sql;
        }
        return 'WITH activity AS (' . implode(' UNION ALL ', $queries) . ') ';
    }

    private static function sources(array $filters): array
    {
        $sources = [
            ['SendMail_Queue','SendMail_Campaigns','campaign_id',"'campaign'",'email','email_to','q.is_test'],
            ['SendMail_InvoiceQueue','SendMail_InvoiceBatches','batch_id',"'invoice'",'email','email_to','q.is_test'],
            ['SendMail_WhatsAppQueue','SendMail_WhatsAppBatches','batch_id','b.source_type','whatsapp','phone_to','0'],
        ];
        return array_values(array_filter($sources, static function(array $source) use ($filters): bool {
            if (!empty($filters['channel']) && $filters['channel'] !== $source[4]) return false;
            return empty($filters['kind']) || $source[4] === 'whatsapp' || "'" . $filters['kind'] . "'" === $source[3];
        }));
    }

    public static function filters(array $input): array
    {
        $filters = ['branch' => (int) BranchRepository::currentId()];
        foreach (['kind' => ['campaign', 'invoice'], 'channel' => ['email', 'whatsapp'],
            'status' => ['pending', 'sending', 'sent', 'failed', 'skipped', 'accepted', 'delivered', 'read']] as $key => $allowed) {
            $filters[$key] = in_array($input[$key] ?? '', $allowed, true) ? $input[$key] : '';
        }
        $filters['batch_id'] = max(0, (int) ($input['batch_id'] ?? 0));
        if ($filters['batch_id'] && (!$filters['kind'] || !$filters['channel'])) $filters['batch_id'] = 0;
        return $filters;
    }

    private static function where(array $filters, bool $withStatus = true): array
    {
        $parts = ['branch_id=:branch']; $params = [':branch' => (int) $filters['branch']];
        foreach (['kind', 'channel', 'batch_id', 'status'] as $key) {
            if (!$withStatus && $key === 'status') continue;
            if (!empty($filters[$key])) { $parts[] = "$key=:$key"; $params[":$key"] = $filters[$key]; }
        }
        return [implode(' AND ', $parts), $params];
    }

    public static function page(array $filters, int $page = 1): array
    {
        [$where, $params] = self::where($filters);
        $params[':page_start'] = (max(1, $page) - 1) * 50;
        $params[':page_end'] = $params[':page_start'] + 50;
        // Sort only keys and materialize the 50 selected rows before loading
        // recipient names, destinations and potentially large error messages.
        $sql = "SET NOCOUNT ON;
            DECLARE @activity_page TABLE(id bigint, branch_id int, kind varchar(10), channel varchar(10), result_total int, page_row_number bigint); "
            . self::source($filters, 'keys') . ", numbered_activity AS (
            SELECT id, branch_id, kind, channel, COUNT(*) OVER() AS result_total,
                ROW_NUMBER() OVER (ORDER BY created_at DESC, id DESC, kind, channel) AS page_row_number
            FROM activity WHERE $where)
            INSERT INTO @activity_page SELECT * FROM numbered_activity
                WHERE page_row_number > :page_start AND page_row_number <= :page_end; "
            . self::source($filters, 'full', true) . "
            SELECT a.*, p.result_total FROM activity a INNER JOIN @activity_page p
                ON p.id=a.id AND p.branch_id=a.branch_id AND p.kind=a.kind AND p.channel=a.channel
            ORDER BY p.page_row_number";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) unset($row['page_row_number']);
        unset($row);
        return ['rows' => $rows, 'total' => (int) ($rows[0]['result_total'] ?? 0)];
    }

    public static function summary(array $filters): array
    {
        [$where, $params] = self::where($filters, false);
        $stmt = Database::pdo()->prepare(self::source($filters, 'summary') . "SELECT
            SUM(CASE WHEN status='pending' AND scheduled_at <= SYSDATETIME() AND batch_status IN ('queued','processing') THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status='pending' AND scheduled_at > SYSDATETIME() AND batch_status IN ('queued','processing') THEN 1 ELSE 0 END) AS scheduled,
            SUM(CASE WHEN status='pending' AND batch_status='paused' THEN 1 ELSE 0 END) AS paused,
            SUM(CASE WHEN status='sending' THEN 1 ELSE 0 END) AS sending,
            SUM(CASE WHEN status='sending' AND attention=1 THEN 1 ELSE 0 END) AS attention,
            SUM(CASE WHEN status IN ('sent','accepted','delivered','read') AND is_test=0 THEN 1 ELSE 0 END) AS sent,
            SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed,
            SUM(CASE WHEN is_test=1 AND status='sent' THEN 1 ELSE 0 END) AS tests
            FROM activity WHERE $where");
        $stmt->execute($params);
        return array_map('intval', $stmt->fetch() ?: []);
    }

    public static function batches(array $filters): array
    {
        $queries = [];
        foreach (self::sources($filters) as [$queue, $batch, $batchKey, $kind, $channel]) {
            $where = 'branch_id=' . (int) $filters['branch'];
            if (!empty($filters['batch_id'])) $where .= ' AND ' . $batchKey . '=' . (int) $filters['batch_id'];
            $kindLiteral = in_array($filters['kind'] ?? '', ['campaign','invoice'], true) ? $filters['kind'] : '';
            $kindFilter = $channel === 'whatsapp' && !empty($filters['kind']) ? " WHERE b.source_type='$kindLiteral'" : '';
            // Aggregate narrow queue columns before joining names and batch state.
            $queries[] = "SELECT $kind AS kind, '$channel' AS channel, q.batch_id, b.name, b.status AS batch_status,
                q.total, q.processed, q.failed, q.created_at
                FROM (SELECT branch_id, $batchKey AS batch_id, COUNT(*) AS total,
                    SUM(CASE WHEN status NOT IN ('pending','sending') THEN 1 ELSE 0 END) AS processed,
                    SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed, MAX(created_at) AS created_at
                    FROM dbo.$queue WHERE $where GROUP BY branch_id, $batchKey) q
                INNER JOIN dbo.$batch b ON b.id=q.batch_id AND b.branch_id=q.branch_id $kindFilter";
        }
        $stmt = Database::pdo()->prepare('SELECT TOP 100 * FROM (' . implode(' UNION ALL ', $queries) . ') batches ORDER BY created_at DESC,batch_id DESC');
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function change(string $kind, string $channel, int $id, string $action): void
    {
        if (!in_array($kind, ['campaign','invoice'], true) || !in_array($channel, ['email','whatsapp'], true)) throw new InvalidArgumentException('Envio invalido.');
        $class = $channel === 'whatsapp' ? WhatsAppRepository::class : ($kind === 'invoice' ? InvoiceRepository::class : QueueRepository::class);
        $suffix = $channel === 'whatsapp' ? 'Batch' : ($kind === 'invoice' ? 'InvoiceBatch' : 'Campaign');
        if (!in_array($action, ['pause','resume','stop'], true)) throw new InvalidArgumentException('Accion invalida.');
        $method = $action . $suffix;
        $class::$method($id);
    }

    public static function label(string $status): string
    {
        return ['pending'=>'Pendiente','sending'=>'Procesando / revisar si se interrumpió','sent'=>'Enviado',
            'failed'=>'Fallido','skipped'=>'Omitido','accepted'=>'Aceptado por Meta','delivered'=>'Entregado','read'=>'Leído',
            'queued'=>'En cola','processing'=>'En curso','paused'=>'Pausado','stopped'=>'Detenido','completed'=>'Completado'][$status] ?? $status;
    }
}
