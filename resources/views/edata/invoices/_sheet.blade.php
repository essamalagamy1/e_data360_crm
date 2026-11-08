@php
    use App\Support\Edata\Fmt;
    use App\Support\Edata\InvoiceLinks;
    use App\Support\Edata\InvoiceMath;
    use App\Support\Edata\Labels;

    $calc = $invoice->calc;
    $state = $calc['state'];
    $customer = $invoice->customer;
    $number = Fmt::invoiceNumber($invoice->invoice_id, $company);
    $money = fn ($value) => Fmt::money($value, $company);
    $rates = InvoiceMath::rates($company);

    $logoBase = \App\Models\Utility::get_file('uploads/logo');
    $logoFile = $settings['company_logo_dark'] ?? 'logo-dark.png';
    $companyName = $settings['company_name'] ?? 'E-Data 360';
    $companyPlace = collect([$settings['company_address'] ?? null, $settings['company_city'] ?? null, $settings['company_state'] ?? null, $settings['company_zipcode'] ?? null, $settings['company_country'] ?? null])->filter()->implode('، ');
    $customerPlace = $customer ? collect([$customer->billing_address, $customer->billing_city, $customer->billing_country])->filter()->implode('، ') : '';

    $hasDiscount = $invoice->items->sum(fn ($item) => (float) $item->discount) > 0;
    $hasTax = $calc['tax'] > 0;

    $link = $link ?? null;
    $qr = ($link && ($settings['invoice_qr_display'] ?? 'on') === 'on') ? InvoiceLinks::qr($link) : null;

    $stateEnglish = ['draft' => 'Draft', 'sent' => 'Awaiting payment', 'partial' => 'Partly paid', 'paid' => 'Paid', 'overdue' => 'Overdue', 'cancelled' => 'Cancelled'];
    $stateArabic = ['draft' => 'مسودة', 'sent' => 'بانتظار السداد', 'partial' => 'مدفوعة جزئياً', 'paid' => 'مدفوعة', 'overdue' => 'متأخرة السداد', 'cancelled' => 'ملغاة'];

    $notes = trim((string) $invoice->notes);
    $termsTitle = trim(strip_tags((string) ($settings['footer_title'] ?? '')));
    $terms = trim(strip_tags((string) ($settings['footer_notes'] ?? '')));
