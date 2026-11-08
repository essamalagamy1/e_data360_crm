<?php

namespace Tests\Feature\Edata;

use App\Models\Invoice;
use App\Support\Edata\InvoiceMath;
use Illuminate\Support\Facades\DB;

class CollectionAndClientsTest extends EdataTestCase
{
    private function category(): int
    {
        return DB::table('product_service_categories')->insertGetId(['name' => 'Income', 'type' => 'income', 'chart_account_id' => 0, 'color' => '#000', 'created_by' => $this->company, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function calc(Invoice $invoice): array
    {
        $fresh = Invoice::with(['items', 'payments'])->find($invoice->id);
        InvoiceMath::attach(collect([$fresh]), $this->company);

        return $fresh->calc;
    }

    private function newInvoice(string $state): Invoice
    {
        $customer = $this->customer();
        $product = $this->product(800);

        $this->actingAs($this->owner)->post(route('edata.invoices.store'), [
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'category_id' => $this->category(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 800, 'discount' => 0]],
            'payment_state' => $state,
        ])->assertSessionHasNoErrors();

        return Invoice::where('customer_id', $customer->id)->firstOrFail();
    }

    public function test_existing_open_invoices_become_collected_and_others_stay(): void
    {
        $open = $this->invoice(1000, 1);
        $partial = $this->invoice(500, 3);
        $draft = $this->invoice(700, 0);
        $cancelled = $this->invoice(900, 1, ['cancelled_at' => now()]);

        (require database_path('migrations/2026_11_08_100000_mark_existing_invoices_collected.php'))->up();

        $this->assertSame(4, (int) $open->fresh()->status);
        $this->assertSame($open->fresh()->issue_date, $open->fresh()->collected_on);
        $this->assertSame(0.0, $this->calc($open)['due']);
        $this->assertSame(1000.0, $this->calc($open)['paid']);
        $this->assertSame('paid', $this->calc($partial)['state']);

        $this->assertSame(0, (int) $draft->fresh()->status);
        $this->assertNull($draft->fresh()->collected_on);
        $this->assertNull($cancelled->fresh()->collected_on);
    }

    public function test_collected_money_counts_on_its_date(): void
    {
        $invoice = $this->invoice(1200, 4, ['collected_on' => now()->toDateString()]);

        $this->assertSame(1200.0, InvoiceMath::collectedBetween($this->company, now()->startOfMonth(), now(), [$invoice->id]));
        $this->assertSame(0.0, InvoiceMath::collectedBetween($this->company, now()->subYears(2), now()->subYear(), [$invoice->id]));
    }

    public function test_a_new_invoice_can_be_collected_or_pending(): void
    {
        $collected = $this->newInvoice('collected');
        $this->assertSame(4, (int) $collected->status);
        $this->assertNotNull($collected->collected_on);
        $this->assertSame(0.0, $this->calc($collected)['due']);
        $this->assertSame('paid', $this->calc($collected)['state']);

        $pending = $this->newInvoice('pending');
        $this->assertSame(1, (int) $pending->status);
        $this->assertNull($pending->collected_on);
        $this->assertSame(800.0, $this->calc($pending)['due']);
    }

    public function test_the_payment_state_must_be_chosen(): void
    {
        $this->actingAs($this->owner)->post(route('edata.invoices.store'), [
            'customer_id' => $this->customer()->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(3)->toDateString(),
            'category_id' => $this->category(),
            'items' => [['product_id' => $this->product(100)->id, 'quantity' => 1, 'price' => 100]],
        ])->assertSessionHasErrors('payment_state');
    }

    public function test_pending_invoice_can_be_marked_collected_and_back(): void
    {
        $invoice = $this->invoice(600, 1);

        $this->actingAs($this->owner)->post(route('edata.invoices.collected', $invoice->id), ['collected_on' => now()->toDateString()])->assertSessionHas('success');
        $this->assertSame(4, (int) $invoice->fresh()->status);
        $this->assertSame(0.0, $this->calc($invoice)['due']);

        // A payment on a fully collected invoice is refused, so nothing is counted twice.
        $this->actingAs($this->owner)->post(route('edata.invoices.payment', $invoice->id), ['date' => now()->toDateString(), 'amount' => 100, 'account_id' => $this->bank()])->assertSessionHas('error');

        $this->actingAs($this->owner)->post(route('edata.invoices.pending', $invoice->id))->assertSessionHas('success');
        $this->assertNull($invoice->fresh()->collected_on);
        $this->assertSame(1, (int) $invoice->fresh()->status);
        $this->assertSame(600.0, $this->calc($invoice)['due']);
    }

    public function test_marking_collected_needs_the_payment_permission(): void
    {
        $invoice = $this->invoice(600, 1);
        $viewer = $this->staff(['manage invoice', 'show invoice'], 'all');

        $this->actingAs($viewer)->post(route('edata.invoices.collected', $invoice->id))->assertForbidden();
        $this->assertNull($invoice->fresh()->collected_on);
    }

    public function test_a_view_only_role_sees_client_names_and_nothing_else(): void
    {
        $client = $this->customer(['name' => 'Client Name Only', 'email' => 'secret-mail@test.local', 'contact' => '0555123456']);
        $this->invoice(4321, 1, ['customer_id' => $client->id]);
        $viewer = $this->staff(['manage customer', 'show customer'], 'all');

        $this->actingAs($viewer)->get(route('edata.clients.index'))
            ->assertOk()
            ->assertSee('Client Name Only')
            ->assertDontSee('secret-mail@test.local')
            ->assertDontSee('0555123456')
            ->assertDontSee('4,321');

        // Searching by email would reveal it, so it finds nothing.
        $this->actingAs($viewer)->get(route('edata.clients.index', ['q' => 'secret-mail']))->assertOk()->assertDontSee('Client Name Only');

        $this->actingAs($viewer)->get(route('edata.clients.show', $client->id))->assertForbidden();
        $this->actingAs($viewer)->get(route('edata.clients.export'))->assertForbidden();
    }

    public function test_a_role_that_edits_clients_still_sees_everything(): void
    {
        $client = $this->customer(['name' => 'Full Client', 'email' => 'visible@test.local']);
        $editor = $this->staff(['manage customer', 'show customer', 'edit customer'], 'all');

        $this->actingAs($editor)->get(route('edata.clients.index'))->assertOk()->assertSee('visible@test.local');
        $this->actingAs($editor)->get(route('edata.clients.show', $client->id))->assertOk();
    }
}
