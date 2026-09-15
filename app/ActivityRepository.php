<?php
declare(strict_types=1);

final class ActivityRepository
{
    private static function source(): string
    {
        return "WITH activity AS (
            SELECT q.id,q.branch_id,q.campaign_id AS batch_id,'campaign' AS kind,'email' AS channel,
                c.name,c.status AS batch_status,q.client_name,q.email_to AS recipient,q.status,q.scheduled_at,q.created_at,q.last_error,q.is_test
            FROM dbo.SendMail_Queue q INNER JOIN dbo.SendMail_Campaigns c ON c.id=q.campaign_id AND c.branch_id=q.branch_id
            UNION ALL
            SELECT q.id,q.branch_id,q.batch_id,'invoice','email',
                b.name,b.status,q.client_name,q.email_to,q.status,q.scheduled_at,q.created_at,q.last_error,q.is_test
            FROM dbo.SendMail_InvoiceQueue q INNER JOIN dbo.SendMail_InvoiceBatches b ON b.id=q.batch_id AND b.branch_id=q.branch_id
            UNION ALL
            SELECT q.id,q.branch_id,q.batch_id,b.source_type,'whatsapp',
                b.name,b.status,q.client_name,q.phone_to,q.status,q.scheduled_at,q.created_at,q.last_error,0
            FROM dbo.SendMail_WhatsAppQueue q INNER JOIN dbo.SendMail_WhatsAppBatches b ON b.id=q.batch_id AND b.branch_id=q.branch_id
        ) ";
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
        $stmt = Database::pdo()->prepare(self::source() . ", numbered_activity AS (
            SELECT *, COUNT(*) OVER() AS result_total,
                ROW_NUMBER() OVER (ORDER BY created_at DESC, id DESC, kind, channel) AS page_row_number
            FROM activity WHERE $where)
            SELECT * FROM numbered_activity WHERE page_row_number > :page_start AND page_row_number <= :page_end
            ORDER BY page_row_number");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) unset($row['page_row_number']);
        unset($row);
        return ['rows' => $rows, 'total' => (int) ($rows[0]['result_total'] ?? 0)];
    }

    public static function summary(array $filters): array
    {
        [$where, $params] = self::where($filters, false);
        $stmt = Database::pdo()->prepare(self::source() . "SELECT
            SUM(CASE WHEN status='pending' AND scheduled_at <= SYSDATETIME() AND batch_status IN ('queued','processing') THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status='pending' AND scheduled_at > SYSDATETIME() AND batch_status IN ('queued','processing') THEN 1 ELSE 0 END) AS scheduled,
            SUM(CASE WHEN status='pending' AND batch_status='paused' THEN 1 ELSE 0 END) AS paused,
            SUM(CASE WHEN status='sending' THEN 1 ELSE 0 END) AS sending,
            SUM(CASE WHEN status='sending' AND last_error IS NOT NULL THEN 1 ELSE 0 END) AS attention,
            SUM(CASE WHEN status IN ('sent','accepted','delivered','read') AND is_test=0 THEN 1 ELSE 0 END) AS sent,
            SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed,
            SUM(CASE WHEN is_test=1 AND status='sent' THEN 1 ELSE 0 END) AS tests
            FROM activity WHERE $where");
        $stmt->execute($params);
        return array_map('intval', $stmt->fetch() ?: []);
    }

    public static function batches(array $filters): array
    {
        [$where, $params] = self::where($filters, false);
        $stmt = Database::pdo()->prepare(self::source() . "SELECT TOP 100 kind,channel,batch_id,name,batch_status,
            COUNT(*) AS total, SUM(CASE WHEN status<>'pending' AND status<>'sending' THEN 1 ELSE 0 END) AS processed,
            SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed, MAX(created_at) AS created_at
            FROM activity WHERE $where GROUP BY kind,channel,batch_id,name,batch_status ORDER BY MAX(created_at) DESC,batch_id DESC");
        $stmt->execute($params);
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
