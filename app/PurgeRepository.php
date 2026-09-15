<?php
declare(strict_types=1);

final class PurgeRepository
{
    public const LIMIT = 100;
    private const SOURCES = [
        'campaign_email'=>['batch'=>'SendMail_Campaigns','queue'=>'SendMail_Queue','foreign'=>'campaign_id','log'=>'SendMail_Log','kind'=>'campaign','channel'=>'email'],
        'invoice_email'=>['batch'=>'SendMail_InvoiceBatches','queue'=>'SendMail_InvoiceQueue','foreign'=>'batch_id','log'=>'SendMail_InvoiceLog','kind'=>'invoice','channel'=>'email'],
        'whatsapp'=>['batch'=>'SendMail_WhatsAppBatches','queue'=>'SendMail_WhatsAppQueue','foreign'=>'batch_id','log'=>'SendMail_WhatsAppEvents','kind'=>'','channel'=>'whatsapp'],
    ];

    private static function requireAdmin(): void
    {
        if (!Auth::isAdmin()) throw new RuntimeException('Solo los administradores pueden purgar el historial.');
    }

    public static function maxCutoff(): string { return (new DateTimeImmutable('today'))->modify('-90 days')->format('Y-m-d'); }

    public static function filters(array $input): array
    {
        self::requireAdmin();
        $cutoff = (string) ($input['cutoff'] ?? self::maxCutoff());
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $cutoff);
        if (!$date || $date->format('Y-m-d') !== $cutoff || $cutoff > self::maxCutoff()) throw new InvalidArgumentException('La fecha debe conservar al menos los últimos 90 días.');
        $branch = max(0, (int) ($input['branch_id'] ?? 0));
        if ($branch && !BranchRepository::find($branch)) throw new InvalidArgumentException('Sucursal inexistente.');
        $kind = (string) ($input['kind'] ?? ''); $channel = (string) ($input['channel'] ?? '');
        if (!in_array($kind, ['', 'campaign', 'invoice'], true) || !in_array($channel, ['', 'email', 'whatsapp'], true)) throw new InvalidArgumentException('Filtro de purga inválido.');
        return ['cutoff'=>$cutoff, 'branch_id'=>$branch, 'kind'=>$kind, 'channel'=>$channel];
    }

    private static function logRelation(array $source, string $hint = ''): string
    {
        $queue = $source['queue']; $foreign = $source['foreign'];
        $link = $source['channel'] === 'whatsapp' ? 'l.provider_message_id=q.provider_message_id' : 'l.queue_id=q.id';
        $relation = "EXISTS(SELECT 1 FROM dbo.$queue q$hint WHERE q.$foreign=b.id AND q.branch_id=b.branch_id AND $link)";
        if ($source['kind'] === 'campaign') $relation = '(l.campaign_id=b.id OR ' . $relation . ')';
        return '(l.branch_id=b.branch_id OR l.branch_id IS NULL) AND ' . $relation;
    }

    private static function candidates(string $key, array $filters, ?int $id = null, bool $lock = false): array
    {
        $s = self::SOURCES[$key];
        if (($filters['channel'] && $filters['channel'] !== $s['channel']) || ($s['kind'] && $filters['kind'] && $s['kind'] !== $filters['kind'])) return [];
        $hint = $lock ? ' WITH (UPDLOCK,HOLDLOCK)' : '';
        $batch = $s['batch']; $queue = $s['queue']; $foreign = $s['foreign']; $log = $s['log'];
        $params = [':cutoff'=>$filters['cutoff'] . ' 00:00:00'];
        $where = ["b.status IN ('completed','stopped')", 'COALESCE(b.completed_at,b.created_at)<@cutoff', 'b.created_at<@cutoff'];
        if ($filters['branch_id']) { $where[] = 'b.branch_id=:branch'; $params[':branch'] = $filters['branch_id']; }
        if ($s['channel'] === 'whatsapp' && $filters['kind']) { $where[] = 'b.source_type=:kind'; $params[':kind'] = $filters['kind']; }
        if ($id !== null) { $where[] = 'b.id=:id'; $params[':id'] = $id; }
        $activity = ['created_at','scheduled_at','sent_at'];
        if ($s['channel'] === 'whatsapp') $activity = array_merge($activity, ['accepted_at','delivered_at','read_at','failed_at']);
        $recent = implode(' OR ', array_map(static fn(string $field): string => 'q.' . $field . '>=@cutoff', $activity));
        $where[] = "NOT EXISTS(SELECT 1 FROM dbo.$queue q$hint WHERE q.$foreign=b.id AND
            (q.branch_id<>b.branch_id OR q.status NOT IN ('sent','failed','skipped','accepted','delivered','read') OR $recent))";
        $relation = self::logRelation($s, $hint);
        $where[] = "NOT EXISTS(SELECT 1 FROM dbo.$log l$hint WHERE $relation AND l.created_at>=@cutoff)";
        $kind = $s['kind'] ? "'" . $s['kind'] . "'" : 'b.source_type';
        $limit = self::LIMIT + 1;
        $sql = "DECLARE @cutoff datetime2=CONVERT(datetime2,:cutoff,120);
            SELECT TOP $limit b.id,b.branch_id,b.name,b.status,COALESCE(b.completed_at,b.created_at) AS finished_at,
                COALESCE(branch.name,'Sucursal eliminada') AS branch_name,$kind AS kind,
                (SELECT COUNT(*) FROM dbo.$queue q$hint WHERE q.$foreign=b.id AND q.branch_id=b.branch_id) AS recipients,
                (SELECT COUNT(*) FROM dbo.$log l$hint WHERE $relation) AS records
            FROM dbo.$batch b$hint LEFT JOIN dbo.SendMail_Branches branch ON branch.id=b.branch_id
            WHERE " . implode(' AND ', $where) . ' ORDER BY COALESCE(b.completed_at,b.created_at),b.id';
        $stmt = Database::pdo()->prepare($sql); $stmt->execute($params);
        return array_map(static fn(array $row): array => $row + ['source'=>$key, 'channel'=>$s['channel']], $stmt->fetchAll());
    }

    public static function preview(array $filters): array
    {
        $filters = self::filters($filters); Schema::ensure();
        $rows = [];
        foreach (array_keys(self::SOURCES) as $key) array_push($rows, ...self::candidates($key, $filters));
        usort($rows, static fn(array $a,array $b): int => [$a['finished_at'],$a['source'],(int)$a['id']] <=> [$b['finished_at'],$b['source'],(int)$b['id']]);
        $more = count($rows) > self::LIMIT; $rows = array_slice($rows, 0, self::LIMIT);
        return ['token'=>bin2hex(random_bytes(16)), 'created_at'=>time(), 'user_id'=>(int) Auth::currentUser()['id'],
            'filters'=>$filters, 'rows'=>$rows, 'more'=>$more,
            'totals'=>['batches'=>count($rows), 'recipients'=>array_sum(array_column($rows,'recipients')), 'records'=>array_sum(array_column($rows,'records'))]];
    }

    public static function execute(array $preview): array
    {
        self::requireAdmin();
        $user = Auth::currentUser(); $token = (string) ($preview['token'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/D',$token) || (int) ($preview['user_id'] ?? 0) !== (int) $user['id']) throw new RuntimeException('Vista previa inválida.');
        $filters = self::filters($preview['filters']);
        $pdo = Database::pdo(); $pdo->beginTransaction();
        try {
            $lock = $pdo->query("DECLARE @r int; EXEC @r=sp_getapplock @Resource='SendMails:purge',@LockMode='Exclusive',@LockOwner='Transaction',@LockTimeout=0; SELECT @r")->fetchColumn();
            if ((int) $lock < 0) throw new RuntimeException('Otro administrador está purgando. Intentá nuevamente al terminar.');
            $receiptKey = 'purge_receipt:' . $token;
            $find = $pdo->prepare('SELECT setting_value FROM dbo.SendMail_Settings WHERE setting_key=:key');
            $find->execute([':key'=>$receiptKey]); $existing = $find->fetchColumn();
            if ($existing !== false) { $pdo->commit(); return json_decode($existing,true,512,JSON_THROW_ON_ERROR); }
            if (time() - (int) $preview['created_at'] > 900 || !$preview['rows'] || count($preview['rows']) > self::LIMIT) throw new RuntimeException('La vista previa venció o no contiene lotes. Calculala nuevamente.');
            $totals = ['batches'=>0, 'recipients'=>0, 'records'=>0];
            foreach ($preview['rows'] as $row) {
                $key = $row['source'];
                if (!isset(self::SOURCES[$key])) throw new RuntimeException('Lote inválido.');
                $current = self::candidates($key,$filters,(int)$row['id'],true);
                if (count($current) !== 1 || $current[0] !== $row) throw new RuntimeException('El historial cambió desde la vista previa. No se eliminó nada. Calculala nuevamente.');
                $s = self::SOURCES[$key]; $batch = $s['batch']; $queue = $s['queue']; $log = $s['log']; $foreign = $s['foreign'];
                $deleteLogs = $pdo->prepare("DELETE l FROM dbo.$log l INNER JOIN dbo.$batch b ON b.id=:id WHERE " . self::logRelation($s));
                $deleteLogs->execute([':id'=>$row['id']]);
                if ($deleteLogs->rowCount() !== (int) $row['records']) throw new RuntimeException('Los registros cambiaron durante la purga. La operación se revirtió.');
                $deleteRows = $pdo->prepare("DELETE FROM dbo.$queue WHERE $foreign=:id AND branch_id=:branch");
                $deleteRows->execute([':id'=>$row['id'], ':branch'=>$row['branch_id']]);
                if ($deleteRows->rowCount() !== (int) $row['recipients']) throw new RuntimeException('Los destinatarios cambiaron durante la purga. La operación se revirtió.');
                $deleteBatch = $pdo->prepare("DELETE FROM dbo.$batch WHERE id=:id AND branch_id=:branch");
                $deleteBatch->execute([':id'=>$row['id'], ':branch'=>$row['branch_id']]);
                if ($deleteBatch->rowCount() !== 1) throw new RuntimeException('El lote cambió durante la purga. La operación se revirtió.');
                $totals['batches']++; $totals['recipients'] += (int) $row['recipients']; $totals['records'] += (int) $row['records'];
            }
            $receipt = ['at'=>date('Y-m-d H:i:s'), 'user_id'=>(int)$user['id'], 'user_name'=>$user['full_name'], 'filters'=>$filters, 'totals'=>$totals];
            $audit = $pdo->prepare('INSERT INTO dbo.SendMail_Settings(setting_key,setting_value) VALUES(:key,:value)');
            $audit->execute([':key'=>$receiptKey, ':value'=>json_encode($receipt,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
            $pdo->commit(); return $receipt;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function recent(): array
    {
        self::requireAdmin();
        $stmt = Database::pdo()->query("SELECT TOP 5 setting_value FROM dbo.SendMail_Settings WHERE setting_key LIKE 'purge_receipt:%' ORDER BY updated_at DESC");
        return array_map(static fn(string $json): array => json_decode($json,true,512,JSON_THROW_ON_ERROR), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
