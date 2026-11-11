@php
    use App\Support\Edata\Labels;
    use App\Support\Edata\Moments;

    $heroUser = auth()->user();
    $heroEmployee = $employee ?? null;
    $heroState = $state ?? null;
    $hero = Moments::hero($heroUser, $heroEmployee, (int) $heroUser->creatorId());

    if ($heroState) {
        $rules = $heroState['rules'];
        $service = \App\Support\Edata\AttendanceService::for((int) $heroUser->creatorId());
        $nowLocal = \Carbon\Carbon::now($rules['zone'])->locale(app()->getLocale());
        $statusPair = Labels::attendance($heroState['status']);
        $working = $heroState['is_open'];
        $shiftStart = \Carbon\Carbon::parse($nowLocal->toDateString() . ' ' . $rules['start'], $rules['zone']);
        $shiftEnd = \Carbon\Carbon::parse($nowLocal->toDateString() . ' ' . $rules['end'], $rules['zone']);
        // Real worked periods today, drawn on the arc as they happened.
        $segments = $heroState['sessions']->map(function ($row) use ($service, $heroState) {
            $in = $service->moment($row, 'in');
            $out = $service->moment($row, 'out');
            if (!$in) {
                return null;
            }
            $isOpen = $heroState['open_id'] === $row->id;
            if (!$out && !$isOpen) {
                return null;
            }

            return [$in->getTimestamp(), $out?->getTimestamp()];
        })->filter()->values();
    }
@endphp
<section class="ed-day ed-sky is-{{ $hero['phase'] }}"
         @if ($heroState)
             data-clock
             data-server-epoch="{{ $heroState['server_epoch'] }}"
             data-zone="{{ $rules['zone'] }}"
             data-finished="{{ $heroState['finished_seconds'] }}"
             data-shift-start="{{ $shiftStart->getTimestamp() }}"
             data-shift-end="{{ $shiftEnd->getTimestamp() }}"
             data-segments="{{ $segments->toJson() }}"
             @if ($working) data-open-since="{{ $heroState['open_since_epoch'] }}" @endif
         @endif
         @if ($hero['occasion'] && $hero['occasion']['celebrate']) data-celebrate="{{ $heroUser->id }}" @endif
         aria-label="{{ __('مساحتك اليوم') }}">
    <i class="ed-sky__stars" aria-hidden="true"></i>

    <header class="ed-day__hello">
        @if ($hero['occasion'])
            <div class="ed-day__occasion"><i class="ti {{ $hero['occasion']['icon'] }}"></i><span>{{ $hero['occasion']['text'] }}</span></div>
        @endif
        <h1>{{ $hero['greeting'] }}</h1>
        <p class="ed-day__thanks">{{ $hero['thanks'] }}</p>
    </header>

    @if ($heroState)
        <div class="ed-day__clock">
            <div class="ed-day__time ed-num" data-clock-time>{{ $nowLocal->format('H:i:s') }}</div>
            <div class="ed-day__date">
                <span>{{ $nowLocal->translatedFormat('l، j F Y') }}</span>
                @if ($hero['hijri'])<span class="is-hijri">{{ $hero['hijri'] }}</span>@endif
            </div>
            <div class="ed-day__state">
                @if ($heroState['status'] !== 'late')
                    <span class="ed-badge {{ $working ? 'is-working' : '' }}">@if ($working)<i class="ed-pulse"></i>@endif{{ __($statusPair[0]) }}</span>
                @endif
                @if ($heroState['late_minutes'] > 0)
                    <span class="ed-badge">{{ __('متأخر') }} {{ $heroState['late_minutes'] }} {{ __('دقيقة') }}</span>
                @endif
            </div>
            <dl class="ed-day__facts">
                <div><dt>{{ __('وقت الدخول') }}</dt><dd class="ed-num">{{ $heroState['first_in'] ?? '--:--' }}</dd></div>
                <div><dt>{{ __('وقت الخروج') }}</dt><dd class="ed-num">{{ $heroState['last_out'] ?? '--:--' }}</dd></div>
                <div><dt>{{ __('الدوام') }}</dt><dd class="ed-num" dir="ltr">{{ $rules['start'] }} - {{ $rules['end'] }}</dd></div>
            </dl>
        </div>

        <div class="ed-day__action">
            <div class="ed-day__timer">
                <span>{{ $working ? __('مدة العمل الحالية') : __('إجمالي اليوم') }}</span>
                @if ($working)
                    <b class="ed-num" data-clock-session>00:00:00</b>
                @else
                    <b class="ed-num" data-clock-total>{{ gmdate('H:i:s', $heroState['total_seconds']) }}</b>
                @endif
            </div>
            @if ($working)
                <form method="POST" action="{{ route('edata.me.clock-out') }}" data-moment-form data-confirm="{{ __('تسجيل الانصراف الآن؟') }}" data-confirm-text="{{ __('سيتوقف العداد ويُحفظ وقت خروجك.') }}" data-confirm-yes="{{ __('تسجيل الانصراف') }}" data-confirm-safe @if (\App\Support\Edata\EmployeeServices::allows(auth()->user(), 'plan')) data-confirm-note="report" data-confirm-note-label="{{ __('تقرير نهاية اليوم (اختياري)') }}" data-confirm-note-placeholder="{{ __('ما الذي تم إنجازه اليوم؟ وما الذي يحتاج إلى مساعدة؟') }}" data-confirm-note-hint="{{ __('يصل إلى الإدارة في صفحة التقارير اليومية.') }}" @endif>
                    @csrf
                    <button type="submit" class="ed-clock__btn ed-clock__btn--out"><i class="ti ti-logout"></i>{{ __('تسجيل الانصراف') }}</button>
                </form>
            @else
                <form method="POST" action="{{ route('edata.me.clock-in') }}" data-moment-form>
                    @csrf
                    <button type="submit" class="ed-clock__btn ed-clock__btn--in"><i class="ti ti-sunrise"></i>{{ __('تسجيل الحضور') }}</button>
                </form>
            @endif
        </div>

        <div class="ed-day__horizon" data-arc aria-hidden="true">
            <svg viewBox="0 0 1000 170" preserveAspectRatio="none">
                <defs>
                    <linearGradient id="edArcWorked" gradientUnits="userSpaceOnUse" x1="{{ app()->getLocale() === 'en' ? 0 : 1000 }}" y1="0" x2="{{ app()->getLocale() === 'en' ? 1000 : 0 }}" y2="0">
                        <stop offset="0" stop-color="#BFFA65" />
                        <stop offset=".5" stop-color="#00D3B5" />
                        <stop offset="1" stop-color="#00C2C6" />
                    </linearGradient>
                </defs>
                <path class="ed-day__arc" d="M60 130 Q500 -130 940 130" />
                <g data-arc-worked></g>
            </svg>
            <i class="ed-day__sun" data-sun></i>
            <span class="ed-day__tick is-start ed-num">{{ $rules['start'] }}</span>
            <span class="ed-day__tick is-end ed-num">{{ $rules['end'] }}</span>
        </div>
    @endif
</section>
