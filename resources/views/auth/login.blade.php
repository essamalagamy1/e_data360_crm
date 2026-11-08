@php
    use App\Models\Utility;
    $settings = Utility::settings();
    $logoBase = Utility::get_file('uploads/logo');
    $logoFile = $settings['company_logo_light'] ?? '';
    $locale = app()->getLocale();
    $rtl = $locale !== 'en';
    $recaptcha = ($settings['recaptcha_module'] ?? 'off') === 'on';
    $recaptchaV2 = ($settings['google_recaptcha_version'] ?? '') === 'v2-checkbox';
    $hasPasswordReset = \Route::has('password.request');
    $loginPhase = \App\Support\Edata\Moments::phase(\Carbon\Carbon::now(($settings['timezone'] ?? '') ?: config('app.timezone')));
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('تسجيل الدخول') }} - {{ config('edata.app_name') }}</title>
    <link rel="icon" href="{{ $logoBase . '/' . ($settings['company_favicon'] ?? 'favicon.png') }}" type="image/png">
    <link rel="stylesheet" href="{{ asset('assets/fonts/tabler-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('edata/css/edata.css') }}?v=2">
    <link rel="stylesheet" href="{{ asset('edata/css/edata-me.css') }}?v=2">
    <style>
        .login { min-height: 100vh; display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(380px, .9fr); background: var(--surface); }
        .login__art { position: relative; overflow: hidden; display: flex; flex-direction: column; justify-content: flex-end; padding: 56px 56px 150px; }
        .login__art > * { position: relative; z-index: 1; }
        .login__art > .ed-sky__stars { position: absolute; z-index: 0; }
        .ed-app .login__art h1 { color: #fff; font-size: clamp(30px, 3.6vw, 50px); font-weight: 800; line-height: 1.2; max-width: 14em; animation: edWipe 1.1s .15s var(--ease) both; }
        .login__art p { color: #DDF3F4; margin-top: 12px; max-width: 30em; font-size: clamp(15px, 1.4vw, 18px); animation: edRiseIn .8s .9s var(--ease) both; }
        [dir="ltr"] .ed-app .login__art h1 { animation-name: edWipeLtr; }
        .login__sun { position: absolute; z-index: 0; inset-inline-start: 50%; bottom: -60px; width: 190px; height: 190px; margin-inline-start: -95px; border-radius: 50%; background: radial-gradient(circle at 50% 50%, #fff 0%, #F2FFD2 30%, #BFFA65 62%, rgba(191, 250, 101, .15) 72%, transparent 74%); box-shadow: 0 0 120px 60px rgba(191, 250, 101, .32); animation: loginRise 2.6s var(--ease) both; }
        .is-night .login__sun, .is-evening .login__sun { background: radial-gradient(circle at 38% 38%, #fff, #DDEBF2 58%, #A9C6D3 70%, transparent 72%); box-shadow: 0 0 80px 30px rgba(214, 235, 245, .22); }
        .login__land { position: absolute; z-index: 0; inset-inline: -6%; bottom: -60px; height: 130px; border-radius: 50% 50% 0 0 / 100% 100% 0 0; background: #00172F; box-shadow: 0 -2px 0 rgba(191, 250, 101, .55), 0 -22px 70px rgba(0, 211, 181, .26); }
        @keyframes loginRise { from { transform: translateY(150px) scale(.9); } to { transform: none; } }
        .login__form { display: flex; align-items: center; justify-content: center; padding: 32px 24px; }
        .login__card { width: min(400px, 100%); display: grid; gap: 16px; }
        .login__card h2 { font-size: 24px; font-weight: 800; }
        .login__brand { display: flex; align-items: center; gap: 10px; min-height: 44px; margin-bottom: 6px; }
        .login__brand img { max-height: 44px; width: auto; }
        .login__pass { position: relative; }
        .login__pass button { position: absolute; inset-inline-end: 6px; top: 6px; width: 28px; height: 28px; border: 0; background: none; color: var(--text-3); cursor: pointer; font-size: 18px; }
        @media (max-width: 860px) {
            .login { grid-template-columns: minmax(0, 1fr); }
            .login__art { padding: 28px 24px 86px; min-height: 240px; }
            .login__sun { width: 110px; height: 110px; margin-inline-start: -55px; bottom: -34px; }
            .login__land { height: 82px; bottom: -44px; }
        }
    </style>
    @if ($recaptcha && $recaptchaV2)
        {!! NoCaptcha::renderJs() !!}
    @elseif ($recaptcha)
        <script src="https://www.google.com/recaptcha/api.js?render={{ $settings['google_recaptcha_key'] ?? '' }}"></script>
    @endif
</head>
<body class="ed-app">
    <div class="ed-ribbon"></div>
    <main class="login">
        <section class="login__art ed-sky is-{{ $loginPhase }}">
            <i class="ed-sky__stars" aria-hidden="true"></i>
            <i class="login__sun" aria-hidden="true"></i>
            <i class="login__land" aria-hidden="true"></i>
            <h1>{{ __('شركاء في كل نجاح') }}</h1>
            <p>{{ \App\Support\Edata\Moments::loginLine() }}</p>
        </section>
        <section class="login__form">
            <form class="login__card" method="POST" action="{{ route('login') }}" id="loginForm">
                @csrf
                <div class="login__brand">
                    <img src="{{ $logoBase . '/' . ($settings['company_logo_dark'] ?? 'logo-dark.png') }}" alt="E-Data 360" onerror="this.remove()">
                </div>
                <div>
                    <h2>{{ __('تسجيل الدخول') }}</h2>
                    <p class="ed-hint">{{ config('edata.app_name') }}</p>
                </div>

                @if (session('status'))
                    <div class="ed-alert tone-bad" role="alert"><i class="ti ti-alert-circle"></i><span>{{ session('status') }}</span></div>
                @endif
                @if (session('error'))
                    <div class="ed-alert tone-bad" role="alert"><i class="ti ti-alert-circle"></i><span>{{ strip_tags((string) session('error')) }}</span></div>
                @endif

                <x-ed.field :label="__('البريد الإلكتروني')" name="email" required>
                    <input class="ed-input @error('email') is-invalid @enderror" type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" dir="ltr">
                </x-ed.field>

                <x-ed.field :label="__('كلمة المرور')" name="password" required>
                    <div class="login__pass">
                        <input class="ed-input @error('password') is-invalid @enderror" type="password" id="password" name="password" required autocomplete="current-password" dir="ltr">
                        <button type="button" aria-label="{{ __('إظهار كلمة المرور') }}" onclick="var i=document.getElementById('password');i.type=i.type==='password'?'text':'password'"><i class="ti ti-eye"></i></button>
                    </div>
                </x-ed.field>

                @if ($hasPasswordReset)
                    <a class="ed-link" style="font-size:13px" href="{{ route('password.request', $lang ?? $locale) }}">{{ __('نسيت كلمة المرور؟') }}</a>
                @endif

                @if ($recaptcha)
                    @if ($recaptchaV2)
                        <div>{!! NoCaptcha::display() !!}</div>
                    @else
                        <input type="hidden" id="g-recaptcha-response" name="g-recaptcha-response">
                    @endif
                    @error('g-recaptcha-response')<span class="ed-error">{{ $message }}</span>@enderror
                @endif

                <button class="ed-btn ed-btn--primary" type="submit" style="height:46px;font-size:15px">{{ __('دخول') }}</button>

                @if (($settings['enable_signup'] ?? 'off') === 'on')
                    <p class="ed-hint" style="text-align:center">{{ __('ليس لديك حساب؟') }} <a class="ed-link" href="{{ route('register', ['0', $lang ?? $locale]) }}">{{ __('إنشاء حساب') }}</a></p>
                @endif

                <div style="display:flex;gap:10px;justify-content:center;font-size:13px">
                    <a class="ed-link" href="{{ route('login', 'ar') }}">العربية</a>
                    <a class="ed-link" href="{{ route('login', 'en') }}">English</a>
                </div>
            </form>
        </section>
    </main>
    @if ($recaptcha && !$recaptchaV2)
        <script>
            grecaptcha.ready(function () {
                grecaptcha.execute(@json($settings['google_recaptcha_key'] ?? ''), { action: 'submit' }).then(function (token) {
                    document.getElementById('g-recaptcha-response').value = token;
                });
            });
        </script>
    @endif
</body>
</html>
