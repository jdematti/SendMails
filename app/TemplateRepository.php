<?php

declare(strict_types=1);

final class TemplateRepository
{
    public static function all(bool $onlyActive = false): array
    {
        Schema::ensure();
        $where = [];
        $params = [];
        [$branchWhere, $branchParams] = BranchRepository::activeBranchWhere();
        if ($branchWhere !== '') {
            $where[] = $branchWhere;
            $params += $branchParams;
        }
        if ($onlyActive) {
            $where[] = 'is_active = 1';
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = Database::pdo()->prepare(
            "SELECT id, branch_id, name, subject, is_active, created_at, updated_at
             FROM dbo.SendMail_Templates
             $whereSql
             ORDER BY updated_at DESC, id DESC"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return array_map([self::class, 'normalizeRow'], $rows);
    }

    public static function count(): int
    {
        Schema::ensure();
        [$branchWhere, $branchParams] = BranchRepository::activeBranchWhere();
        $whereSql = $branchWhere !== '' ? 'WHERE ' . $branchWhere : '';
        $stmt = Database::pdo()->prepare("SELECT COUNT(*) FROM dbo.SendMail_Templates $whereSql");
        $stmt->execute($branchParams);
        return (int) $stmt->fetchColumn();
    }

    public static function recent(int $limit = 5): array
    {
        Schema::ensure();
        $limit = max(1, min($limit, 100));
        [$branchWhere, $branchParams] = BranchRepository::activeBranchWhere();
        $whereSql = $branchWhere !== '' ? 'WHERE ' . $branchWhere : '';
        $stmt = Database::pdo()->prepare(
            "SELECT TOP $limit id, branch_id, name, subject, is_active, created_at, updated_at
             FROM dbo.SendMail_Templates
             $whereSql
             ORDER BY created_at DESC, id DESC"
        );
        $stmt->execute($branchParams);
        $rows = $stmt->fetchAll();

        return array_map([self::class, 'normalizeRow'], $rows);
    }

    public static function blankHtml(): string
    {
        if (!is_file(DEFAULT_TEMPLATE_PATH)) {
            return '';
        }

        $html = file_get_contents(DEFAULT_TEMPLATE_PATH);
        return is_string($html) ? repair_mojibake($html) : '';
    }

    public static function find(int $id): ?array
    {
        Schema::ensure();
        [$branchWhere, $branchParams] = BranchRepository::activeBranchWhere();
        $branchSql = $branchWhere !== '' ? ' AND ' . $branchWhere : '';
        $stmt = Database::pdo()->prepare("SELECT * FROM dbo.SendMail_Templates WHERE id = :id $branchSql");
        $stmt->execute([':id' => $id] + $branchParams);
        $row = $stmt->fetch();
        return $row ? self::normalizeRow($row) : null;
    }

    public static function save(?int $id, string $name, string $subject, string $htmlBody, bool $isActive, ?array $attachments = null): int
    {
        Schema::ensure();
        if ($name === '' || $subject === '' || $htmlBody === '') {
            throw new InvalidArgumentException('Nombre, asunto y HTML son obligatorios.');
        }
        $branchId = BranchRepository::currentId();
        if ($branchId === null || $branchId <= 0) {
            throw new RuntimeException('Selecciona una sucursal antes de guardar plantillas.');
        }
        // Null preserves existing attachments for callers that only edit the HTML.
        $attachmentsJson = $attachments === null ? null : CampaignAttachments::toJson($attachments);

        if ($id) {
            $stmt = Database::pdo()->prepare(
                'UPDATE dbo.SendMail_Templates
                 SET name = :name, subject = :subject, html_body = :html_body, is_active = :is_active,
                     attachments_json = COALESCE(:attachments_json, attachments_json), updated_at = SYSDATETIME()
                 WHERE id = :id AND branch_id = :branch_id'
            );
            $stmt->execute([
                ':name' => $name,
                ':subject' => $subject,
                ':html_body' => $htmlBody,
                ':attachments_json' => $attachmentsJson,
                ':is_active' => $isActive ? 1 : 0,
                ':id' => $id,
                ':branch_id' => $branchId,
            ]);
            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('Plantilla no encontrada para la sucursal activa.');
            }
            return $id;
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO dbo.SendMail_Templates (branch_id, name, subject, html_body, is_active, attachments_json)
             OUTPUT INSERTED.id
             VALUES (:branch_id, :name, :subject, :html_body, :is_active, :attachments_json)'
        );
        $stmt->execute([
            ':branch_id' => $branchId,
            ':name' => $name,
            ':subject' => $subject,
            ':html_body' => $htmlBody,
            ':attachments_json' => $attachmentsJson,
            ':is_active' => $isActive ? 1 : 0,
        ]);
        return (int) $stmt->fetchColumn();
    }

    private static function normalizeRow(array $row): array
    {
        foreach (['name', 'subject', 'html_body'] as $field) {
            if (isset($row[$field])) {
                $row[$field] = repair_mojibake((string) $row[$field]);
            }
        }

        return $row;
    }
}
