<?php

declare(strict_types=1);

final class WhatsAppPhone
{
    public static function normalize(string $value, string $countryCode = '54'): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $countryCode = preg_replace('/\D+/', '', $countryCode) ?: '54';
        $compact = preg_replace('/[^0-9+]+/', '', $value) ?: '';
        if (strncmp($compact, '00', 2) === 0) {
            $compact = '+' . substr($compact, 2);
        }
        $digits = preg_replace('/\D+/', '', $compact) ?: '';

        if ($countryCode !== '54') {
            if (strncmp($digits, $countryCode, strlen($countryCode)) === 0) {
                return self::validInternational($digits) ? $digits : null;
            }
            $digits = ltrim($digits, '0');
            $candidate = $countryCode . $digits;
            return self::validInternational($candidate) ? $candidate : null;
        }

        if (strncmp($digits, '549', 3) === 0 && strlen($digits) === 13) {
            return $digits;
        }

        if (strncmp($digits, '54', 2) === 0) {
            $digits = substr($digits, 2);
        }
        $digits = ltrim($digits, '0');

        if (strncmp($digits, '9', 1) === 0 && strlen($digits) === 11) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) === 12) {
            $withoutLocalMobilePrefix = self::removeLocalMobilePrefix($value, $digits);
            if ($withoutLocalMobilePrefix !== null) {
                $digits = $withoutLocalMobilePrefix;
            }
        }

        if (strlen($digits) !== 10 || $digits[0] === '0' || strncmp($digits, '15', 2) === 0) {
            return null;
        }

        return '549' . $digits;
    }

    public static function normalizeRequired(string $value, string $countryCode = '54'): string
    {
        $normalized = self::normalize($value, $countryCode);
        if ($normalized === null) {
            throw new InvalidArgumentException('Numero de WhatsApp invalido: ' . trim($value));
        }
        return $normalized;
    }

    private static function removeLocalMobilePrefix(string $raw, string $digits): ?string
    {
        $normalizedRaw = preg_replace('/^\+?54\s*/', '', trim($raw)) ?: trim($raw);
        if (preg_match('/^0?([0-9]{2,4})[\s().-]*15[\s().-]*([0-9]{6,8})$/', $normalizedRaw, $matches)) {
            $candidate = $matches[1] . $matches[2];
            return strlen($candidate) === 10 ? $candidate : null;
        }

        foreach ([4, 3, 2] as $areaLength) {
            if (substr($digits, $areaLength, 2) !== '15') {
                continue;
            }
            $candidate = substr($digits, 0, $areaLength) . substr($digits, $areaLength + 2);
            if (strlen($candidate) === 10) {
                return $candidate;
            }
        }
        return null;
    }

    private static function validInternational(string $digits): bool
    {
        $length = strlen($digits);
        return $length >= 8 && $length <= 15 && $digits[0] !== '0';
    }
}
