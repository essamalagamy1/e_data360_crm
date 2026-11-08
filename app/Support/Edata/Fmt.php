<?php

namespace App\Support\Edata;

use App\Models\Utility;

/**
 * Formats that read company settings (currency symbol, invoice prefix). The
 * settings are loaded once per company for the request, which replaces the many
 * repeated settings queries a single old page used to run.
 */
class Fmt
{
    private static array $settings = [];

    public static function settings(int $companyId): array
    {
        return self::$settings[$companyId] ??= Utility::settingsById($companyId);
    }

    public static function flush(): void
    {
        self::$settings = [];
    }

    public static function money($amount, int $companyId): string
    {
        $settings = self::settings($companyId);
        $decimals = (int) ($settings['decimal_number'] ?? 2);
        $symbol = $settings['site_currency_symbol'] ?? ($settings['site_currency'] ?? '');
        $number = number_format((float) $amount, $decimals, '.', ',');

        return ($settings['site_currency_symbol_position'] ?? 'pre') === 'pre'
            ? $symbol . ' ' . $number
            : $number . ' ' . $symbol;
    }

    public static function invoiceNumber($number, int $companyId): string
    {
        $settings = self::settings($companyId);

        return ($settings['invoice_prefix'] ?? '#INVO') . sprintf('%05d', (int) $number);
    }

    /**
     * A file under public/ with its change time in the link, so every deployment
     * makes browsers fetch the new version instead of an old cached copy.
     */
    public static function asset(string $path): string
    {
        $time = @filemtime(public_path($path));

        return asset($path) . '?v=' . ($time ?: '1');
    }

    public static function timezone(int $companyId): string
    {
        // An empty setting is common on a fresh company and must not reach Carbon.
        return (self::settings($companyId)['timezone'] ?? '') ?: (config('app.timezone') ?: 'UTC');
    }
}
