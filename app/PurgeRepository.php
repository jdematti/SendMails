<?php
declare(strict_types=1);

final class PurgeRepository
{
    public const LIMIT = 20;
    public const ROW_BUDGET = 10000;
    private const QUERY_SECONDS = 15;
    private const OPERATION_SECONDS = 45;
    private static ?float $deadline = null;
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

    private static function statement(string $sql, array $params = []): PDOStatement
    {
        $seconds = self::QUERY_SECONDS;
        if (self::$deadline !== null) {
            $remaining = self::$deadline - microtime(true);
            if ($remaining <= 0) throw new RuntimeException('La purga alcanzó su tiempo máximo. Calculá otra vista previa con menos lotes.');
            $seconds = min($seconds, max(1, (int) ceil($remaining)));
        }
        $stmt = Database::pdo()->prepare($sql, [PDO::SQLSRV_ATTR_QUERY_TIMEOUT=>$seconds]);
        $stmt->execute($params);
        return $stmt;
    }

    private static function relatedLogs(array $source, string $hint = ''): string
    {
        $queue = $source['queue']; $foreign = $source['foreign']; $log = $source['log'];
        $link = $source['channel'] === 'whatsapp' ? 'l.provider_message_id=q.provider_message_id' : 'l.queue_id=q.id';
        $linked = "SELECT l.id,l.created_at FROM dbo.$queue q$hint INNER JOIN dbo.$log l$hint ON $link
            WHERE q.$foreign=b.id AND q.branch_id=b.branch_id AND (l.branch_id=b.branch_id OR l.branch_id IS NULL)";
        if ($source['kind'] !== 'campaign') return $linked;
        // Disjoint index lookups: avoid rescanning the complete log for each batch.
        return "SELECT l.id,l.created_at FROM dbo.$log l$hint WHERE l.campaign_id=b.id AND (l.branch_id=b.branch_id OR l.branch_id IS NULL)
            UNION ALL $linked AND (l.campaign_id IS NULL OR l.campaign_id<>b.id)";
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
        $related = self::relatedLogs($s, $hint);
        $where[] = "NOT EXISTS(SELECT 1 FROM ($related) related WHERE related.created_at>=@cutoff)";
        $kind = $s['kind'] ? "'" . $s['kind'] . "'" : 'b.source_type';
        $limit = self::LIMIT + 1;
        $sql = "DECLARE @cutoff datetime2=CONVERT(datetime2,:cutoff,120);
            SELECT TOP $limit b.id,b.branch_id,b.name,b.status,COALESCE(b.completed_at,b.created_at) AS finished_at,
                COALESCE(branch.name,'Sucursal eliminada') AS branch_name,$kind AS kind,
                (SELECT COUNT(*) FROM dbo.$queue q$hint WHERE q.$foreign=b.id AND q.branch_id=b.branch_id) AS recipients,
                (SELECT COUNT(*) FROM ($related) related) AS records
            FROM dbo.$batch b$hint LEFT JOIN dbo.SendMail_Branches branch ON branch.id=b.branch_id
            WHERE " . implode(' AND ', $where) . ' ORDER BY COALESCE(b.completed_at,b.created_at),b.id';
        $stmt = self::statement($sql, $params);
        return array_map(static fn(array $row): array => $row + ['source'=>$key, 'channel'=>$s['channel']], $stmt->fetchAll());
    }

