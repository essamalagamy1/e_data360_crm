<?php

namespace App\Http\Controllers\Edata;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Invoice;
use App\Support\Edata\Fmt;
use App\Support\Edata\InvoiceLinks;
use App\Support\Edata\InvoiceMath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * What a client sees. No login: the unguessable token in the link is the permission,
 * so this page only ever shows the invoice itself, never its internal history.
 */
class PublicInvoiceController extends Controller
{
    public function show(Request $request, string $slug)
    {
        $token = InvoiceLinks::tokenFrom($slug);

        $invoice = $token === '' ? null : Invoice::with(['customer', 'project', 'items.product', 'payments'])
            ->where('public_token', $token)
            ->first();

        abort_if(!$invoice || !InvoiceLinks::isShareable($invoice), 404);

        app()->setLocale($request->query('lang') === 'en' ? 'en' : 'ar');

        $company = (int) $invoice->created_by;
        InvoiceMath::attach(collect([$invoice]), $company);

        $settings = Fmt::settings($company);
        $hasDue = !$invoice->cancelled_at && $invoice->calc['due'] > 0;

        return response()
            ->view('edata.invoices.public', [
                'invoice' => $invoice,
                'company' => $company,
                'settings' => $settings,
                'link' => route('edata.invoice.public', InvoiceLinks::slug($invoice, $token)),
                'accountNames' => BankAccount::where('created_by', $company)->get()->mapWithKeys(fn ($a) => [$a->id => trim($a->bank_name . ' ' . $a->holder_name)]),
                'payUrl' => $hasDue && InvoiceLinks::hasOnlinePayment($company) ? route('edata.invoice.pay', Crypt::encrypt($invoice->id)) : null,
                'autoDownload' => $request->query('download') === 'pdf',
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /** Links sent by the old system carry an encrypted id. They still reach the invoice. */
    public function legacyLink(string $id)
    {
        return redirect()->to($this->targetFor($id));
    }

    public function legacyPdf(string $id)
    {
        return redirect()->to($this->targetFor($id) . '?download=pdf');
    }

    private function targetFor(string $encrypted): string
    {
        try {
            $invoice = Invoice::find(Crypt::decrypt($encrypted));
        } catch (\Throwable $e) {
            abort(404);
        }

        abort_if(!$invoice || !InvoiceLinks::isShareable($invoice) || $invoice->public_token_rotated_at, 404);

        return InvoiceLinks::url($invoice);
    }
}
