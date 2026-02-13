<?php

namespace App\Support;

class PhoneNumber
{
    /**
     * Normalize a phone number into E.164 format.
     *
     * Best-effort rules (no external carrier validation):
     * - Accepts + prefixed international numbers (8-15 digits)
     * - Accepts US local 10-digit numbers and normalizes to +1
     * - Accepts US 11-digit numbers starting with 1 and normalizes to +1...
     */
    public static function normalize(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        // Keep only digits and leading plus semantics.
        $sanitized = preg_replace('/[^\d+]/', '', $trimmed) ?? '';

        if ($sanitized === '') {
            return null;
        }

        // Convert common international prefix 00 to +
        if (str_starts_with($sanitized, '00')) {
            $sanitized = '+'.substr($sanitized, 2);
        }

        // Ensure plus appears only at the beginning.
        if (str_contains(substr($sanitized, 1), '+')) {
            return null;
        }

        if (! str_starts_with($sanitized, '+')) {
            $digits = preg_replace('/\D/', '', $sanitized) ?? '';

            if (strlen($digits) === 10) {
                return '+1'.$digits;
            }

            if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
                return '+'.$digits;
            }

            return null;
        }

        $digits = substr($sanitized, 1);

        if (! preg_match('/^[1-9]\d{7,14}$/', $digits)) {
            return null;
        }

        return '+'.$digits;
    }

    public static function mask(?string $e164): ?string
    {
        if (! $e164) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $e164) ?? '';

        if (strlen($digits) < 4) {
            return null;
        }

        $countryLength = max(strlen($digits) - 10, 1);
        $country = substr($digits, 0, $countryLength);
        $last4 = substr($digits, -4);

        return sprintf('+%s••••••%s', $country, $last4);
    }
}