    public static function preview(array $filters): array
    {
        $filters = self::filters($filters); Schema::ensure();
        $rows = [];
        self::$deadline = microtime(true) + self::OPERATION_SECONDS;
        try {
            foreach (array_keys(self::SOURCES) as $key) array_push($rows, ...self::candidates($key, $filters));
        } catch (PDOException $e) {
            if (in_array((string)$e->getCode(), ['HYT00','HYT01'], true)) {
                throw new RuntimeException('La base tardó demasiado al calcular la vista previa. No se modificó ningún dato. Intentá filtrar por sucursal o canal.', 0, $e);
            }
            throw $e;
        } finally { self::$deadline = null; }
        usort($rows, static fn(array $a,array $b): int => [$a['finished_at'],$a['source'],(int)$a['id']] <=> [$b['finished_at'],$b['source'],(int)$b['id']]);
        $available = count($rows); $selected = []; $volume = 0;
        foreach ($rows as $row) {
            $cost = (int)$row['recipients'] + (int)$row['records'];
            if (count($selected) >= self::LIMIT || ($selected && $volume + $cost > self::ROW_BUDGET)) break;
            $selected[] = $row; $volume += $cost;
            // A batch is indivisible: if it exceeds the budget, handle it alone.
            if ($volume >= self::ROW_BUDGET) break;
        }
        $rows = $selected; $more = $available > count($rows);
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
        self::$deadline = microtime(true) + self::OPERATION_SECONDS;
        try {
            $lock = self::statement("DECLARE @r int; EXEC @r=sp_getapplock @Resource='SendMails:purge',@LockMode='Exclusive',@LockOwner='Transaction',@LockTimeout=0; SELECT @r")->fetchColumn();
            if ((int) $lock < 0) throw new RuntimeException('Otro administrador está purgando. Intentá nuevamente al terminar.');
            $receiptKey = 'purge_receipt:' . $token;
            $find = self::statement('SELECT setting_value FROM dbo.SendMail_Settings WHERE setting_key=:key', [':key'=>$receiptKey]);
            $existing = $find->fetchColumn(); $find->closeCursor();
            if ($existing !== false) { $pdo->commit(); return json_decode($existing,true,512,JSON_THROW_ON_ERROR); }
            $volume = array_sum(array_column($preview['rows'], 'recipients')) + array_sum(array_column($preview['rows'], 'records'));
            if (time() - (int) $preview['created_at'] > 900 || !$preview['rows'] || count($preview['rows']) > self::LIMIT || (count($preview['rows']) > 1 && $volume > self::ROW_BUDGET)) throw new RuntimeException('La vista previa venció o supera el límite actual. Calculala nuevamente.');
            $totals = ['batches'=>0, 'recipients'=>0, 'records'=>0];
            foreach ($preview['rows'] as $row) {
                $key = $row['source'];
                if (!isset(self::SOURCES[$key])) throw new RuntimeException('Lote inválido.');
                $current = self::candidates($key,$filters,(int)$row['id'],true);
                if (count($current) !== 1 || $current[0] !== $row) throw new RuntimeException('El historial cambió desde la vista previa. No se eliminó nada. Calculala nuevamente.');
                $s = self::SOURCES[$key]; $batch = $s['batch']; $queue = $s['queue']; $log = $s['log']; $foreign = $s['foreign'];
                $params = [':id'=>$row['id'], ':branch'=>$row['branch_id']]; $deletedLogs = 0;
                if ($s['kind'] === 'campaign') {
                    $deletedLogs += self::statement("DELETE FROM dbo.$log WHERE campaign_id=:id AND (branch_id=:branch OR branch_id IS NULL)", $params)->rowCount();
                }
                $link = $s['channel'] === 'whatsapp' ? 'l.provider_message_id=q.provider_message_id' : 'l.queue_id=q.id';
                $deletedLogs += self::statement("DELETE l FROM dbo.$log l INNER JOIN dbo.$queue q ON $link
                    WHERE q.$foreign=:id AND q.branch_id=:branch AND (l.branch_id=q.branch_id OR l.branch_id IS NULL)", $params)->rowCount();
                if ($deletedLogs !== (int) $row['records']) throw new RuntimeException('Los registros cambiaron durante la purga. La operación se revirtió.');
                $deleteRows = self::statement("DELETE FROM dbo.$queue WHERE $foreign=:id AND branch_id=:branch", $params);
                if ($deleteRows->rowCount() !== (int) $row['recipients']) throw new RuntimeException('Los destinatarios cambiaron durante la purga. La operación se revirtió.');
                $deleteBatch = self::statement("DELETE FROM dbo.$batch WHERE id=:id AND branch_id=:branch", $params);
                if ($deleteBatch->rowCount() !== 1) throw new RuntimeException('El lote cambió durante la purga. La operación se revirtió.');
                $totals['batches']++; $totals['recipients'] += (int) $row['recipients']; $totals['records'] += (int) $row['records'];
            }
            $receipt = ['at'=>date('Y-m-d H:i:s'), 'user_id'=>(int)$user['id'], 'user_name'=>$user['full_name'], 'filters'=>$filters, 'totals'=>$totals];
            self::statement('INSERT INTO dbo.SendMail_Settings(setting_key,setting_value) VALUES(:key,:value)',
                [':key'=>$receiptKey, ':value'=>json_encode($receipt,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
            $pdo->commit(); return $receipt;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof PDOException && in_array((string)$e->getCode(), ['HYT00','HYT01'], true)) {
                throw new RuntimeException('La base tardó demasiado en responder. La purga se revirtió; no se eliminó ningún lote de esta operación. Calculá otra vista previa o intentá cuando la base esté menos ocupada.', 0, $e);
            }
            throw $e;
        } finally { self::$deadline = null; }
    }

    public static function recent(): array
    {
        self::requireAdmin();
        $stmt = self::statement("SELECT TOP 5 setting_value FROM dbo.SendMail_Settings WHERE setting_key LIKE 'purge[_]receipt:%' ORDER BY updated_at DESC");
        return array_map(static fn(string $json): array => json_decode($json,true,512,JSON_THROW_ON_ERROR), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
