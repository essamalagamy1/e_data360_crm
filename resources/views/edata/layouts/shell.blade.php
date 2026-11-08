@php
    $edIsNew = View::hasSection('edata-page');
    $edSettings = \App\Models\Utility::settings();
    $edLogoBase = \App\Models\Utility::get_file('uploads/logo');
    $edFavicon = $edSettings['company_favicon'] ?? '';
    $edLang = app()->getLocale();
    $edRtl = !in_array($edLang, ['en'], true);

    $edFlash = [];
    foreach (['success' => 'success', 'error' => 'error', 'status' => 'success'] as $key => $type) {
        if (session($key)) {
            $edFlash[] = ['type' => $type, 'text' => trim(strip_tags((string) session($key)))];
        }
    }
    if (isset($errors) && $errors->any() && !session('error')) {
        $edFlash[] = ['type' => 'error', 'text' => __('راجع الحقول المحددة في النموذج.')];
    }
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $edLang) }}" dir="{{ $edRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" id="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('page-title') - {{ config('edata.app_name') }}</title>
    <link rel="icon" href="{{ $edLogoBase . '/' . ($edFavicon ?: 'favicon.png') }}" type="image/png">
    <script>
        try {
            if (localStorage.getItem('edSideSmall') === '1') document.documentElement.classList.add('side-small');
            // The page's opening animation runs once a day, not on every visit.
            var edToday = new Date().toDateString();
            if (localStorage.getItem('edIntroDay') !== edToday) {
                document.documentElement.classList.add('ed-first-today');
                localStorage.setItem('edIntroDay', edToday);
            }
        } catch (e) {}
    </script>
    <link rel="stylesheet" href="{{ asset('assets/fonts/tabler-icons.min.css') }}">

    @unless ($edIsNew)
        {{-- Screens not rebuilt yet need the original stylesheets and sit under a thin skin. --}}
        <link rel="stylesheet" href="{{ asset('assets/css/plugins/main.css') }}">
        <link rel="stylesheet" href="{{ asset('assets/css/plugins/style.css') }}">
        <link rel="stylesheet" href="{{ asset('assets/css/plugins/flatpickr.min.css') }}">
        <link rel="stylesheet" href="{{ asset('assets/css/plugins/animate.min.css') }}">
        <link rel="stylesheet" href="{{ asset('assets/fonts/feather.css') }}">
        <link rel="stylesheet" href="{{ asset('assets/fonts/fontawesome.css') }}">
        <link rel="stylesheet" href="{{ asset('assets/fonts/material.css') }}">
        <link rel="stylesheet" href="{{ asset('assets/css/plugins/bootstrap-switch-button.min.css') }}">
        <link rel="stylesheet" href="{{ asset($edRtl ? 'assets/css/style-rtl.css' : 'assets/css/style.css') }}" id="main-style-link">
        <link rel="stylesheet" href="{{ asset('assets/css/customizer.css') }}">
        <link rel="stylesheet" href="{{ asset('css/custom.css') }}">
    @endunless

    <link rel="stylesheet" href="{{ asset('edata/css/edata.css') }}?v=2">
    <link rel="stylesheet" href="{{ asset('edata/css/edata-me.css') }}?v=2">

    @unless ($edIsNew)
        <link rel="stylesheet" href="{{ asset('edata/css/edata-skin.css') }}?v=1">
    @endunless

    @stack('css-page')
    @stack('edata-css')
</head>
<body class="ed-app {{ $edIsNew ? '' : 'ed-legacy' }}" data-move-failed="{{ __('تعذر النقل. لم يتغير شيء.') }}" data-cheer="{{ __('عمل رائع. شكراً لك.') }}" @if (session('ed_cheer')) data-cheer-now="1" @endif>
    @include('edata.partials.moment')
    <div class="ed-ribbon" aria-hidden="true"></div>
    <div class="ed-toasts" id="edToasts" aria-live="polite"></div>
    @unless ($edIsNew)
        {{-- The original script on these screens expects to remove this element on load. --}}
        <div class="loader-bg" hidden></div>
    @endunless

    @include('edata.partials.sidebar', ['edLogoBase' => $edLogoBase, 'edSettings' => $edSettings])
    <div class="ed-scrim" id="edScrim"></div>

    <div class="ed-shell">
        @include('edata.partials.topbar')

        @if ($edIsNew)
            <main class="ed-main" id="edMain">
                @yield('content')
            </main>
        @else
            <main class="ed-main dash-container">
                <div class="dash-content">
                    <div class="page-header">
                        <div class="page-block">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                                <div>
                                    <div class="page-header-title"><h4 class="mb-2">@yield('page-title')</h4></div>
                                    <ul class="breadcrumb">@yield('breadcrumb')</ul>
                                </div>
                                <div class="action-btn-col">@yield('action-btn')</div>
                            </div>
                        </div>
                    </div>
                    @yield('content')
                </div>
            </main>

            <div class="modal fade" id="commonModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="exampleModalLabel"></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="body"></div>
                    </div>
                </div>
            </div>
            <div class="modal fade" id="commonModalOver" tabindex="-1" role="dialog" aria-labelledby="commonModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="commonModalLabel"></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body"></div>
                    </div>
                </div>
            </div>
            <div class="position-fixed top-0 end-0 p-3" style="z-index: 99999">
                <div id="liveToast" class="toast text-white fade" role="alert" aria-live="assertive" aria-atomic="true">
                    <div class="d-flex">
                        <div class="toast-body"></div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                </div>
            </div>
            @include('partials.admin.footer', ['edShell' => true])
        @endif
    </div>

    <dialog class="ed-dialog" id="edConfirm">
        <form id="edConfirmForm" method="dialog">
            <div class="ed-dialog__body">
                <h3 id="edConfirmTitle"></h3>
                <p id="edConfirmText"></p>
                <div class="ed-field" id="edConfirmReasonWrap" hidden style="margin-top:12px">
                    <label class="ed-label" for="edConfirmReason">{{ __('السبب') }}<em>*</em></label>
                    <textarea class="ed-textarea" id="edConfirmReason" minlength="3" maxlength="400" rows="2"></textarea>
                    <span class="ed-hint">{{ __('يُحفظ السبب في سجل الأنشطة.') }}</span>
                </div>
            </div>
            <div class="ed-dialog__foot">
                <button type="button" class="ed-btn" id="edConfirmNo">{{ __('تراجع') }}</button>
                <button type="submit" class="ed-btn ed-btn--solid-danger" id="edConfirmYes" data-default="{{ __('تأكيد') }}">{{ __('تأكيد') }}</button>
            </div>
        </form>
    </dialog>

    <script>window.edFlash = @json($edFlash);</script>
    @stack('edata-vendor')
    <script src="{{ asset('edata/js/edata.js') }}?v=2"></script>
    @stack('edata-scripts')
</body>
</html>
