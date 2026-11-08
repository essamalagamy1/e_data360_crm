<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('الصفحة غير موجودة') }} - E-Data 360</title>
    <link rel="stylesheet" href="{{ asset('assets/fonts/tabler-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('edata/css/edata.css') }}">
</head>
<body class="ed-app" style="display:grid;place-items:center;min-height:100vh">
    <div class="ed-ribbon"></div>
    <div class="ed-empty">
        <i class="ti ti-search"></i>
        <b>{{ __('هذه الصفحة غير موجودة، أو لا تملك صلاحية الوصول إليها.') }}</b>
        <div><a class="ed-btn ed-btn--primary" href="{{ url('/account-dashboard') }}">{{ __('العودة للرئيسية') }}</a></div>
    </div>
</body>
</html>
