@php
    use App\Support\Edata\Labels;
    $sMonth = $summary['month'];
    $prev = $sMonth->copy()->subMonth()->format('Y-m');
    $next = $sMonth->copy()->addMonth()->format('Y-m');
@endphp
<section class="ed-panel" id="month">
    <div class="ed-panel__head">
        <div><h2>{{ __('ملخص الشهر') }}</h2><p>{{ Labels::date($sMonth->toDateString(), 'F Y') }}</p></div>
        <div class="ed-seg" aria-label="{{ __('اختيار الشهر') }}">
            <a href="{{ route('edata.me', ['m' => $prev]) }}#month" title="{{ __('الشهر السابق') }}" aria-label="{{ __('الشهر السابق') }}"><i class="ti ti-chevron-right"></i></a>
            @unless ($isThisMonth)
                <a href="{{ route('edata.me', ['m' => $next]) }}#month" title="{{ __('الشهر التالي') }}" aria-label="{{ __('الشهر التالي') }}"><i class="ti ti-chevron-left"></i></a>
            @endunless
        </div>
    </div>
    <div class="ed-msum">
        <div><i class="ti ti-clock"></i><b class="ed-num">{{ Labels::hours($summary['seconds']) }}</b><span>{{ __('ساعات العمل') }}</span></div>
        <div><i class="ti ti-calendar-event"></i><b class="ed-num">{{ $summary['attended'] }}</b><span>{{ __('أيام حضور') }}</span></div>
        <div><i class="ti ti-alarm"></i><b class="ed-num">{{ $summary['on_time'] }}</b><span>{{ __('أيام في الموعد') }}</span></div>
        <div><i class="ti ti-chart-donut"></i><b class="ed-num">{{ $summary['rate'] !== null ? $summary['rate'] . '%' : '—' }}</b><span>{{ __('الالتزام') }}</span></div>
        <div><i class="ti ti-circle-check"></i><b class="ed-num">{{ $summary['tasks'] }}</b><span>{{ __('مهام أُنجزت') }}</span></div>
        <div><i class="ti ti-player-play"></i><b class="ed-num">{{ Labels::hours($summary['timer_seconds']) }}</b><span>{{ __('وقت مسجّل على المهام') }}</span></div>
        <div><i class="ti ti-heart"></i><b class="ed-num">{{ $summary['kudos'] }}</b><span>{{ __('كلمات شكر وصلتك') }}</span></div>
        <div><i class="ti ti-calendar-minus"></i><b class="ed-num">{{ $summary['leave'] }}</b><span>{{ __('أيام إجازة') }}</span></div>
    </div>
</section>
