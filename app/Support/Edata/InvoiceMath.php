<?php

namespace App\Support\Edata;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Tax;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Invoice arithmetic in one place. Billed value and collected money are kept
 * apart on purpose: revenue always comes from payment rows, so a paid invoice
 * can never be counted a second time.
 */
class InvoiceMath
{
    private static array $rates = [];

    public static function rates(int $companyId): array
    {
        return self::$rates[$companyId] ??= Tax::where('created_by', $companyId)->pluck('rate', 'id')->map(fn ($r) => (float) $r)->all();
    }

    public static function flush(): void
    {
        self::$rates = [];
    }

    /** Loads what is needed for a set of invoices and attaches a ->calc array to each. */
    public static function attach(Collection $invoices, int $companyId): Collection
    {
        if ($invoices->isEmpty()) {
            return $invoices;
        }

        // Accepts a plain collection too; the models inside stay the same instances.
        (new EloquentCollection($invoices->all()))->loadMissing(['items', 'payments']);

        $credits = CreditNote::whereIn('invoice', $invoices->pluck('id'))
            ->selectRaw('invoice, SUM(amount) as total')
            ->groupBy('invoice')
            ->pluck('total', 'invoice');

        $rates = self::rates($companyId);

        foreach ($invoices as $invoice) {
            $invoice->calc = self::calculate($invoice, $rates, (float) ($credits[$invoice->id] ?? 0));
        }

        return $invoices;
    }

    public static function calculate(Invoice $invoice, array $rates, float $credit = 0): array
    {
        $subtotal = 0.0;
        $discount = 0.0;
        $tax = 0.0;

        foreach ($invoice->items as $item) {
            $lineGross = (float) $item->price * (float) $item->quantity;
            $lineDiscount = (float) $item->discount;
            $subtotal += $lineGross;
            $discount += $lineDiscount;

            foreach (array_filter(explode(',', (string) $item->tax)) as $taxId) {
                $tax += (($rates[(int) $taxId] ?? 0) / 100) * ($lineGross - $lineDiscount);
            }
        }

        $total = $subtotal - $discount + $tax;
        $paid = (float) $invoice->payments->sum('amount');
        $due = round($total - $paid - $credit, 2);

        return [
            'subtotal' => round($subtotal, 2),
            'discount' => round($discount, 2),
            'tax' => round($tax, 2),
            'total' => round($total, 2),
            'paid' => round($paid, 2),
            'credit' => round($credit, 2),
            'due' => $due,
            'state' => self::state($invoice, $due, $paid),
        ];
    }

    /**
     * The state a person should see. The stored status alone is not enough: the old
     * edit screen set any edited invoice to "partially paid", so that status only
     * counts when a payment row really exists.
     */
    public static function state(Invoice $invoice, ?float $due = null, float $paid = 0): string
    {
        if ($invoice->cancelled_at) {
            return 'cancelled';
        }

        $status = (int) $invoice->status;

        if ($status === 0) {
            return 'draft';
        }
        if ($status === 4 || ($due !== null && $due <= 0)) {
            return 'paid';
        }
        if (Carbon::parse($invoice->due_date)->endOfDay()->isPast()) {
            return 'overdue';
        }

        return $paid > 0 ? 'partial' : 'sent';
    }

    /** Filters by computed state using only columns, so pagination stays in SQL. */
    public static function applyStateFilter($query, string $state)
    {
        $today = Carbon::today()->toDateString();
        $hasPayment = fn ($q) => $q->selectRaw('1')->from('invoice_payments')->whereColumn('invoice_payments.invoice_id', 'invoices.id');

        return match ($state) {
            'cancelled' => $query->whereNotNull('invoices.cancelled_at'),
            'draft' => $query->whereNull('invoices.cancelled_at')->where('invoices.status', 0),
            'paid' => $query->whereNull('invoices.cancelled_at')->where('invoices.status', 4),
            'overdue' => $query->whereNull('invoices.cancelled_at')->whereIn('invoices.status', [1, 2, 3])->whereDate('invoices.due_date', '<', $today),
            'partial' => $query->whereNull('invoices.cancelled_at')->whereIn('invoices.status', [1, 2, 3])->whereDate('invoices.due_date', '>=', $today)->whereExists($hasPayment),
            'sent' => $query->whereNull('invoices.cancelled_at')->whereIn('invoices.status', [1, 2, 3])->whereDate('invoices.due_date', '>=', $today)->whereNotExists($hasPayment),
            default => $query,
        };
    }

    /**
     * Company-wide or client-wide money summary.
     * Draft and cancelled invoices are not billed yet, so they stay out of the totals.
     */
    public static function summary($invoiceQuery, int $companyId): array
    {
        $invoices = (clone $invoiceQuery)->whereNull('invoices.cancelled_at')->where('invoices.status', '!=', 0)->get();
        self::attach($invoices, $companyId);

        $billed = 0.0;
        $collected = 0.0;
        $due = 0.0;
        $overdueAmount = 0.0;
        $states = ['sent' => 0, 'partial' => 0, 'paid' => 0, 'overdue' => 0];

        foreach ($invoices as $invoice) {
            $calc = $invoice->calc;
            $billed += $calc['total'];
            $collected += $calc['paid'];
            $due += max(0, $calc['due']);
            $states[$calc['state']] = ($states[$calc['state']] ?? 0) + 1;
            if ($calc['state'] === 'overdue') {
                $overdueAmount += max(0, $calc['due']);
            }
        }

        return [
            'count' => $invoices->count(),
            'billed' => round($billed, 2),
            'collected' => round($collected, 2),
            'due' => round($due, 2),
            'overdue_amount' => round($overdueAmount, 2),
            'states' => $states,
        ];
    }

    /** Money actually received between two dates, from payment rows only. */
    public static function collectedBetween(int $companyId, $from, $to, ?array $invoiceIds = null): float
    {
        $invoices = Invoice::where('created_by', $companyId)->select('id');

        $query = InvoicePayment::whereIn('invoice_id', $invoiceIds ?? $invoices)
            ->whereBetween('date', [Carbon::parse($from)->toDateString(), Carbon::parse($to)->toDateString()]);

        return round((float) $query->sum('amount'), 2);
    }
}
