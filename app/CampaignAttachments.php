<?php

declare(strict_types=1);

final class CampaignAttachments
{
    public const MAX_FILES = 5;
    public const MAX_BYTES = 10 * 1024 * 1024;

    public static function fromJson(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }
        $files = json_decode($json, true);
        if (!is_array($files)) {
            throw new RuntimeException('No se pudieron leer los adjuntos de la plantilla.');
        }
        return self::validate($files);
    }

    public static function toJson(array $files): string
    {
        return json_encode(self::validate($files), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function validate(array $files): array
    {
        if (count($files) > self::MAX_FILES) {
            throw new InvalidArgumentException('La plantilla admite hasta 5 archivos adjuntos.');
        }
        $total = 0;
        $result = [];
        foreach ($files as $file) {
            if (!is_array($file) || !is_string($file['content'] ?? null) || !is_string($file['name'] ?? null)) {
                throw new InvalidArgumentException('Adjunto invalido.');
            }
            if (strlen($file['content']) > 4 * (int) ceil(self::MAX_BYTES / 3)) {
                throw new InvalidArgumentException('Los adjuntos no pueden superar 10 MB en total.');
            }
            $binary = base64_decode($file['content'], true);
            if ($binary === false || $binary === '') {
                throw new InvalidArgumentException('El adjunto esta vacio o dañado.');
            }
            $total += strlen($binary);
            if ($total > self::MAX_BYTES) {
                throw new InvalidArgumentException('Los adjuntos no pueden superar 10 MB en total.');
            }
            $name = basename(str_replace('\\', '/', $file['name']));
            $name = trim(preg_replace('/[\x00-\x1f\x7f]/', '', $name) ?? '');
            if ($name === '' || $name === '.' || $name === '..' || strlen($name) > 240 || !preg_match('//u', $name)) {
                throw new InvalidArgumentException('El nombre del adjunto no es valido o es demasiado largo.');
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($binary);
            $result[] = [
                'name' => $name,
                'size' => strlen($binary),
                'type' => is_string($mime) ? $mime : 'application/octet-stream',
                'content' => base64_encode($binary),
            ];
        }
        return $result;
    }

    public static function withUploads(array $existing, array $uploads, array $remove): array
    {
        $files = [];
        $total = 0;
        foreach (self::validate($existing) as $index => $file) {
            if (!in_array((string) $index, $remove, true)) {
                $files[] = $file;
                $total += $file['size'];
            }
        }
        if ($uploads !== []) {
            foreach (['name', 'tmp_name', 'error', 'size'] as $key) {
                if (!isset($uploads[$key]) || !is_array($uploads[$key])) {
                    throw new InvalidArgumentException('La carga de adjuntos no es valida.');
                }
            }
            foreach ($uploads['error'] as $index => $error) {
                if ($error === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                    throw new InvalidArgumentException('Un adjunto supera el limite de carga del servidor (' . ini_get('upload_max_filesize') . ').');
                }
                if ($error !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('No se pudo cargar un adjunto. Vuelve a seleccionar los archivos.');
                }
                $path = $uploads['tmp_name'][$index] ?? null;
                $name = $uploads['name'][$index] ?? null;
                if (!is_string($path) || !is_string($name) || !is_uploaded_file($path)) {
                    throw new InvalidArgumentException('El archivo no es una carga valida.');
                }
                $size = filesize($path);
                if ($size === false || $total + $size > self::MAX_BYTES) {
                    throw new InvalidArgumentException('Los adjuntos no pueden superar 10 MB en total.');
                }
                $total += $size;
                if (count($files) >= self::MAX_FILES) {
                    throw new InvalidArgumentException('La plantilla admite hasta 5 archivos adjuntos.');
                }
                $binary = file_get_contents($path);
                if ($binary === false) {
                    throw new RuntimeException('No se pudo leer el adjunto cargado.');
                }
                $files[] = ['name' => $name, 'content' => base64_encode($binary)];
            }
        }
        return self::validate($files);
    }

    public static function addToMail(\PHPMailer\PHPMailer\PHPMailer $mail, array $files): void
    {
        foreach (self::validate($files) as $file) {
            $mail->addStringAttachment(
                (string) base64_decode($file['content'], true),
                $file['name'],
                \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64,
                $file['type']
            );
        }
    }
}
