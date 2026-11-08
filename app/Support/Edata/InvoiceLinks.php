<?php

namespace App\Support\Edata;

use App\Models\Invoice;
use App\Models\Utility;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Milon\Barcode\DNS2D;

/**
 * The link a client opens without logging in, for example /i/INVO00327-K7X2M9QPLT3A.
 *
 * The number makes the link recognisable and is ignored when it is opened. Only the
 * random part finds the invoice, so numbers cannot be walked to reach other clients.
 */
class InvoiceLinks
{
    // No 0/O, 1/I/L: the link has to survive being read out or retyped.
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const LENGTH = 12;

    /** A draft has not been issued yet, so it has no client-facing page. */
    public static function isShareable(Invoice $invoice): bool
    {
        return (int) $invoice->status !== 0 || $invoice->cancelled_at;
    }

    public static function url(Invoice $invoice): ?string
    {
        if (!self::isShareable($invoice)) {
            return null;
        }

        $token = $invoice->public_token ?: self::issue($invoice);

        return route('edata.invoice.public', self::slug($invoice, $token));
    }

    /**
     * Written straight to the table: a link is not an edit of the invoice, so it must
     * not move updated_at or add a row to the invoice's change history.
     */
    public static function issue(Invoice $invoice): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $token = self::randomToken();

            try {
                DB::table('invoices')->where('id', $invoice->id)->update(['public_token' => $token]);
                $invoice->public_token = $token;
                $invoice->syncOriginalAttribute('public_token');

                return $token;
            } catch (QueryException $e) {
                // Almost impossible collision on the unique index: draw again.
                continue;
            }
        }

        throw new \RuntimeException('Could not create an invoice link.');
    }

    /** Replaces the link. The previous one, and any old encrypted link, stop working. */
    public static function rotate(Invoice $invoice): string
    {
        $token = self::issue($invoice);
        $now = now();
        DB::table('invoices')->where('id', $invoice->id)->update(['public_token_rotated_at' => $now]);
        $invoice->public_token_rotated_at = $now;
        $invoice->syncOriginalAttribute('public_token_rotated_at');

        return $token;
    }

    public static function slug(Invoice $invoice, string $token): string
    {
        $number = preg_replace('/[^A-Za-z0-9]/', '', Fmt::invoiceNumber($invoice->invoice_id, (int) $invoice->created_by));

        return ($number !== '' ? $number . '-' : '') . $token;
    }

    /** The part of a slug that identifies the invoice. */
    public static function tokenFrom(string $slug): string
    {
        $parts = explode('-', $slug);

        return (string) end($parts);
    }

    private static function randomToken(): string
    {
        $token = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::LENGTH; $i++) {
            $token .= self::ALPHABET[random_int(0, $max)];
        }

        return $token;
    }

    /**
     * The QR code as one compact SVG path, so it stays sharp in print and in a PDF.
     * Returns null rather than breaking the page if the code cannot be drawn.
     */
    public static function qr(string $text): ?string
    {
        try {
            $svg = (new DNS2D())->getBarcodeSVG($text, 'QRCODE,M', 1, 1, '#002D59');
        } catch (\Throwable $e) {
            return null;
        }

        if (!preg_match('/<svg width="(\d+)"/', $svg, $size)) {
            return null;
        }
        preg_match_all('/<rect x="(\d+)" y="(\d+)"/', $svg, $cells, PREG_SET_ORDER);
        if (!$cells) {
            return null;
        }

        $path = '';
        foreach ($cells as $cell) {
            $path .= 'M' . $cell[1] . ' ' . $cell[2] . 'h1v1h-1z';
        }

        $n = (int) $size[1];

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $n . ' ' . $n . '" width="' . ($n * 4) . '" height="' . ($n * 4) . '" shape-rendering="crispEdges" role="img" aria-hidden="true"><path fill="#002D59" d="' . $path . '"/></svg>';
    }

    /** True when the company has switched on any online payment gateway. */
    public static function hasOnlinePayment(int $companyId): bool
    {
        try {
            $settings = Utility::getCompanyPaymentSetting($companyId);
        } catch (\Throwable $e) {
            return false;
        }

        foreach ((array) $settings as $name => $value) {
            if (str_starts_with((string) $name, 'is_') && str_ends_with((string) $name, '_enabled') && $value === 'on') {
                return true;
            }
        }

        return false;
    }
}
