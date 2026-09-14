<?php
declare(strict_types=1);

final class BulkInsert
{
    public static function rows(PDO $pdo, string $table, array $rows): void
    {
        if (!$rows) return;
        if (!in_array($table, ['SendMail_Queue', 'SendMail_InvoiceQueue', 'SendMail_WhatsAppQueue'], true)) {
            throw new InvalidArgumentException('Tabla de cola invalida.');
        }
        $columns = array_keys($rows[0]);
        foreach ($columns as $column) {
            if (!preg_match('/^[a-z_]+$/D', $column)) throw new InvalidArgumentException('Columna invalida.');
        }
        // SQL Server allows at most 2100 parameters. Leave room below that limit.
        foreach (array_chunk($rows, max(1, (int) floor(1800 / count($columns)))) as $chunk) {
            $values = []; $params = [];
            foreach ($chunk as $row) {
                if (array_keys($row) !== $columns) throw new InvalidArgumentException('Columnas de cola inconsistentes.');
                $values[] = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
                array_push($params, ...array_values($row));
            }
            $stmt = $pdo->prepare('INSERT INTO dbo.' . $table . ' (' . implode(',', $columns) . ') VALUES ' . implode(',', $values));
            $stmt->execute($params);
        }
    }
}
