<?php

declare(strict_types=1);

final class WhatsAppBusinessService
{
    public static function assertConfigured(array $config): void
    {
        if (empty($config['is_enabled'])) {
            throw new RuntimeException('WhatsApp no esta habilitado para la sucursal.');
        }
        foreach (['graph_version', 'waba_id', 'phone_number_id', 'access_token'] as $key) {
            if (trim((string) ($config[$key] ?? '')) === '') {
                throw new RuntimeException('La configuracion de WhatsApp esta incompleta: falta ' . $key . '.');
            }
        }
    }

    public static function testConnection(array $config): array
    {
        self::assertConfigured($config);
        return self::request(
            $config,
            'GET',
            '/' . rawurlencode((string) $config['phone_number_id']),
            null,
            ['fields' => 'verified_name,display_phone_number,quality_rating,name_status']
        );
    }

    public static function fetchTemplates(array $config): array
    {
        self::assertConfigured($config);
        $templates = [];
        $path = '/' . rawurlencode((string) $config['waba_id']) . '/message_templates';
        $query = [
            'fields' => 'id,name,status,category,language,components',
            'limit' => '250',
        ];

        for ($page = 0; $page < 20; $page++) {
            $response = self::request($config, 'GET', $path, null, $query);
            foreach (($response['data'] ?? []) as $template) {
                if (is_array($template)) {
                    $templates[] = $template;
                }
            }
            $next = (string) ($response['paging']['next'] ?? '');
            if ($next === '') {
                break;
            }
            $path = $next;
            $query = [];
        }

        return $templates;
    }

    public static function sendTemplate(array $config, string $phone, array $template, array $context): array
    {
        self::assertConfigured($config);
        $phone = WhatsAppPhone::normalizeRequired($phone, (string) ($config['country_code'] ?? '54'));
        $name = trim((string) ($template['name'] ?? ''));
        $language = trim((string) ($template['language'] ?? ''));
        if ($name === '' || $language === '') {
            throw new RuntimeException('La plantilla de WhatsApp no tiene nombre o idioma.');
        }

        $variables = WhatsAppRepository::templateVariables($template);
        $expected = WhatsAppRepository::bodyParameterCount($template);
        if ($expected !== count($variables)) {
            throw new RuntimeException('La plantilla ' . $name . ' requiere ' . $expected . ' variables y tiene ' . count($variables) . ' configuradas.');
        }

        $parameters = [];
        foreach ($variables as $variable) {
            $parameters[] = [
                'type' => 'text',
                'text' => self::contextValue($context, $variable),
            ];
        }

        $templatePayload = [
            'name' => $name,
            'language' => ['code' => $language],
        ];
        if ($parameters) {
            $templatePayload['components'] = [[
                'type' => 'body',
                'parameters' => $parameters,
            ]];
        }

        return self::request(
            $config,
            'POST',
            '/' . rawurlencode((string) $config['phone_number_id']) . '/messages',
            [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $phone,
                'type' => 'template',
                'template' => $templatePayload,
            ]
        );
    }

    public static function sendHelloWorld(array $config, string $phone): array
    {
        self::assertConfigured($config);
        $phone = WhatsAppPhone::normalizeRequired($phone, (string) ($config['country_code'] ?? '54'));
        return self::request(
            $config,
            'POST',
            '/' . rawurlencode((string) $config['phone_number_id']) . '/messages',
            [
                'messaging_product' => 'whatsapp',
                'to' => $phone,
                'type' => 'template',
                'template' => [
                    'name' => 'hello_world',
                    'language' => ['code' => 'en_US'],
                ],
            ]
        );
    }

    private static function contextValue(array $context, string $variable): string
    {
        $aliases = [
            'nombre' => 'client_name',
            'razon_social' => 'razon_social',
            'codigo_cliente' => 'codigo_cliente',
            'plan' => 'plan_contratado',
            'telefono' => 'telefono_movil',
            'importe' => 'amount_label',
            'vencimiento' => 'due_date_label',
            'url_factura' => 'invoice_url',
        ];
        $key = $aliases[$variable] ?? $variable;
        $value = $context[$key] ?? '';
        if (is_scalar($value)) {
            return trim((string) $value);
        }
        return '';
    }

    private static function request(array $config, string $method, string $path, ?array $payload = null, array $query = []): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('La extension cURL de PHP es necesaria para WhatsApp.');
        }

        if (preg_match('#^https://graph\.facebook\.com/#', $path)) {
            $url = $path;
        } else {
            $url = 'https://graph.facebook.com/' . rawurlencode((string) $config['graph_version']) . $path;
            if ($query) {
                $url .= '?' . http_build_query($query);
            }
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('No se pudo iniciar la conexion con Meta.');
        }

        $headers = [
            'Authorization: Bearer ' . (string) $config['access_token'],
            'Accept: application/json',
        ];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) {
                throw new RuntimeException('No se pudo preparar la solicitud a Meta.');
            }
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $headers;
            $options[CURLOPT_POSTFIELDS] = $json;
        }
        curl_setopt_array($handle, $options);
        $raw = curl_exec($handle);
        $curlError = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if (!is_string($raw)) {
            throw new WhatsAppApiException('No se pudo conectar con Meta: ' . $curlError, 0);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }
        if ($status < 200 || $status >= 300) {
            $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
            $message = trim((string) ($error['message'] ?? 'Error HTTP ' . $status . ' de Meta.'));
            $code = trim((string) ($error['code'] ?? ''));
            if ($code !== '') {
                $message .= ' (codigo ' . $code . ')';
            }
            throw new WhatsAppApiException($message, $status, $error);
        }

        return $decoded;
    }
}
