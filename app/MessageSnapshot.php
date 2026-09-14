<?php
declare(strict_types=1);

final class MessageSnapshot
{
    private const FIELDS = ['subject', 'html_body', 'attachments_json', 'from_email', 'from_name',
        'reply_to', 'bcc', 'web', 'loc_prefix', 'domicilio', 'facebook', 'instagram', 'whatsapp',
        'test_mode', 'test_email', 'language', 'category', 'components_json', 'body_variables_json'];

    public static function capture(array $template, bool $whatsApp = false): string
    {
        $snapshot = array_intersect_key($template, array_flip(self::FIELDS));
        if ($whatsApp) {
            $snapshot['template_name'] = (string) ($template['name'] ?? $template['template_name'] ?? '');
            $snapshot['template_status'] = (string) ($template['status'] ?? $template['template_status'] ?? '');
        }
        return json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function apply(array $row): array
    {
        $snapshot = json_decode((string) ($row['message_snapshot'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($snapshot)) {
            throw new RuntimeException('El envio no tiene una copia valida de su mensaje. Ejecuta la migracion.');
        }
        $allowed = array_flip(array_merge(self::FIELDS, ['template_name', 'template_status']));
        unset($row['message_snapshot']);
        return array_replace($row, array_intersect_key($snapshot, $allowed));
    }
}
