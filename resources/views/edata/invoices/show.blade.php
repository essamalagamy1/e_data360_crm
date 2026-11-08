@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', \App\Support\Edata\Fmt::invoiceNumber($invoice->invoice_id, $company))

@php
    use App\Support\Edata\Fmt;
    use App\Support\Edata\Labels;
    $calc = $invoice->calc;
    $isDraft = $calc['state'] === 'draft';
    $isCancelled = $calc['state'] === 'cancelled';
    $hasPayments = $invoice->payments->isNotEmpty();
    $fileNumber = ltrim(Fmt::invoiceNumber($invoice->invoice_id, $company), '#');
    $companyLabel = $settings['company_name'] ?? 'E-Data 360';
    $shareText = $link ? __('مرحباً :name، هذه فاتورتكم رقم :number من :company:', ['name' => $invoice->customer->name ?? '', 'number' => $fileNumber, 'company' => $companyLabel]) . "\n" . $link : '';
    $sharePhone = preg_replace('/\D/', '', (string) ($invoice->customer->contact ?? ''));
@endphp

@push('edata-css')
    <link rel="stylesheet" href="{{ \App\Support\Edata\Fmt::asset('edata/css/invoice.css') }}">
@endpush

@push('edata-scripts')
    <script src="{{ \App\Support\Edata\Fmt::asset('edata/js/invoice.js') }}"></script>
@endpush

@section('content')
<x-ed.head :title="Fmt::invoiceNumber($invoice->invoice_id, $company)" :crumbs="[__('الفواتير') => route('edata.invoices.index')]">
    <div class="ed-head__actions" id="invoiceTools" data-name="{{ $fileNumber }} - {{ $companyLabel }}" data-busy="{{ __('جارٍ التجهيز') }}" data-copied="{{ __('تم النسخ') }}" data-failed="{{ __('هذا المتصفح لا يدعم التحميل المباشر. ستفتح نافذة الطباعة، اختر منها الحفظ بصيغة PDF.') }}">
        <a class="ed-btn" href="{{ route('edata.invoices.print', $invoice->id) }}" target="_blank" rel="noopener"><i class="ti ti-printer"></i>{{ __('طباعة') }}</a>
        <button class="ed-btn" type="button" data-inv-pdf><i class="ti ti-file-download"></i><span data-label>{{ __('تحميل PDF') }}</span></button>
        <button class="ed-btn" type="button" data-inv-png><i class="ti ti-photo"></i><span data-label>{{ __('حفظ كصورة') }}</span></button>
    </div>
    @if ($canEdit && !$isCancelled)
        <a class="ed-btn" href="{{ route('edata.invoices.edit', $invoice->id) }}"><i class="ti ti-pencil"></i>{{ __('تعديل') }}</a>
    @endif
    @if ($isDraft && $canSend)
        <form method="POST" action="{{ route('edata.invoices.sent', $invoice->id) }}" data-confirm="{{ __('اعتماد الفاتورة كمرسلة؟') }}" data-confirm-text="{{ __('تدخل في الإجماليات وتُسجل قيودها المحاسبية.') }}" data-confirm-yes="{{ __('اعتماد') }}" data-confirm-safe>
            @csrf
            <button class="ed-btn ed-btn--primary" type="submit"><i class="ti ti-send"></i>{{ __('تحديد كمرسلة') }}</button>
        </form>
    @endif
</x-ed.head>

