<?php
declare(strict_types=1);

final class TemplateDraft
{
    public static function load(string $kind, int $draftId, int $sourceId, array $template): array
    {
        if ($draftId > 0) {
            $draft = DraftRepository::find($draftId, $kind);
            if ($draft['state'] === 'sent') throw new RuntimeException('Este borrador ya fue publicado.');
            return $draft['payload'] + ['kind' => $kind, 'id' => $draftId, 'revision' => (int) $draft['revision']];
        }
        return ['kind' => $kind, 'id' => 0, 'revision' => 0, 'source_id' => $sourceId,
            'base_version' => (string) ($template['content_revision'] ?? ''), 'template' => $template];
    }

    public static function store(array &$context, array $template, int $expectedRevision): void
    {
        $context['revision'] = $expectedRevision;
        $payload = ['source_id' => $context['source_id'], 'base_version' => $context['base_version'], 'template' => $template];
        $saved = DraftRepository::save($context['id'], $context['kind'], (string) ($template['name'] ?? ''), $payload, $expectedRevision);
        $context = array_replace($context, $payload, $saved);
    }

    public static function publish(array $context, callable $save): int
    {
        $pdo = Database::pdo(); $pdo->beginTransaction();
        try {
            $draft = DraftRepository::find($context['id'], $context['kind'], true);
            if ($draft['state'] === 'sent' || (int) $draft['revision'] !== $context['revision']) {
                throw new RuntimeException('Otro usuario modifico o publico este borrador. Recargalo.');
            }
            if ($context['source_id'] > 0) {
                $table = $context['kind'] === 'template' ? 'SendMail_Templates' : 'SendMail_InvoiceTemplates';
                $stmt = $pdo->prepare("SELECT content_revision FROM dbo.$table WITH (UPDLOCK,HOLDLOCK) WHERE id=:id AND branch_id=:branch");
                $stmt->execute([':id' => $context['source_id'], ':branch' => BranchRepository::currentId()]);
                $version = $stmt->fetchColumn();
                if ($version === false || (string) $version !== $context['base_version']) throw new RuntimeException('La plantilla publicada cambio desde que se inicio este borrador. Crea una copia para conservar ambas versiones.');
            }
            $id = $save();
            DraftRepository::save($context['id'], $context['kind'], (string) $context['template']['name'],
                ['source_id' => $id], $context['revision'], 'sent');
            $pdo->commit(); return $id;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