@endphp
<div class="inv-wrap">
<article class="inv is-{{ $state }}" id="invoiceSheet">
    <div class="inv-ribbon" aria-hidden="true"></div>

    <header class="inv-head">
        <div class="inv-brand">
            <img src="{{ $logoBase . '/' . $logoFile }}" alt="{{ $companyName }}" onerror="this.remove()">
            <div class="inv-brand__text">
                <b>{{ $companyName }}</b>
                @if ($companyPlace)<span>{{ $companyPlace }}</span>@endif
                @if (!empty($settings['vat_number']))<span><x-ed.bi class="is-inline" ar="الرقم الضريبي" en="VAT no." /> <bdi>{{ $settings['vat_number'] }}</bdi></span>@endif
                @if (!empty($settings['registration_number']))<span><x-ed.bi class="is-inline" ar="السجل التجاري" en="CR no." /> <bdi>{{ $settings['registration_number'] }}</bdi></span>@endif
            </div>
        </div>
        <div class="inv-title">
            <h1><x-ed.bi ar="فاتورة" en="Invoice" /></h1>
            <div class="inv-number"><bdi>{{ $number }}</bdi></div>
        </div>
    </header>

    <section class="inv-hero">
        <div class="inv-hero__to">
            <x-ed.bi class="inv-hero__label" ar="فاتورة إلى" en="Bill to" />
            <strong><bdi>{{ $customer->name ?? '—' }}</bdi></strong>
            @if ($customer)
                @if ($customer->contact_person)<span>{{ $customer->contact_person }}</span>@endif
                @if ($customerPlace)<span>{{ $customerPlace }}</span>@endif
                @if ($customer->contact)<span><bdi>{{ $customer->contact }}</bdi></span>@endif
                @if ($customer->email)<span><bdi>{{ $customer->email }}</bdi></span>@endif
                @if ($customer->tax_number)<span>{{ app()->getLocale() === 'en' ? 'VAT no.' : 'الرقم الضريبي' }} <bdi>{{ $customer->tax_number }}</bdi></span>@endif
            @endif
        </div>
        <svg class="inv-hero__bars" viewBox="0 0 150 90" aria-hidden="true">
            <rect x="4" y="58" width="20" height="32" rx="5" fill="#00C2C6" />
            <rect x="34" y="42" width="20" height="48" rx="5" fill="#00D3B5" />
            <rect x="64" y="50" width="20" height="40" rx="5" fill="#82F67D" />
            <rect x="94" y="24" width="20" height="66" rx="5" fill="#BFFA65" />
            <rect x="124" y="4" width="20" height="86" rx="5" fill="#BFFA65" />
        </svg>
        <div class="inv-hero__due">
            @if ($state === 'paid')
                <x-ed.bi class="inv-hero__label" ar="إجمالي الفاتورة" en="Invoice total" />
                <div class="inv-hero__amount"><bdi>{{ $money($calc['total']) }}</bdi></div>
            @elseif ($state === 'cancelled')
                <x-ed.bi class="inv-hero__label" ar="قيمة الفاتورة الملغاة" en="Cancelled amount" />
                <div class="inv-hero__amount is-void"><bdi>{{ $money($calc['total']) }}</bdi></div>
            @else
                <x-ed.bi class="inv-hero__label" ar="المبلغ المستحق" en="Amount due" />
                <div class="inv-hero__amount"><bdi>{{ $money(max(0, $calc['due'])) }}</bdi></div>
            @endif
            <div class="inv-pill is-{{ $state }}"><span>{{ app()->getLocale() === 'en' ? $stateEnglish[$state] : $stateArabic[$state] }}</span></div>
        </div>
    </section>

    <dl class="inv-meta">
        <div><dt><x-ed.bi ar="تاريخ الإصدار" en="Issue date" /></dt><dd>{{ Labels::date($invoice->issue_date) }}</dd></div>
        <div><dt><x-ed.bi ar="تاريخ الاستحقاق" en="Due date" /></dt><dd>{{ Labels::date($invoice->due_date) }}</dd></div>
        @if ($invoice->ref_number)<div><dt><x-ed.bi ar="المرجع" en="Reference" /></dt><dd><bdi>{{ $invoice->ref_number }}</bdi></dd></div>@endif
        @if ($invoice->project)<div><dt><x-ed.bi ar="المشروع" en="Project" /></dt><dd><bdi>{{ $invoice->project->project_name }}</bdi></dd></div>@endif
    </dl>

    <table class="inv-lines">
        <thead>
            <tr>
                <th class="is-n" aria-label="#">#</th>
                <th><x-ed.bi ar="الخدمة" en="Service" /></th>
                <th class="is-c"><x-ed.bi ar="الكمية" en="Qty" /></th>
                <th class="is-e"><x-ed.bi ar="السعر" en="Unit price" /></th>
                @if ($hasDiscount)<th class="is-e"><x-ed.bi ar="الخصم" en="Discount" /></th>@endif
                @if ($hasTax)<th class="is-c"><x-ed.bi ar="الضريبة" en="VAT" /></th>@endif
                <th class="is-e"><x-ed.bi ar="الإجمالي" en="Amount" /></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                @php
                    $rate = collect(array_filter(explode(',', (string) $item->tax)))->sum(fn ($id) => $rates[(int) $id] ?? 0);
                @endphp
                <tr>
                    <td class="is-n ed-num">{{ $loop->iteration }}</td>
                    <td class="is-name">
                        <b><bdi>{{ $item->product->name ?? '—' }}</bdi></b>
                        @if ($item->description)<span>{{ $item->description }}</span>@endif
                    </td>
                    <td class="is-c ed-num" data-label="{{ app()->getLocale() === 'en' ? 'Qty' : 'الكمية' }}">{{ (float) $item->quantity == (int) $item->quantity ? (int) $item->quantity : $item->quantity }}</td>
                    <td class="is-e ed-num" data-label="{{ app()->getLocale() === 'en' ? 'Unit price' : 'السعر' }}"><bdi>{{ $money($item->price) }}</bdi></td>
                    @if ($hasDiscount)<td class="is-e ed-num" data-label="{{ app()->getLocale() === 'en' ? 'Discount' : 'الخصم' }}">@if ((float) $item->discount)<bdi>{{ $money($item->discount) }}</bdi>@else — @endif</td>@endif
                    @if ($hasTax)<td class="is-c ed-num" data-label="{{ app()->getLocale() === 'en' ? 'VAT' : 'الضريبة' }}">{{ $rate > 0 ? rtrim(rtrim(number_format($rate, 2), '0'), '.') . '%' : '—' }}</td>@endif
                    <td class="is-e is-total ed-num" data-label="{{ app()->getLocale() === 'en' ? 'Amount' : 'الإجمالي' }}"><bdi>{{ $money($item->price * $item->quantity - $item->discount) }}</bdi></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="inv-sum">
        <div class="inv-qr">
            @if ($qr)
                <div class="inv-qr__code">{!! $qr !!}</div>
                <div class="inv-qr__text">
                    <x-ed.bi ar="نسخة الفاتورة" en="Online copy" />
                    <span>{{ app()->getLocale() === 'en' ? 'Scan to open this invoice, always up to date.' : 'امسح الكود لفتح الفاتورة وحالتها محدّثة.' }}</span>
                </div>
            @endif
        </div>

        <div class="inv-totals">
            <div><x-ed.bi class="is-inline" ar="المجموع" en="Subtotal" /><b class="ed-num"><bdi>{{ $money($calc['subtotal']) }}</bdi></b></div>
            @if ($calc['discount'] > 0)<div><x-ed.bi class="is-inline" ar="الخصم" en="Discount" /><b class="ed-num"><bdi>- {{ $money($calc['discount']) }}</bdi></b></div>@endif
            @if ($calc['tax'] > 0)<div><x-ed.bi class="is-inline" ar="ضريبة القيمة المضافة" en="VAT" /><b class="ed-num"><bdi>{{ $money($calc['tax']) }}</bdi></b></div>@endif
            <div class="is-grand"><x-ed.bi class="is-inline" ar="الإجمالي" en="Total" /><b class="ed-num"><bdi>{{ $money($calc['total']) }}</bdi></b></div>
            @if ($calc['paid'] > 0)<div class="is-paid"><x-ed.bi class="is-inline" ar="المدفوع" en="Paid" /><b class="ed-num"><bdi>{{ $money($calc['paid']) }}</bdi></b></div>@endif
            @if ($calc['credit'] > 0)<div><x-ed.bi class="is-inline" ar="إشعارات دائنة" en="Credit notes" /><b class="ed-num"><bdi>{{ $money($calc['credit']) }}</bdi></b></div>@endif
            @if ($state !== 'cancelled' && ($calc['paid'] > 0 || $calc['credit'] > 0))
                <div class="is-due"><x-ed.bi class="is-inline" ar="المتبقي" en="Balance due" /><b class="ed-num"><bdi>{{ $money(max(0, $calc['due'])) }}</bdi></b></div>
            @endif
        </div>

        @if (in_array($state, ['paid', 'cancelled', 'draft'], true))
            <div class="inv-stamp is-{{ $state }}" aria-hidden="true">
                <span>{{ $stateArabic[$state] }}</span><small>{{ $stateEnglish[$state] }}</small>
            </div>
        @endif
    </div>

    @if ($invoice->payments->isNotEmpty() || ($calc['marked'] ?? 0) > 0)
        <section class="inv-block">
            <h2><x-ed.bi ar="المدفوعات" en="Payments" /></h2>
            <ul class="inv-pays">
                @foreach ($invoice->payments as $payment)
                    <li>
                        <span>{{ Labels::date($payment->date) }}</span>
                        <b class="ed-num"><bdi>{{ $money($payment->amount) }}</bdi></b>
                        <span class="is-sub">{{ $accountNames[$payment->account_id] ?? '' }}@if ($payment->reference) <bdi>{{ $payment->reference }}</bdi>@endif</span>
                    </li>
                @endforeach
                @if (($calc['marked'] ?? 0) > 0)
                    <li>
                        <span>{{ Labels::date($invoice->collected_on) }}</span>
                        <b class="ed-num"><bdi>{{ $money($calc['marked']) }}</bdi></b>
                        <span class="is-sub">{{ app()->getLocale() === 'en' ? 'Collected' : 'تم التحصيل' }}</span>
                    </li>
                @endif
            </ul>
        </section>
    @endif

    @if ($notes !== '')
        <section class="inv-block">
            <h2><x-ed.bi ar="ملاحظات" en="Notes" /></h2>
            <p>{{ $notes }}</p>
        </section>
    @endif

    @if ($terms !== '')
        <section class="inv-block">
            <h2>{{ $termsTitle ?: (app()->getLocale() === 'en' ? 'Terms' : 'الشروط') }}</h2>
            <p>{{ $terms }}</p>
        </section>
    @endif

    <footer class="inv-foot">
        <div class="inv-foot__thanks">
            <b>{{ app()->getLocale() === 'en' ? 'Thank you for trusting ' . $companyName : 'شكراً لثقتكم في ' . $companyName }}</b>
        </div>
        <div class="inv-foot__contact">
            @if (!empty($settings['company_telephone']))<span><bdi>{{ $settings['company_telephone'] }}</bdi></span>@endif
            @if (!empty($settings['company_email']))<span><bdi>{{ $settings['company_email'] }}</bdi></span>@endif
        </div>
    </footer>
</article>
</div>