<div class="ed-grid ed-grid--side ed-reveal">
    <div>@include('edata.invoices._sheet')</div>

    <div class="ed-stack no-print">
        <div class="ed-figures" style="grid-auto-flow:row;grid-template-columns:1fr 1fr">
            <x-ed.figure :label="__('الإجمالي')" :money="$calc['total']" :company="$company" />
            <x-ed.figure :label="__('المتبقي')" :money="max(0, $calc['due'])" :company="$company" :tone="$calc['due'] > 0 ? 'bad' : 'good'" />
        </div>

        @if ($canPay && !$isCancelled && !$isDraft)
            @if ($calc['due'] > 0)
                <form class="ed-panel ed-collect" method="POST" action="{{ route('edata.invoices.collected', $invoice->id) }}" data-confirm="{{ __('تحديد الفاتورة كمتحصلة؟') }}" data-confirm-text="{{ __('يُحسب المتبقي كله كمبلغ متحصل في التاريخ المختار.') }}" data-confirm-yes="{{ __('تم التحصيل') }}" data-confirm-safe>
                    @csrf
                    <div class="ed-panel__body ed-stack" style="gap:10px">
                        <div class="ed-collect__state"><i class="ti ti-clock"></i><span><b>{{ __('قيد الدفع') }}</b>{{ __('وصل المبلغ؟ حدّدها كمتحصلة بضغطة.') }}</span></div>
                        <x-ed.field :label="__('تاريخ التحصيل')" name="collected_on">
                            <input class="ed-input" type="date" id="collected_on" name="collected_on" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}">
                        </x-ed.field>
                        <button class="ed-btn ed-btn--primary" type="submit"><i class="ti ti-circle-check"></i>{{ __('تم التحصيل') }}</button>
                    </div>
                </form>
            @elseif ($invoice->collected_on)
                <section class="ed-panel ed-collect is-done">
                    <div class="ed-panel__body ed-stack" style="gap:10px">
                        <div class="ed-collect__state"><i class="ti ti-circle-check"></i><span><b>{{ __('تم التحصيل') }}</b>{{ Labels::date($invoice->collected_on) }}</span></div>
                        <form method="POST" action="{{ route('edata.invoices.pending', $invoice->id) }}" data-confirm="{{ __('إرجاع الفاتورة إلى قيد الدفع؟') }}" data-confirm-text="{{ __('تعود مبلغاً مستحقاً على العميل. الدفعات المسجلة لا تتغير.') }}" data-confirm-yes="{{ __('إرجاع لقيد الدفع') }}">
                            @csrf
                            <button class="ed-btn ed-btn--ghost ed-btn--sm" type="submit"><i class="ti ti-arrow-back-up"></i>{{ __('إرجاع لقيد الدفع') }}</button>
                        </form>
                    </div>
                </section>
            @endif
        @endif

        <section class="ed-panel">
            <div class="ed-panel__head"><h2>{{ __('مشاركة مع العميل') }}</h2></div>
            <div class="ed-panel__body ed-stack" style="gap:10px">
                @if ($link)
                    <div class="ed-share">
                        <bdi class="ed-share__url" title="{{ $link }}">{{ preg_replace('#^https?://#', '', $link) }}</bdi>
                        <button class="ed-btn ed-btn--primary ed-btn--sm" type="button" data-inv-copy="{{ $link }}"><i class="ti ti-copy"></i><span data-label>{{ __('نسخ') }}</span></button>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                        <a class="ed-btn" href="https://wa.me/{{ $sharePhone }}?text={{ rawurlencode($shareText) }}" target="_blank" rel="noopener"><i class="ti ti-brand-whatsapp"></i>{{ __('واتساب') }}</a>
                        <a class="ed-btn" href="{{ $link }}" target="_blank" rel="noopener"><i class="ti ti-external-link"></i>{{ __('فتح الرابط') }}</a>
                    </div>
                    <p class="ed-hint">{{ __('يفتحها العميل من غير تسجيل دخول، وتظهر له حالة السداد محدّثة.') }}</p>
                    @if ($canEdit)
                        <form method="POST" action="{{ route('edata.invoices.relink', $invoice->id) }}" data-confirm="{{ __('تغيير رابط هذه الفاتورة؟') }}" data-confirm-text="{{ __('الرابط الحالي سيتوقف عن العمل عند أي شخص معه.') }}" data-confirm-yes="{{ __('تغيير الرابط') }}">
                            @csrf
                            <button class="ed-btn ed-btn--ghost ed-btn--sm" type="submit"><i class="ti ti-refresh"></i>{{ __('تغيير الرابط') }}</button>
                        </form>
                    @endif
                @else
                    <p class="ed-hint">{{ __('اعتمد الفاتورة أولاً، وبعدها يظهر رابطها هنا.') }}</p>
                @endif
            </div>
        </section>

        @if ($canPay && !$isCancelled && $calc['due'] > 0)
            <form class="ed-panel" method="POST" action="{{ route('edata.invoices.payment', $invoice->id) }}" enctype="multipart/form-data">
                @csrf
                <div class="ed-panel__head"><h2>{{ __('تسجيل دفعة') }}</h2></div>
                <div class="ed-panel__body ed-stack">
                    <x-ed.field :label="__('المبلغ')" name="amount" required :hint="__('المتبقي') . ': ' . Fmt::money($calc['due'], $company)">
                        <input class="ed-input @error('amount') is-invalid @enderror" type="number" step="0.01" min="0.01" max="{{ $calc['due'] }}" id="amount" name="amount" value="{{ old('amount', $calc['due']) }}" required dir="ltr">
                    </x-ed.field>
                    <x-ed.field :label="__('تاريخ الدفع')" name="date" required>
                        <input class="ed-input" type="date" id="date" name="date" value="{{ old('date', now()->toDateString()) }}" required>
                    </x-ed.field>
                    <x-ed.field :label="__('الحساب')" name="account_id" required>
                        <select class="ed-select @error('account_id') is-invalid @enderror" id="account_id" name="account_id" required>
                            <option value="">{{ __('اختر حساباً') }}</option>
                            @foreach ($accounts as $account)
                                @php $linked = (int) $account->chart_account_id !== 0; @endphp
                                <option value="{{ $account->id }}" @disabled(!$linked) @selected((int) old('account_id') === (int) $account->id)>{{ trim($account->bank_name . ' ' . $account->holder_name) }}@unless ($linked) ({{ __('غير مربوط بدليل الحسابات') }})@endunless</option>
                            @endforeach
                        </select>
                    </x-ed.field>
                    <x-ed.field :label="__('المرجع')" name="reference">
                        <input class="ed-input" id="reference" name="reference" value="{{ old('reference') }}">
                    </x-ed.field>
                    <x-ed.field :label="__('إيصال')" name="add_receipt" :hint="__('صورة أو PDF، حتى 5 ميجابايت.')">
                        <input class="ed-input" style="padding-block:8px" type="file" id="add_receipt" name="add_receipt" accept="image/jpeg,image/png,application/pdf">
                    </x-ed.field>
                    <button class="ed-btn ed-btn--primary" type="submit">{{ __('تسجيل الدفعة') }}</button>
                </div>
            </form>
        @endif

        <section class="ed-panel">
            <div class="ed-panel__head"><h2>{{ __('سجل المدفوعات') }}</h2><span class="ed-hint ed-num">{{ Fmt::money($calc['paid'], $company) }}</span></div>
            @if (!$hasPayments)
                <x-ed.empty small icon="ti-cash" :title="__('لم تُسجل دفعات بعد.')" />
            @else
                <div class="ed-list">
                    @foreach ($invoice->payments as $payment)
                        <div>
                            <div class="ed-list__main">
                                <span class="ed-list__title ed-num">{{ Fmt::money($payment->amount, $company) }}</span>
                                <span class="ed-list__sub">{{ Labels::date($payment->date) }}، {{ $accountNames[$payment->account_id] ?? __('حساب') }}@if ($payment->reference)، {{ $payment->reference }}@endif</span>
                            </div>
                            @if ($canRemovePayment)
                                <form method="POST" action="{{ route('edata.invoices.payment.remove', [$invoice->id, $payment->id]) }}" data-confirm="{{ __('حذف هذه الدفعة؟') }}" data-confirm-text="{{ __('يُعاد حساب المتبقي ورصيد الحساب والعميل.') }}">
                                    @csrf @method('DELETE')
                                    <button class="ed-btn ed-btn--ghost ed-btn--sm" type="submit" aria-label="{{ __('حذف الدفعة') }}"><i class="ti ti-trash"></i></button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="ed-panel">
            <div class="ed-panel__head"><h2>{{ __('سجل التغييرات') }}</h2></div>
            <x-ed.trail :logs="$history" :with-diff="false" />
        </section>

        @if (!$isCancelled && !$hasPayments && ($canEdit || ($canDelete && $isDraft)))
            <section class="ed-panel">
                <div class="ed-panel__body ed-stack">
                    @if ($canEdit && !$hasPayments)
                        <form method="POST" action="{{ route('edata.invoices.cancel', $invoice->id) }}" data-confirm="{{ __('إلغاء هذه الفاتورة؟') }}" data-confirm-text="{{ __('تبقى محفوظة في السجل ولا تدخل في الإجماليات.') }}" data-confirm-yes="{{ __('إلغاء الفاتورة') }}">
                            @csrf
                            <button class="ed-btn ed-btn--danger" type="submit" style="width:100%">{{ __('إلغاء الفاتورة') }}</button>
                        </form>
                    @endif
                    @if ($canDelete && $isDraft && !$hasPayments)
                        <form method="POST" action="{{ route('edata.invoices.destroy', $invoice->id) }}" data-confirm="{{ __('حذف هذه المسودة؟') }}">
                            @csrf @method('DELETE')
                            <button class="ed-btn ed-btn--ghost" type="submit" style="width:100%">{{ __('حذف المسودة') }}</button>
                        </form>
                    @endif
                </div>
            </section>
        @endif
    </div>
</div>
@endsection
