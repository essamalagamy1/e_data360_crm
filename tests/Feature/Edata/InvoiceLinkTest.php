<?php

namespace Tests\Feature\Edata;

use App\Models\EdataAuditLog;
use App\Models\Invoice;
use App\Support\Edata\InvoiceLinks;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class InvoiceLinkTest extends EdataTestCase
{
    private function publicPath(Invoice $invoice): string
    {
        return parse_url(InvoiceLinks::url($invoice), PHP_URL_PATH);
    }

    public function test_a_sent_invoice_opens_from_its_short_link_without_login(): void
    {
        $customer = $this->customer(['name' => 'Client Visible']);
        $invoice = $this->invoice(1500, 1, ['customer_id' => $customer->id]);

        $path = $this->publicPath($invoice);
        $this->assertMatchesRegularExpression('#^/i/INVO\d+-[A-Z2-9]{12}$#', $path);

        Auth::logout();
        $response = $this->get($path);

        $response->assertOk()->assertSee('Client Visible');
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
    }

    public function test_the_client_page_never_shows_internal_history(): void
    {
        $invoice = $this->invoice();
        Auth::logout();

        $this->get($this->publicPath($invoice))
            ->assertOk()
            ->assertDontSee('سجل التغييرات')
            ->assertDontSee('تسجيل دفعة')
            ->assertDontSee('تغيير الرابط');
    }

    public function test_guessing_a_link_does_not_work(): void
    {
        $invoice = $this->invoice();
        $number = strtok(basename($this->publicPath($invoice)), '-');

        $this->get('/i/' . $number . '-AAAAAAAAAAAA')->assertNotFound();
        $this->get('/i/' . $invoice->id)->assertNotFound();
        $this->get('/i/-')->assertNotFound();
    }

    public function test_a_draft_has_no_client_page(): void
    {
        $draft = $this->invoice(1000, 0);
        $this->assertNull(InvoiceLinks::url($draft));

        DB::table('invoices')->where('id', $draft->id)->update(['public_token' => 'DRAFTTOKEN23']);
        $this->get('/i/INVO1-DRAFTTOKEN23')->assertNotFound();
    }

    public function test_a_cancelled_invoice_still_opens_and_says_so(): void
    {
        $invoice = $this->invoice();
        $path = $this->publicPath($invoice);
        $invoice->cancelled_at = now();
        $invoice->save();

        $this->get($path)->assertOk()->assertSee('ملغاة');
    }

    public function test_creating_a_link_is_not_recorded_as_an_edit(): void
    {
        $invoice = $this->invoice();
        DB::table('invoices')->where('id', $invoice->id)->update(['updated_at' => '2020-01-01 00:00:00']);
        $invoice = Invoice::find($invoice->id);
        $logs = EdataAuditLog::where('entity_type', 'invoice')->where('entity_id', $invoice->id)->count();

        InvoiceLinks::url($invoice);

        $this->assertSame('2020-01-01 00:00:00', (string) DB::table('invoices')->where('id', $invoice->id)->value('updated_at'));
        $this->assertSame($logs, EdataAuditLog::where('entity_type', 'invoice')->where('entity_id', $invoice->id)->count());
    }

    public function test_old_encrypted_links_lead_to_the_new_page(): void
    {
        $invoice = $this->invoice();

        $this->get('/customer/invoice/' . Crypt::encrypt($invoice->id))
            ->assertRedirect(InvoiceLinks::url($invoice->fresh()));
        $this->get('/invoice/pdf/' . Crypt::encrypt($invoice->id))
            ->assertRedirect(InvoiceLinks::url($invoice->fresh()) . '?download=pdf');
        $this->get('/customer/invoice/not-a-real-value')->assertNotFound();
    }

    public function test_changing_the_link_retires_every_older_link(): void
    {
        $invoice = $this->invoice();
        $oldPath = $this->publicPath($invoice);

        $this->actingAs($this->owner)->post(route('edata.invoices.relink', $invoice->id))->assertRedirect();

        $fresh = $invoice->fresh();
        Auth::logout();

        $this->get($oldPath)->assertNotFound();
        $this->get($this->publicPath($fresh))->assertOk();
        $this->get('/customer/invoice/' . Crypt::encrypt($invoice->id))->assertNotFound();
        $this->assertTrue(EdataAuditLog::where('entity_type', 'invoice')->where('entity_id', $invoice->id)->where('note', 'تغيير رابط الفاتورة')->exists());
    }

    public function test_changing_a_link_needs_the_edit_permission(): void
    {
        $invoice = $this->invoice();
        $staff = $this->staff(['manage invoice', 'show invoice'], 'all');

        $this->actingAs($staff)->post(route('edata.invoices.relink', $invoice->id))->assertForbidden();
    }

    public function test_the_staff_screen_shows_the_link_and_the_print_page_renders(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->owner)->get(route('edata.invoices.show', $invoice->id))
            ->assertOk()
            ->assertSee('/i/', false)
            ->assertSee('invoiceSheet', false);

        $this->actingAs($this->owner)->get(route('edata.invoices.print', $invoice->id))
            ->assertOk()
            ->assertSee('invoiceSheet', false);
    }

    public function test_the_qr_code_is_a_single_scalable_shape(): void
    {
        $svg = InvoiceLinks::qr('https://system.e-data360.com/i/INVO00001-ABCDEFGHJKMN');

        $this->assertNotNull($svg);
        $this->assertStringContainsString('viewBox', $svg);
        $this->assertStringNotContainsString('<rect', $svg);
    }
}
