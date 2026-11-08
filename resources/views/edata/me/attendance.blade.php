@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', __('حضوري'))

@php use App\Support\Edata\Labels; @endphp

@section('content')
<div class="ed-stack ed-reveal">
    @include('edata.attendance._clock')

    <div class="ed-figures">
        <x-ed.figure :label="__('ساعات هذا الشهر')" :value="Labels::hours($totals['seconds'])" />
        <x-ed.figure :label="__('أيام الحضور')" :count="$totals['attended']" :hint="__('من') . ' ' . $totals['expected'] . ' ' . __('يوم عمل')" />
        <x-ed.figure :label="__('مرات التأخير')" :count="$totals['late']" :tone="$totals['late'] ? 'warn' : null" />
        <x-ed.figure :label="__('الغياب')" :count="$totals['absent']" :tone="$totals['absent'] ? 'bad' : null" />
        <x-ed.figure :label="__('ساعات إضافية')" :value="Labels::hours($totals['overtime_seconds'])" />
    </div>

    <section class="ed-panel">
        <div class="ed-panel__head">
            <div>
                <h2>{{ __('سجل حضوري') }}</h2>
                <p>{{ $month->copy()->locale(app()->getLocale())->translatedFormat('F Y') }}</p>
            </div>
            <div class="ed-actions">
                <a class="ed-btn ed-btn--sm" href="{{ route('edata.me.attendance', ['month' => $month->copy()->subMonth()->format('Y-m')]) }}"><i class="ti ti-chevron-right"></i>{{ __('الشهر السابق') }}</a>
                @unless ($isCurrentMonth)
                    <a class="ed-btn ed-btn--sm" href="{{ route('edata.me.attendance', ['month' => $month->copy()->addMonth()->format('Y-m')]) }}">{{ __('الشهر التالي') }}<i class="ti ti-chevron-left"></i></a>
                @endunless
            </div>
        </div>
        @php $shown = $days->reject(fn ($d) => in_array($d['status'], ['weekend', 'not_yet'], true)); @endphp
        @if ($shown->isEmpty())
            <x-ed.empty icon="ti-calendar-off" :title="__('لا توجد سجلات في هذا الشهر.')" />
        @else
            <div class="ed-table-wrap">
                <table class="ed-table">
                    <thead>
                        <tr><th>{{ __('التاريخ') }}</th><th>{{ __('وقت الدخول') }}</th><th>{{ __('وقت الخروج') }}</th><th>{{ __('ساعات العمل') }}</th><th>{{ __('الحالة') }}</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($shown as $day)
                            <tr>
                                <td>{{ Labels::date($day['date'], 'l، j M') }}</td>
                                <td class="ed-num">{{ $day['in'] ?? '—' }}</td>
                                <td class="ed-num">{{ $day['out'] ?? '—' }}</td>
                                <td class="ed-num">{{ $day['seconds'] ? Labels::hours($day['seconds']) : '—' }}</td>
                                <td>
                                    <x-ed.badge :pair="Labels::attendance($day['status'])" />
                                    @foreach ($day['flags'] as $flag)
                                        @if ($flag !== $day['status'])<x-ed.badge :pair="Labels::attendance($flag)" />@endif
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
    <p class="ed-hint">{{ __('السجل قراءة فقط. لو فيه خطأ في وقت مسجل، اطلب من مديرك تصحيحه، والتعديل يتوثق باسمه.') }}</p>
</div>
@endsection
