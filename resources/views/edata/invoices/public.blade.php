@php
    use App\Support\Edata\Fmt;

    $publicNumber = ltrim(Fmt::invoiceNumber($invoice->invoice_id, $company), '#');
    $publicCompany = $settings['company_name'] ?? 'E-Data 360';
    $publicLang = app()->getLocale();
    $publicPhone = preg_replace('/\D/', '', (string) ($settings['company_telephone'] ?? ''));
    $publicFavicon = \App\Models\Utility::get_file('uploads/logo') . '/' . ($settings['company_favicon'] ?? 'favicon.png');
    $publicTitle = ($publicLang === 'en' ? 'Invoice ' : 'فاتورة ') . $publicNumber . ' - ' . $publicCompany;
    $publicAsk = $publicLang === 'en' ? 'Hello, I have a question about invoice ' . $publicNumber : 'مرحباً، عندي استفسار عن الفاتورة ' . $publicNumber;
@endphp
<!DOCTYPE html>
<html lang="{{ $publicLang }}" dir="{{ $publicLang === 'en' ? 'ltr' : 'rtl' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ $publicTitle }}</title>
    <meta property="og:title" content="{{ $publicTitle }}">
    <meta property="og:site_name" content="{{ $publicCompany }}">
    <link rel="icon" href="{{ $publicFavicon }}" type="image/png">
    <link rel="stylesheet" href="{{ asset('assets/fonts/tabler-icons.min.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\Edata\Fmt::asset('edata/css/edata.css') }}">
    <link rel="stylesheet" href="{{ \App\Support\Edata\Fmt::asset('edata/css/invoice.css') }}">
    <style>
        body.ed-app { background: #E8EEF2; min-height: 100vh; }
        .pub { max-width: 892px; margin-inline: auto; padding: 18px 16px 40px; }
        .pub-bar { display: flex; align-items: center; justify-content: space-between; gap: 8px 12px; flex-wrap: wrap; margin-bottom: 12px; }
        .pub-actions { display: flex; flex-wrap: wrap; gap: 8px; }
        .pub-lang { color: var(--text-2); font-size: 13px; font-weight: 600; }
        .pub-lang:hover { color: var(--navy); }
        .pub .inv { box-shadow: 0 24px 60px rgba(0, 45, 89, .14); border-color: transparent; animation: pubIn .5s var(--ease) both; }
        .pub .pub-note { text-align: center; color: var(--text-3); font-size: 12.5px; margin-top: 22px; }
        @keyframes pubIn { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }
        @media (max-width: 640px) {
            .pub { padding: 12px 10px 92px; }
            .pub-actions { position: fixed; inset-inline: 0; bottom: 0; z-index: 20; padding: 10px; background: #fff; box-shadow: 0 -8px 24px rgba(0, 45, 89, .12); display: grid; grid-auto-flow: column; grid-auto-columns: minmax(0, 1fr); }
            .pub-actions .ed-btn { padding-inline: 6px; font-size: 12.5px; height: 44px; }
            .pub-actions .is-wide-only { display: none; }
        }
        @media print {
            .pub-bar, .pub-note { display: none; }
            .pub { max-width: none; padding: 0; margin: 0; }
            .pub .inv { box-shadow: none; animation: none; }
        }
    </style>
</head>
<body class="ed-app">
    <main class="pub">
        <div class="pub-bar">
            <div class="pub-actions" id="invoiceTools"
                 data-name="{{ $publicNumber }} - {{ $publicCompany }}"
                 data-busy="{{ __('جارٍ التجهيز') }}"
                 data-failed="{{ __('هذا المتصفح لا يدعم التحميل المباشر. ستفتح نافذة الطباعة، اختر منها الحفظ بصيغة PDF.') }}"
                 data-auto="{{ $autoDownload ? 'pdf' : '' }}">
                @if ($payUrl)
                    <a class="ed-btn ed-btn--accent" href="{{ $payUrl }}"><i class="ti ti-credit-card"></i><span>{{ __('الدفع أونلاين') }}</span></a>
                @endif
                <button class="ed-btn ed-btn--primary" type="button" data-inv-pdf><i class="ti ti-file-download"></i><span data-label>{{ __('تحميل PDF') }}</span></button>
                <button class="ed-btn" type="button" data-inv-png><i class="ti ti-photo"></i><span data-label>{{ __('حفظ كصورة') }}</span></button>
                <button class="ed-btn is-wide-only" type="button" data-inv-print><i class="ti ti-printer"></i><span data-label>{{ __('طباعة') }}</span></button>
                @if ($publicPhone !== '')
                    <a class="ed-btn" href="https://wa.me/{{ $publicPhone }}?text={{ rawurlencode($publicAsk) }}" target="_blank" rel="noopener"><i class="ti ti-brand-whatsapp"></i><span>{{ __('تواصل معنا') }}</span></a>
                @endif
            </div>
            <a class="pub-lang" href="?lang={{ $publicLang === 'en' ? 'ar' : 'en' }}">{{ $publicLang === 'en' ? 'العربية' : 'English' }}</a>
        </div>

        @include('edata.invoices._sheet')

        <p class="pub-note">{{ __('هذه الصفحة خاصة بالفاتورة، ويفتحها من لديه الرابط فقط.') }}</p>
    </main>
    <script src="{{ \App\Support\Edata\Fmt::asset('edata/js/invoice.js') }}"></script>
</body>
</html>
