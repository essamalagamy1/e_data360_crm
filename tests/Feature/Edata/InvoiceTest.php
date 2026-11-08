<?php

namespace Tests\Feature\Edata;

use App\Models\BankAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\ProductService;
use App\Support\Edata\Access;
use App\Support\Edata\InvoiceMath;
use Illuminate\Support\Facades\DB;

class InvoiceTest extends EdataTestCase
{
    private function pay(Invoice $invoice, float $amount, ?int $bank = null)
    {
        return $this->actingAs($this->owner)->post(route('edata.invoices.payment', $invoice->id), [
            'date' => now()->toDateString(),
            'amount' => $amount,
            'account_id' => $bank ?? $this->bank(),
        ]);
    }

    private function category(): int
    {
        return DB::table('product_service_categories')->insertGetId(['name' => 'Income', 'type' => 'income', 'chart_account_id' => 0, 'color' => '#000', 'created_by' => $this->company, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_creating_an_invoice_with_lines_calculates_totals(): void
    {
        $customer = $this->customer();
        $product = $this->product(500);

        $this->actingAs($this->owner)->post(route('edata.invoices.store'), [
            'customer_id' => $customer->id,
            'issue_date' => '2026-11-01',
            'due_date' => '2026-11-15',
            'category_id' => $this->category(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'price' => 500, 'discount' => 100, 'description' => 'first'],
                ['product_id' => $product->id, 'quantity' => 1, 'price' => 200, 'discount' => 0, 'description' => 'second'],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $invoice = Invoice::where('customer_id', $customer->id)->with('items', 'payments')->firstOrFail();
        InvoiceMath::attach(collect([$invoice]), $this->company);

        $this->assertSame(0, (int) $invoice->status);
        $this->assertSame(1200.0, $invoice->calc['subtotal']);
        $this->assertSame(100.0, $invoice->calc['discount']);
        $this->assertSame(1100.0, $invoice->calc['total']);
        $this->assertSame('draft', $invoice->calc['state']);
    }

    public function test_tax_is_calculated_on_the_line_after_discount(): void
    {
        $tax = DB::table('taxes')->insertGetId(['name' => 'VAT', 'rate' => '15', 'created_by' => $this->company, 'created_at' => now(), 'updated_at' => now()]);
        InvoiceMath::flush();
        $invoice = $this->invoice(1000);
        DB::table('invoice_products')->where('invoice_id', $invoice->id)->update(['tax' => (string) $tax, 'discount' => 100, 'quantity' => 2]);

        $invoice = Invoice::with('items', 'payments')->find($invoice->id);
        InvoiceMath::attach(collect([$invoice]), $this->company);

        // (2 x 1000 - 100) = 1900, plus 15% = 285
        $this->assertSame(285.0, $invoice->calc['tax']);
        $this->assertSame(2185.0, $invoice->calc['total']);
    }

    public function test_multiple_payments_reduce_due_until_paid(): void
    {
        $invoice = $this->invoice(1000);
        $bank = $this->bank();

        $this->pay($invoice, 400, $bank)->assertSessionHas('success');
        $this->assertSame(3, (int) $invoice->fresh()->status);

        $fresh = Invoice::with('items', 'payments')->find($invoice->id);
        InvoiceMath::attach(collect([$fresh]), $this->company);
        $this->assertSame(600.0, $fresh->calc['due']);
        $this->assertSame('partial', $fresh->calc['state']);

        $this->pay($invoice, 600, $bank)->assertSessionHas('success');
        $this->assertSame(4, (int) $invoice->fresh()->status);
        $this->assertSame(2, InvoicePayment::where('invoice_id', $invoice->id)->count());
        $this->assertEqualsWithDelta(1000.0, BankAccount::find($bank)->opening_balance, 0.01);
    }

    public function test_paying_more_than_due_is_refused(): void
    {
        $invoice = $this->invoice(1000);

        $this->pay($invoice, 1000.5)->assertSessionHas('error');

        $this->assertSame(0, InvoicePayment::where('invoice_id', $invoice->id)->count());
        $this->assertSame(1, (int) $invoice->fresh()->status);
    }

    public function test_revenue_counts_payments_once_and_keeps_billed_separate(): void
    {
        $partly = $this->invoice(1000);
        $full = $this->invoice(2000);
        $unpaid = $this->invoice(500);
        $bank = $this->bank();
        $this->pay($partly, 300, $bank);
        $this->pay($full, 2000, $bank);

        $ids = [$partly->id, $full->id, $unpaid->id];
        $summary = InvoiceMath::summary(Access::invoices($this->owner)->whereIn('invoices.id', $ids), $this->company);

        $this->assertSame(3500.0, $summary['billed']);
        $this->assertSame(2300.0, $summary['collected']);
        $this->assertSame(1200.0, $summary['due']);

        $revenue = InvoiceMath::collectedBetween($this->company, now()->subDay(), now()->addDay(), $ids);
        $this->assertSame(2300.0, $revenue);
    }

    public function test_draft_and_cancelled_invoices_stay_out_of_totals(): void
    {
        $draft = $this->invoice(1000, 0);
        $sent = $this->invoice(700);
        $cancelled = $this->invoice(9000);

        $this->actingAs($this->owner)->post(route('edata.invoices.cancel', $cancelled->id))->assertSessionHas('success');

        $summary = InvoiceMath::summary(Access::invoices($this->owner)->whereIn('invoices.id', [$draft->id, $sent->id, $cancelled->id]), $this->company);

        $this->assertSame(700.0, $summary['billed']);
        $this->assertNotNull($cancelled->fresh()->cancelled_at);
    }

    public function test_old_partial_status_without_payment_row_is_shown_as_sent(): void
    {
        // The old edit screen stamped status 3 on edited invoices with no money received.
        $invoice = $this->invoice(1000, 3);

        $fresh = Invoice::with('items', 'payments')->find($invoice->id);
        InvoiceMath::attach(collect([$fresh]), $this->company);

        $this->assertSame('sent', $fresh->calc['state']);
    }

    public function test_overdue_comes_from_due_date(): void
    {
        $invoice = $this->invoice(1000, 1, ['issue_date' => now()->subDays(30)->toDateString(), 'due_date' => now()->subDay()->toDateString()]);

        $fresh = Invoice::with('items', 'payments')->find($invoice->id);
        InvoiceMath::attach(collect([$fresh]), $this->company);
        $this->assertSame('overdue', $fresh->calc['state']);

        $listed = InvoiceMath::applyStateFilter(Access::invoices($this->owner)->where('invoices.id', $invoice->id), 'overdue')->count();
        $this->assertSame(1, $listed);
    }

    public function test_removing_a_payment_restores_due_status_and_balance(): void
    {
        $invoice = $this->invoice(1000);
        $bank = $this->bank();
        $this->pay($invoice, 1000, $bank);
        $payment = InvoicePayment::where('invoice_id', $invoice->id)->firstOrFail();

        $this->actingAs($this->owner)->delete(route('edata.invoices.payment.remove', [$invoice->id, $payment->id]))->assertSessionHas('success');

        $this->assertSame(1, (int) $invoice->fresh()->status);
        $this->assertSame(0, InvoicePayment::where('invoice_id', $invoice->id)->count());
        $this->assertEqualsWithDelta(0.0, BankAccount::find($bank)->opening_balance, 0.01);
    }

    public function test_invoice_with_payments_cannot_be_cancelled_or_have_its_lines_changed(): void
    {
        $invoice = $this->invoice(1000);
        $this->pay($invoice, 100);
        $category = $this->category();

        $this->actingAs($this->owner)->post(route('edata.invoices.cancel', $invoice->id))->assertSessionHas('error');
        $this->assertNull($invoice->fresh()->cancelled_at);

        $this->actingAs($this->owner)->put(route('edata.invoices.update', $invoice->id), [
            'customer_id' => $this->customer()->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(20)->toDateString(),
            'notes' => 'updated note',
            'category_id' => $category,
            'items' => [['product_id' => $this->product()->id, 'quantity' => 9, 'price' => 1, 'discount' => 0]],
        ])->assertSessionHas('success');

        $fresh = Invoice::with('items')->find($invoice->id);
        $this->assertSame('updated note', $fresh->notes);
        $this->assertSame((int) $invoice->customer_id, (int) $fresh->customer_id);
        $this->assertSame(1000.0, (float) $fresh->items->first()->price);
    }

    public function test_only_drafts_can_be_deleted(): void
    {
        $sent = $this->invoice(1000);
        $draft = $this->invoice(1000, 0);

        $this->actingAs($this->owner)->delete(route('edata.invoices.destroy', $sent->id))->assertSessionHas('error');
        $this->assertNotNull(Invoice::find($sent->id));

        $this->actingAs($this->owner)->delete(route('edata.invoices.destroy', $draft->id))->assertSessionHas('success');
        $this->assertNull(Invoice::find($draft->id));
    }

    public function test_used_product_cannot_be_deleted_but_can_be_disabled(): void
    {
        $invoice = $this->invoice(1000);
        $product = ProductService::find($invoice->items()->first()->product_id);

        $this->actingAs($this->owner)->delete(route('edata.products.destroy', $product->id))->assertSessionHas('error');
        $this->assertNotNull(ProductService::find($product->id));

        $this->actingAs($this->owner)->post(route('edata.products.toggle', $product->id))->assertSessionHas('success');
        $this->assertFalse((bool) $product->fresh()->is_active);
        $this->assertNotNull($invoice->fresh()->items()->first());
    }

    public function test_invoice_screen_of_another_customer_scope_is_blocked(): void
    {
        $accountant = $this->staff(['manage invoice', 'show invoice']);
        $invoice = $this->invoice();

        // Scope "own" means only invoices on projects this user belongs to.
        $this->actingAs($accountant)->get(route('edata.invoices.show', $invoice->id))->assertNotFound();

        $project = $this->project([], [$accountant]);
        $invoice->project_id = $project->id;
        $invoice->save();
        \App\Support\Edata\Access::flushCache();

        $this->actingAs($accountant)->get(route('edata.invoices.show', $invoice->id))->assertOk();
    }
}
