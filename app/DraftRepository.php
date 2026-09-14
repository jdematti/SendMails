<?php
declare(strict_types=1);

final class DraftRepository
{
    public const KINDS = ['campaign', 'invoice', 'template', 'invoice_template'];

    private static function branch(): int
    {
        $id = (int) BranchRepository::currentId();
        if ($id <= 0) throw new RuntimeException('Selecciona una sucursal.');
        return $id;
    }

    public static function all(string $kind): array
    {
        Schema::ensure();
        $stmt = Database::pdo()->prepare("SELECT d.id, d.name, d.revision, d.updated_at, u.full_name AS editor
            FROM dbo.SendMail_Drafts d LEFT JOIN dbo.SendMail_Users u ON u.id=d.updated_by
            WHERE d.branch_id=:branch AND d.kind=:kind AND d.state <> 'sent'
            ORDER BY d.updated_at DESC, d.id DESC");
        $stmt->execute([':branch' => self::branch(), ':kind' => $kind]);
        return $stmt->fetchAll();
    }

    public static function find(int $id, string $kind, bool $lock = false): array
    {
        Schema::ensure();
        $hint = $lock ? ' WITH (UPDLOCK, HOLDLOCK)' : '';
        $stmt = Database::pdo()->prepare("SELECT * FROM dbo.SendMail_Drafts$hint
            WHERE id=:id AND branch_id=:branch AND kind=:kind");
        $stmt->execute([':id' => $id, ':branch' => self::branch(), ':kind' => $kind]);
        $row = $stmt->fetch();
        if (!$row) throw new RuntimeException('Borrador no encontrado en esta sucursal.');
        $row['payload'] = json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        unset($row['payload_json']);
        return $row;
    }

    public static function save(int $id, string $kind, string $name, array $payload, int $revision, string $state = 'draft'): array
    {
        if (!in_array($kind, self::KINDS, true) || !in_array($state, ['draft', 'review', 'sent'], true)) {
            throw new InvalidArgumentException('Tipo de borrador invalido.');
        }
        Schema::ensure();
        $params = [':branch' => self::branch(), ':kind' => $kind,
            ':name' => mb_substr(trim($name) ?: 'Sin titulo', 0, 150),
            ':payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':user' => (int) (Auth::currentUser()['id'] ?? 0), ':state' => $state];
        if ($id > 0) {
            $stmt = Database::pdo()->prepare("UPDATE dbo.SendMail_Drafts SET name=:name, payload_json=:payload,
                updated_by=:user, updated_at=SYSDATETIME(), revision=revision+1, state=:state
                OUTPUT INSERTED.id, INSERTED.revision
                WHERE id=:id AND branch_id=:branch AND kind=:kind AND revision=:revision AND state <> 'sent'");
            $stmt->execute($params + [':id' => $id, ':revision' => $revision]);
        } else {
            $stmt = Database::pdo()->prepare("INSERT INTO dbo.SendMail_Drafts(branch_id,kind,name,payload_json,updated_by,state)
                OUTPUT INSERTED.id, INSERTED.revision VALUES(:branch,:kind,:name,:payload,:user,:state)");
            $stmt->execute($params);
        }
        $row = $stmt->fetch();
        if (!$row) throw new RuntimeException('Otro usuario modifico o confirmo este borrador. Recargalo antes de continuar; tus cambios no sobrescribieron los suyos.');
        return ['id' => (int) $row['id'], 'revision' => (int) $row['revision']];
    }
}
