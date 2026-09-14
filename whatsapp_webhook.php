<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = trim((string) ($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? ''));
    $token = trim((string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? ''));
    $challenge = (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');
    if ($mode === 'subscribe' && $challenge !== '' && Settings::whatsappVerifyTokenMatches($token)) {
        echo $challenge;
        exit;
    }
    http_response_code(403);
    echo 'Verificacion rechazada.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Metodo no permitido.';
    exit;
}

$raw = file_get_contents('php://input');
$payload = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($payload)) {
    http_response_code(400);
    echo 'Payload invalido.';
    exit;
}

$entry = is_array($payload['entry'][0] ?? null) ? $payload['entry'][0] : [];
$change = is_array($entry['changes'][0] ?? null) ? $entry['changes'][0] : [];
$value = is_array($change['value'] ?? null) ? $change['value'] : [];
$wabaId = trim((string) ($entry['id'] ?? ''));
$phoneNumberId = trim((string) ($value['metadata']['phone_number_id'] ?? ''));
$config = Settings::whatsappForWebhook($wabaId, $phoneNumberId);

if (!$config) {
    http_response_code(403);
    echo 'Cuenta no configurada.';
    exit;
}

$appSecret = (string) ($config['app_secret'] ?? '');
$signature = trim((string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''));
$expected = $appSecret !== '' && is_string($raw) ? 'sha256=' . hash_hmac('sha256', $raw, $appSecret) : '';
if ($expected === '' || $signature === '' || !hash_equals($expected, $signature)) {
    http_response_code(403);
    echo 'Firma invalida.';
    exit;
}

$branchId = (int) ($config['branch_id'] ?? 0);
$timezone = new DateTimeZone(date_default_timezone_get());

foreach (($payload['entry'] ?? []) as $payloadEntry) {
    if (!is_array($payloadEntry)) {
        continue;
    }
    foreach (($payloadEntry['changes'] ?? []) as $payloadChange) {
        $changeValue = is_array($payloadChange['value'] ?? null) ? $payloadChange['value'] : [];
        foreach (($changeValue['statuses'] ?? []) as $statusRow) {
            if (!is_array($statusRow)) {
                continue;
            }
            $providerId = trim((string) ($statusRow['id'] ?? ''));
            $status = strtolower(trim((string) ($statusRow['status'] ?? '')));
            $eventAt = null;
            $timestamp = (int) ($statusRow['timestamp'] ?? 0);
            if ($timestamp > 0) {
                $eventAt = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
            }
            $errorParts = [];
            foreach (($statusRow['errors'] ?? []) as $errorRow) {
                if (is_array($errorRow)) {
                    $errorParts[] = trim((string) ($errorRow['title'] ?? $errorRow['message'] ?? $errorRow['code'] ?? ''));
                }
            }
            $statusPayload = json_encode($statusRow, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            WhatsAppRepository::insertEvent($branchId, $providerId, $status, $eventAt, $statusPayload);
            WhatsAppRepository::updateProviderStatus($providerId, $status, $eventAt, implode(' | ', array_filter($errorParts)));
        }

        foreach (($changeValue['messages'] ?? []) as $message) {
            if (!is_array($message)) {
                continue;
            }
            $providerId = trim((string) ($message['id'] ?? ''));
            $from = WhatsAppPhone::normalize((string) ($message['from'] ?? ''), (string) ($config['country_code'] ?? '54'));
            $text = trim((string) (
                $message['text']['body']
                ?? $message['button']['text']
                ?? $message['button']['payload']
                ?? $message['interactive']['button_reply']['title']
                ?? $message['interactive']['button_reply']['id']
                ?? ''
            ));
            $timestamp = (int) ($message['timestamp'] ?? 0);
            $eventAt = $timestamp > 0 ? (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone) : null;
            $messagePayload = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            WhatsAppRepository::insertEvent($branchId, $providerId, 'received', $eventAt, $messagePayload);

            $normalizedText = mb_strtoupper(trim($text), 'UTF-8');
            $asciiText = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalizedText) : false;
            if (is_string($asciiText)) {
                $normalizedText = $asciiText;
            }
            $normalizedText = preg_replace('/[^A-Z0-9 ]/u', '', $normalizedText) ?? $normalizedText;
            $normalizedText = preg_replace('/\s+/', ' ', trim($normalizedText)) ?? $normalizedText;
            if ($from !== null && in_array($normalizedText, ['BAJA', 'STOP', 'CANCELAR', 'CANCELACION', 'NO RECIBIR', 'NO QUIERO RECIBIR'], true)) {
                WhatsAppRepository::recordOptOut($branchId, $from, WhatsAppRepository::SOURCE_CAMPAIGN, 'Solicitud recibida por WhatsApp: ' . $text);
            }
        }
    }
}

echo 'EVENT_RECEIVED';
