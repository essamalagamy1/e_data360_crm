@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', $person->name)

@php
    use App\Support\Edata\Labels;
    use App\Support\Edata\TaskRules;
    $total = $openCount + $doneCount;
    $completion = $total > 0 ? (int) round($doneCount / $total * 100) : 0;
@endphp

@section('content')
<x-ed.head :title="$person->name" :sub="($designation->name ?? __('بدون مسمى وظيفي')) . ($department ? '، ' . $department->name : '')"
    :crumbs="$isSelf ? [] : [__('الموظفون') => route('edata.people.index')]">
    @if ($canEdit)
        <a class="ed-btn" href="{{ route('edata.people.edit', $person->id) }}"><i class="ti ti-pencil"></i>{{ __('تعديل') }}</a>
    @endif
</x-ed.head>

<div class="ed-stack ed-reveal">
    <div class="ed-figures">
        <x-ed.figure :label="__('مهام مفتوحة')" :count="$openCount" />
        <x-ed.figure :label="__('مهام مكتملة')" :count="$doneCount" tone="good" />
        <x-ed.figure :label="__('مهام متأخرة')" :count="$overdue" :tone="$overdue ? 'bad' : null" />
        <x-ed.figure :label="__('ساعات هذا الشهر')" :value="Labels::hours($totals['seconds'])" />
        <x-ed.figure :label="__('الالتزام بالحضور')" :value="$totals['rate'] !== null ? $totals['rate'] . '%' : '—'" />
    </div>

    <div class="ed-grid ed-grid--side">
        <div class="ed-stack">
            <section class="ed-panel">
                <div class="ed-panel__head">
                    <h2>{{ __('المهام المسندة') }}</h2>
                    <div style="min-width:160px"><x-ed.progress :value="$completion" /></div>
                </div>
                @if ($tasks->isEmpty())
                    <x-ed.empty small icon="ti-checks" :title="__('لا توجد مهام مفتوحة.')" />
                @else
                    <div class="ed-list">
                        @foreach ($tasks as $task)
                            <a href="{{ route('edata.tasks.show', $task->id) }}">
                                <div class="ed-list__main">
                                    <span class="ed-list__title">{{ $task->name }}</span>
                                    <span class="ed-list__sub">{{ $task->project->project_name ?? '' }}</span>
                                </div>
                                <x-ed.badge :pair="Labels::priority($task->priority)" />
                                @if ($task->end_date)
                                    <span class="ed-due {{ TaskRules::isOverdue($task, $company) ? 'is-late' : '' }}"><i class="ti ti-calendar"></i>{{ Labels::date($task->end_date, 'j M') }}</span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="ed-panel">
                <div class="ed-panel__head"><h2>{{ __('المشاريع') }}</h2></div>
                @if ($projects->isEmpty())
                    <x-ed.empty small icon="ti-briefcase" :title="__('غير مضاف لأي مشروع.')" />
                @else
                    <div class="ed-list">
                        @foreach ($projects as $project)
                            <a href="{{ route('projects.show', $project->id) }}">
                                <div class="ed-list__main">
                                    <span class="ed-list__title">{{ $project->project_name }}</span>
                                    <span class="ed-list__sub">{{ __('التسليم') }}: {{ Labels::date($project->end_date) }}</span>
                                </div>
                                <x-ed.badge :pair="Labels::project($project->status)" />
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="ed-panel">
                <div class="ed-panel__head"><h2>{{ __('الحضور هذا الشهر') }}</h2></div>
                @php $shown = $days->reject(fn ($d) => in_array($d['status'], ['weekend', 'not_yet'], true)); @endphp
                @if ($shown->isEmpty())
                    <x-ed.empty small icon="ti-calendar-off" :title="__('لا توجد سجلات هذا الشهر.')" />
                @else
                    <div class="ed-table-wrap">
                        <table class="ed-table">
                            <thead><tr><th>{{ __('التاريخ') }}</th><th>{{ __('الدخول') }}</th><th>{{ __('الخروج') }}</th><th>{{ __('الساعات') }}</th><th>{{ __('الحالة') }}</th></tr></thead>
                            <tbody>
                                @foreach ($shown as $day)
                                    <tr>
                                        <td>{{ Labels::date($day['date'], 'D، j M') }}</td>
                                        <td class="ed-num">{{ $day['in'] ?? '—' }}</td>
                                        <td class="ed-num">{{ $day['out'] ?? '—' }}</td>
                                        <td class="ed-num">{{ $day['seconds'] ? Labels::hours($day['seconds']) : '—' }}</td>
                                        <td><x-ed.badge :pair="Labels::attendance($day['status'])" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>

        <div class="ed-stack">
            <section class="ed-panel">
                <div class="ed-panel__body" style="text-align:center">
                    <x-ed.avatar :user="$person" size="lg" />
                    <div style="font-weight:700;margin-top:8px">{{ $person->name }}</div>
                    <div class="ed-hint">{{ $person->type }}</div>
                </div>
                <div class="ed-panel__body" style="border-top:1px solid var(--line)">
                    <dl class="ed-facts">
                        <dt>{{ __('البريد') }}</dt><dd dir="ltr" style="text-align:start">{{ $person->email }}</dd>
                        <dt>{{ __('الهاتف') }}</dt><dd dir="ltr" style="text-align:start">{{ $employee->phone ?? '—' }}</dd>
                        <dt>{{ __('تاريخ التعيين') }}</dt><dd>{{ Labels::date($employee->company_doj ?? null) }}</dd>
                        <dt>{{ __('نوع الدوام') }}</dt><dd>{{ Labels::schedule(\App\Support\Edata\AttendanceService::ownDays($employee)) }}</dd>
                        <dt>{{ __('الحالة الوظيفية') }}</dt><dd><x-ed.badge :pair="Labels::employment($employee->employment_status ?? 'active')" /></dd>
                        <dt>{{ __('الدخول للنظام') }}</dt>
                        <dd>
                            @if ($person->is_enable_login)<x-ed.badge tone="good" :text="__('مفعّل')" />@else<x-ed.badge tone="muted" :text="__('متوقف')" />@endif
                        </dd>
                    </dl>
                </div>
            </section>
            <section class="ed-panel">
                <div class="ed-panel__head"><h2>{{ __('آخر الأنشطة') }}</h2></div>
                <x-ed.trail :logs="$activity" :with-diff="false" />
            </section>
        </div>
    </div>
</div>
@endsection
