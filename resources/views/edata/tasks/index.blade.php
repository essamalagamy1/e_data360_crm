@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', __('المهام'))

@php
    use App\Support\Edata\Labels;
    use App\Support\Edata\TaskRules;
    $query = request()->except(['view', 'page']);
@endphp

@section('content')
<x-ed.head :title="__('المهام')" :sub="__('من يعمل على ماذا، وما الذي يحتاج تدخلاً الآن.')">
    <div class="ed-seg" aria-label="{{ __('طريقة العرض') }}">
        <a href="{{ route('edata.tasks.index', array_merge($query, ['view' => 'table'])) }}" class="{{ $view === 'table' ? 'is-active' : '' }}"><i class="ti ti-table"></i>{{ __('جدول') }}</a>
        <a href="{{ route('edata.tasks.index', array_merge($query, ['view' => 'board'])) }}" class="{{ $view === 'board' ? 'is-active' : '' }}"><i class="ti ti-layout-kanban"></i>{{ __('لوحة') }}</a>
    </div>
    @if ($canCreate)
        <a class="ed-btn ed-btn--primary" href="{{ route('edata.tasks.create') }}"><i class="ti ti-plus"></i>{{ __('مهمة جديدة') }}</a>
    @endif
</x-ed.head>

<div class="ed-stack ed-reveal">
    <div class="ed-figures">
        <x-ed.figure :label="__('مفتوحة')" :count="$stats['open']" :href="route('edata.tasks.index', ['view' => $view])" />
        <x-ed.figure :label="__('مهامي')" :count="$stats['mine']" :href="route('edata.tasks.index', ['view' => $view, 'state' => 'mine'])" />
        <x-ed.figure :label="__('متأخرة')" :count="$stats['overdue']" :tone="$stats['overdue'] ? 'bad' : null" :href="route('edata.tasks.index', ['view' => $view, 'state' => 'overdue'])" />
        <x-ed.figure :label="__('بلا تحديث منذ فترة')" :count="$stats['stale']" :tone="$stats['stale'] ? 'warn' : null" :href="route('edata.tasks.index', ['view' => $view, 'state' => 'stale'])" />
        <x-ed.figure :label="__('تحتاج تدخل المدير')" :value="__('عرض')" :href="route('edata.tasks.index', ['view' => $view, 'state' => 'attention'])" />
    </div>

    <section class="ed-panel">
        <form class="ed-filters" method="GET" data-autosubmit>
            <input type="hidden" name="view" value="{{ $view }}">
            <div class="ed-search">
                <i class="ti ti-search" aria-hidden="true"></i>
                <input class="ed-input" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('ابحث باسم المهمة') }}" aria-label="{{ __('ابحث باسم المهمة') }}">
            </div>
            <select class="ed-select" name="project" aria-label="{{ __('المشروع') }}">
                <option value="">{{ __('كل المشاريع المفتوحة') }}</option>
                @foreach ($projects as $p)<option value="{{ $p->id }}" @selected((int) request('project') === (int) $p->id)>{{ $p->project_name }}</option>@endforeach
            </select>
            <select class="ed-select" name="assignee" aria-label="{{ __('الموظف') }}">
                <option value="">{{ __('كل الموظفين') }}</option>
                @foreach ($users as $u)<option value="{{ $u->id }}" @selected((int) request('assignee') === (int) $u->id)>{{ $u->name }}</option>@endforeach
            </select>
            <select class="ed-select" name="priority" aria-label="{{ __('الأولوية') }}">
                <option value="">{{ __('كل الأولويات') }}</option>
                @foreach (Labels::TASK_PRIORITIES as $value => $pair)<option value="{{ $value }}" @selected(request('priority') === $value)>{{ __($pair[0]) }}</option>@endforeach
            </select>
            @if ($view === 'table')
                <select class="ed-select" name="stage" aria-label="{{ __('الحالة') }}">
                    <option value="">{{ __('كل الحالات') }}</option>
                    @foreach ($stages as $s)<option value="{{ $s->id }}" @selected((int) request('stage') === (int) $s->id)>{{ __(TaskRules::stageName($s->name)) }}</option>@endforeach
                </select>
                <select class="ed-select" name="state" aria-label="{{ __('العرض') }}">
                    <option value="">{{ __('كل المهام') }}</option>
                    <option value="mine" @selected(request('state') === 'mine')>{{ __('مهامي') }}</option>
                    <option value="overdue" @selected(request('state') === 'overdue')>{{ __('متأخرة') }}</option>
                    <option value="stale" @selected(request('state') === 'stale')>{{ __('بلا تحديث') }}</option>
                    <option value="attention" @selected(request('state') === 'attention')>{{ __('تحتاج تدخلاً') }}</option>
                    <option value="done" @selected(request('state') === 'done')>{{ __('مكتملة') }}</option>
                    <option value="cancelled" @selected(request('state') === 'cancelled')>{{ __('ملغاة') }}</option>
                </select>
            @elseif (request('state'))
                <input type="hidden" name="state" value="{{ request('state') }}">
            @endif
            @if (request()->hasAny(['q', 'project', 'assignee', 'priority', 'stage', 'state']))
                <a class="ed-btn ed-btn--ghost ed-btn--sm" href="{{ route('edata.tasks.index', ['view' => $view]) }}">{{ __('مسح الفلاتر') }}</a>
            @endif
        </form>

        @if ($view === 'board')
            <div class="ed-panel__body">
                @if (empty($columns))
                    <x-ed.empty icon="ti-layout-kanban" :title="__('لم تُعرّف مراحل للمهام بعد.')" />
                @else
                    <div class="ed-board">
                        @foreach ($columns as $column)
                            <div class="ed-col" data-col="{{ $column['stage']->id }}" data-col-field="stage_id">
                                <div class="ed-col__head"><span>{{ __($column['name']) }}</span><small class="ed-num" data-col-count>{{ $column['count'] }}</small></div>
                                <div class="ed-col__list" data-col-list>
                                    @foreach ($column['tasks'] as $task)
                                        @php $isLate = TaskRules::isOverdue($task, $company); @endphp
                                        <div class="ed-card" draggable="true" data-id="{{ $task->id }}" data-move-url="{{ route('edata.tasks.move', $task->id) }}">
                                            <a class="ed-card__title" href="{{ route('edata.tasks.show', $task->id) }}">{{ $task->name }}</a>
                                            <span class="ed-card__sub">{{ $task->project->project_name ?? '' }}</span>
                                            <div class="ed-card__meta">
                                                <span class="ed-avatars">
                                                    @foreach (TaskRules::assigneeIds($task) as $userId)
                                                        @if (isset($users[$userId]))<x-ed.avatar :user="$users[$userId]" size="sm" />@endif
                                                    @endforeach
                                                </span>
                                                <span style="display:inline-flex;gap:6px;align-items:center">
                                                    @if ($task->priority === 'critical' || $task->priority === 'high')<x-ed.badge :pair="Labels::priority($task->priority)" />@endif
                                                    @if ($task->end_date)<span class="ed-due {{ $isLate ? 'is-late' : '' }}">{{ Labels::date($task->end_date, 'j M') }}</span>@endif
                                                </span>
                                            </div>
                                        </div>
                                    @endforeach
                                    @if ($column['count'] > $column['tasks']->count())
                                        <a class="ed-link" style="text-align:center;font-size:12.5px;padding:6px" href="{{ route('edata.tasks.index', array_merge($query, ['view' => 'table', 'stage' => $column['stage']->id])) }}">{{ __('عرض كل') }} {{ $column['count'] }}</a>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @elseif ($paginator->isEmpty())
            <x-ed.empty icon="ti-checkbox" :title="__('لا توجد مهام تطابق هذا البحث.')">
                @if ($canCreate)<a class="ed-btn ed-btn--primary" href="{{ route('edata.tasks.create') }}">{{ __('مهمة جديدة') }}</a>@endif
            </x-ed.empty>
        @else
            <div class="ed-table-wrap">
                <table class="ed-table">
                    <thead><tr><th>{{ __('المهمة') }}</th><th>{{ __('المسؤول') }}</th><th>{{ __('الأولوية') }}</th><th>{{ __('التسليم') }}</th><th>{{ __('الإنجاز') }}</th><th>{{ __('الحالة') }}</th></tr></thead>
                    <tbody>
                        @foreach ($paginator as $task)
                            @php
                                $isLate = TaskRules::isOverdue($task, $company);
                                $reason = TaskRules::needsAttention($task, $company);
                                $assignees = collect(TaskRules::assigneeIds($task))->map(fn ($id) => $users[$id] ?? null)->filter();
                            @endphp
                            <tr>
                                <td>
                                    <a class="ed-table__title" href="{{ route('edata.tasks.show', $task->id) }}">{{ $task->name }}</a>
                                    <span class="ed-table__sub">{{ $task->project->project_name ?? '' }}@if ($reason) ، <span class="tone-text-warn">{{ __($reason) }}</span>@endif</span>
                                </td>
                                <td>
                                    @if ($assignees->isEmpty())
                                        <span class="ed-hint">{{ __('بلا مسؤول') }}</span>
                                    @else
                                        <span class="ed-avatars" title="{{ $assignees->pluck('name')->implode('، ') }}">
                                            @foreach ($assignees as $assignee)<x-ed.avatar :user="$assignee" size="sm" />@endforeach
                                        </span>
                                    @endif
                                </td>
                                <td><x-ed.badge :pair="Labels::priority($task->priority)" /></td>
                                <td class="is-tight"><span class="ed-due {{ $isLate ? 'is-late' : '' }}">{{ Labels::date($task->end_date, 'j M Y') }}</span></td>
                                <td style="min-width:120px"><x-ed.progress :value="(int) $task->progress" :late="$isLate" /></td>
                                <td><x-ed.badge :pair="TaskRules::state($task, $company)" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-ed.pager :items="$paginator" />
        @endif
    </section>
</div>
@endsection
