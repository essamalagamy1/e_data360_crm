@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', __('التقارير اليومية'))

@php
    use App\Support\Edata\EmployeeServices;
    use App\Support\Edata\Labels;
    use App\Support\Edata\TaskRules;

    $day = \Carbon\Carbon::parse($date);
    $doneStages = TaskRules::doneStageIds($company);
    $withReport = $logs->filter(fn ($log) => filled($log->report))->count();
    $withPlan = $logs->filter(fn ($log) => count(EmployeeServices::planIds($log)) > 0)->count();
@endphp

@section('content')
<x-ed.head :title="__('التقارير اليومية')" :sub="__('خطة كل موظف في بداية اليوم، وتقريره عند الانصراف، مع أوقات الحضور.')">
    <form method="GET" class="ed-dayjump" data-autosubmit>
        <a class="ed-btn ed-btn--ghost ed-btn--sm" href="{{ route('edata.daily.index', ['date' => $day->copy()->subDay()->toDateString()]) }}" aria-label="{{ __('اليوم السابق') }}"><i class="ti ti-chevron-right"></i></a>
        <input class="ed-input" type="date" name="date" value="{{ $date }}" aria-label="{{ __('التاريخ') }}">
        <a class="ed-btn ed-btn--ghost ed-btn--sm" href="{{ route('edata.daily.index', ['date' => $day->copy()->addDay()->toDateString()]) }}" aria-label="{{ __('اليوم التالي') }}"><i class="ti ti-chevron-left"></i></a>
    </form>
</x-ed.head>

<div class="ed-figures ed-reveal" style="margin-bottom:16px">
    <x-ed.figure :label="__('الموظفون')" :count="$employees->count()" />
    <x-ed.figure :label="__('كتبوا خطة اليوم')" :count="$withPlan" />
    <x-ed.figure :label="__('كتبوا تقرير اليوم')" :count="$withReport" tone="good" />
</div>

<section class="ed-panel ed-reveal">
    <div class="ed-panel__head"><h2>{{ Labels::date($date, 'l، j F Y') }}</h2></div>
    @if ($employees->isEmpty())
        <x-ed.empty icon="ti-users" :title="__('لا يوجد موظفون في نطاقك.')" />
    @else
        <div class="ed-dailylist">
            @foreach ($employees as $person)
                @php
                    $d = $days[$person->id] ?? null;
                    $log = $logs[$person->id] ?? null;
                    $planned = collect(EmployeeServices::planIds($log))->map(fn ($id) => $tasks[$id] ?? null)->filter();
                @endphp
                <article class="ed-daily">
                    <div class="ed-daily__who">
                        <b>{{ $person->name }}</b>
                        @if ($d)
                            <x-ed.badge :pair="Labels::attendance($d['status'])" />
                            @if (in_array('permission', $d['flags'], true))<span class="ed-badge tone-info">{{ __('بإذن') }}</span>@endif
                            <span class="ed-num ed-daily__times">
                                @if ($d['in']){{ __('دخول') }} {{ $d['in'] }}@endif
                                @if ($d['out']) · {{ __('خروج') }} {{ $d['out'] }}@endif
                                @if ($d['seconds']) · {{ Labels::hours($d['seconds']) }}@endif
                            </span>
                        @endif
                    </div>
                    <div class="ed-daily__cols">
                        <div>
                            <h3>{{ __('خطة اليوم') }}</h3>
                            @if ($planned->isEmpty())
                                <p class="ed-hint">{{ __('لم تُكتب خطة.') }}</p>
                            @else
                                <ul class="ed-plan ed-plan--sm">
                                    @foreach ($planned as $t)
                                        @php $isDone = (int) $t->is_complete === 1 || in_array((int) $t->stage_id, $doneStages, true); @endphp
                                        <li class="{{ $isDone ? 'is-done' : '' }}"><i class="ti {{ $isDone ? 'ti-circle-check' : 'ti-target' }}"></i><a href="{{ route('edata.tasks.show', $t->id) }}">{{ $t->name }}</a></li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                        <div>
                            <h3>{{ __('تقرير نهاية اليوم') }}</h3>
                            @if ($log && filled($log->report))
                                <p class="ed-daily__report">{!! nl2br(e($log->report)) !!}</p>
                            @else
                                <p class="ed-hint">{{ __('لم يُكتب تقرير.') }}</p>
                            @endif
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</section>
@endsection
