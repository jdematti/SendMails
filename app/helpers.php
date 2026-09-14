<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_is_valid(?string $token): bool
{
    return is_string($token) && hash_equals(csrf_token(), $token);
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_is_valid(is_string($token) ? $token : null)) {
        http_response_code(419);
        exit('Token CSRF invalido.');
    }
}

function post_string(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return trim(is_scalar($value) ? (string) $value : $default);
}

function query_string(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    return trim(is_scalar($value) ? (string) $value : $default);
}

function selected(string $value, string $current): string
{
    return $value === $current ? ' selected' : '';
}

function checked(bool $condition): string
{
    return $condition ? ' checked' : '';
}

function format_datetime(?string $value): string
{
    if (!$value) {
        return '-';
    }

    try {
        return (new DateTimeImmutable($value))->format('d/m/Y H:i:s');
    } catch (Throwable $e) {
        return $value;
    }
}

function configure_server_timezone(): void
{
    $timezone = getenv('APP_TIMEZONE') ?: getenv('TZ') ?: detect_windows_timezone();
    if (!$timezone || !in_array($timezone, timezone_identifiers_list(), true)) {
        return;
    }

    date_default_timezone_set($timezone);
}

function detect_windows_timezone(): ?string
{
    if (PHP_OS_FAMILY !== 'Windows' || !function_exists('exec')) {
        return null;
    }

    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (in_array('exec', $disabled, true)) {
        return null;
    }

    $output = [];
    $code = 1;
    @exec('tzutil /g 2>NUL', $output, $code);
    if ($code !== 0 || empty($output[0])) {
        return null;
    }

    $map = [
        'Argentina Standard Time' => 'America/Argentina/Buenos_Aires',
        'SA Eastern Standard Time' => 'America/Cayenne',
        'E. South America Standard Time' => 'America/Sao_Paulo',
        'Montevideo Standard Time' => 'America/Montevideo',
        'Paraguay Standard Time' => 'America/Asuncion',
        'Pacific SA Standard Time' => 'America/Santiago',
        'Central Standard Time' => 'America/Chicago',
        'Eastern Standard Time' => 'America/New_York',
        'Mountain Standard Time' => 'America/Denver',
        'Pacific Standard Time' => 'America/Los_Angeles',
        'UTC' => 'UTC',
        'GMT Standard Time' => 'Europe/London',
        'W. Europe Standard Time' => 'Europe/Berlin',
        'Romance Standard Time' => 'Europe/Paris',
    ];

    $windowsId = trim($output[0]);
    return $map[$windowsId] ?? null;
}

function repair_mojibake(?string $value): string
{
    $text = (string) $value;
    $markerPattern = '/(?:\x{00C3}|\x{00C2}|\x{00E2})/u';
    if ($text === '' || !preg_match($markerPattern, $text)) {
        return $text;
    }

    $fixed = preg_replace_callback(
        '/[\x{00C2}\x{00C3}\x{00E2}][\x{0080}-\x{FFFF}]{1,2}/u',
        static function (array $match): string {
            foreach (['Windows-1252', 'ISO-8859-1'] as $encoding) {
                $candidate = @iconv('UTF-8', $encoding . '//IGNORE', $match[0]);
                if ($candidate !== false && mb_check_encoding($candidate, 'UTF-8')) {
                    return $candidate;
                }
            }

            return $match[0];
        },
        $text
    );

    if (!is_string($fixed)) {
        return $text;
    }

    $originalMarkers = preg_match_all($markerPattern, $text);
    $fixedMarkers = preg_match_all($markerPattern, $fixed);

    return $fixedMarkers < $originalMarkers ? $fixed : $text;
}

function normalize_int(string $value, int $default, int $min = 0, int $max = PHP_INT_MAX): int
{
    if (!is_numeric($value)) {
        return $default;
    }

    return max($min, min($max, (int) $value));
}
