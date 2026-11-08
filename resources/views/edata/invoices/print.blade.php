@php
    $printNumber = \App\Support\Edata\Fmt::invoiceNumber($invoice->invoice_id, $company);
    $printAuto = request()->query('download') ? 'pdf' : (request()->query('preview') ? '' : 'print');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'en' ? 'ltr' : 'rtl' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ ltrim($printNumber, '#') }} - {{ $settings['company_name'] ?? 'E-Data 360' }}</title>
    <link rel="stylesheet" href="{{ asset('assets/fonts/tabler-icons.min.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\Edata\Fmt::asset('edata/css/edata.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\Edata\Fmt::asset('edata/css/invoice.css') }}">
    <style>
        body.ed-app { background: #E9EEF3; }
        .print-wrap { max-width: 860px; margin: 20px auto; padding-inline: 16px; }
        .print-bar { display: flex; gap: 8px; justify-content: center; margin-bottom: 14px; flex-wrap: wrap; }
        @media print {
            .print-bar { display: none; }
            .print-wrap { max-width: none; margin: 0; padding: 0; }
        }
    </style>
</head>
<body class="ed-app">
    <div class="print-wrap">
        <div class="print-bar" id="invoiceTools" data-name="{{ ltrim($printNumber, '#') }}" data-busy="{{ __('جارٍ التجهيز') }}" data-failed="{{ __('هذا المتصفح لا يدعم التحميل المباشر. ستفتح نافذة الطباعة، اختر منها الحفظ بصيغة PDF.') }}" data-auto="{{ $printAuto }}">
            <button class="ed-btn ed-btn--primary" type="button" data-inv-print><i class="ti ti-printer"></i><span data-label>{{ __('طباعة') }}</span></button>
            <button class="ed-btn" type="button" data-inv-pdf><i class="ti ti-file-download"></i><span data-label>{{ __('تحميل PDF') }}</span></button>
            <button class="ed-btn" type="button" data-inv-png><i class="ti ti-photo"></i><span data-label>{{ __('حفظ كصورة') }}</span></button>
        </div>
        @include('edata.invoices._sheet')
    </div>
    <script src="{{ \App\Support\Edata\Fmt::asset('edata/js/invoice.js') }}"></script>
</body>
</html>
