@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', __('المشاريع'))

@php
    use App\Support\Edata\Labels;
    use App\Support\Edata\ProjectStats;
    $query = request()->except(['view', 'page']);
    $views = ['cards' => ['ti-layout-grid', 'بطاقات'], 'table' => ['ti-table', 'جدول'], 'board' => ['ti-layout-kanban', 'لوحة'], 'timeline' => ['ti-calendar-stats', 'خط زمني']];
@endphp

@section('content')
<x-ed.head :title="__('المشاريع')" :sub="__('حالة كل مشروع من نظرة واحدة، بدون فتح تفاصيله.')">
    <div class="ed-seg" role="tablist" aria-label="{{ __('طريقة العرض') }}">
        @foreach ($views as $key => [$icon, $label])
            <a href="{{ route('projects.index', array_merge($query, ['view' => $key])) }}" class="{{ $view === $key ? 'is-active' : '' }}"><i class="ti {{ $icon }}"></i>{{ __($label) }}</a>
        @endforeach
    </div>
    @if ($canCreate)
        <a class="ed-btn ed-btn--primary" href="{{ route('projects.create') }}"><i class="ti ti-plus"></i>{{ __('مشروع جديد') }}</a>
    @endif
</x-ed.head>

<div class="ed-stack ed-reveal">
    <div class="ed-figures">
        <x-ed.figure :label="__('إجمالي المشاريع')" :count="$total" :href="route('projects.index', ['view' => $view])" />
        <x-ed.figure :label="__('قيد التنفيذ')" :count="$counts['in_progress'] ?? 0" :href="route('projects.index', ['view' => $view, 'status' => 'in_progress'])" />
        <x-ed.figure :label="__('بانتظار العميل')" :count="$counts['waiting_client'] ?? 0" :href="route('projects.index', ['view' => $view, 'status' => 'waiting_client'])" />
        <x-ed.figure :label="__('متأخرة عن التسليم')" :count="$delayed" :tone="$delayed ? 'bad' : null" :href="route('projects.index', ['view' => $view, 'delayed' => 1])" />
        <x-ed.figure :label="__('مكتملة')" :count="$counts['complete'] ?? 0" tone="good" :href="route('projects.index', ['view' => 'table', 'status' => 'complete'])" />
    </div>

    <section class="ed-panel">
        <form class="ed-filters" method="GET" data-autosubmit>
            <input type="hidden" name="view" value="{{ $view }}">
            <div class="ed-search">
                <i class="ti ti-search" aria-hidden="true"></i>
                <input class="ed-input" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('ابحث باسم المشروع') }}" aria-label="{{ __('ابحث باسم المشروع') }}">
            </div>
            <select class="ed-select" name="status" aria-label="{{ __('الحالة') }}">
                <option value="">{{ __('كل الحالات') }}</option>
                @foreach (Labels::PROJECT_STATUSES as $value => $pair)<option value="{{ $value }}" @selected(request('status') === $value)>{{ __($pair[0]) }}</option>@endforeach
            </select>
            <select class="ed-select" name="customer" aria-label="{{ __('العميل') }}">
                <option value="">{{ __('كل العملاء') }}</option>
                @foreach ($customers as $c)<option value="{{ $c->id }}" @selected((int) request('customer') === (int) $c->id)>{{ $c->name }}</option>@endforeach
            </select>
            <select class="ed-select" name="manager" aria-label="{{ __('مدير المشروع') }}">
                <option value="">{{ __('كل المديرين') }}</option>
                @foreach ($staff as $s)<option value="{{ $s->id }}" @selected((int) request('manager') === (int) $s->id)>{{ $s->name }}</option>@endforeach
            </select>
            <select class="ed-select" name="member" aria-label="{{ __('عضو الفريق') }}">
                <option value="">{{ __('كل أعضاء الفرق') }}</option>
                @foreach ($staff as $s)<option value="{{ $s->id }}" @selected((int) request('member') === (int) $s->id)>{{ $s->name }}</option>@endforeach
            </select>
            @if (request('delayed'))<input type="hidden" name="delayed" value="1">@endif
            @if (request()->hasAny(['q', 'status', 'customer', 'manager', 'member', 'delayed']))
                <a class="ed-btn ed-btn--ghost ed-btn--sm" href="{{ route('projects.index', ['view' => $view]) }}">{{ __('مسح الفلاتر') }}</a>
            @endif
        </form>

        @if ($projects->isEmpty())
            <x-ed.empty icon="ti-briefcase" :title="__('لا توجد مشاريع تطابق هذا البحث.')">
                @if ($canCreate)<a class="ed-btn ed-btn--primary" href="{{ route('projects.create') }}">{{ __('مشروع جديد') }}</a>@endif
            </x-ed.empty>

        @elseif ($view === 'cards')
            <div class="ed-panel__body">
                <div class="ed-pcards">
                    @foreach ($projects as $project)
                        @php
                            $s = $stats[$project->id] ?? [];
                            $late = ProjectStats::isDelayed($project);
                        @endphp
                        <article class="ed-pcard {{ $late ? 'is-delayed' : '' }}">
                            <div class="ed-pcard__top">
                                <div style="min-width:0">
                                    <a class="ed-pcard__name" href="{{ route('projects.show', $project->id) }}">{{ $project->project_name }}</a>
                                    <span class="ed-pcard__client">{{ $project->customer->name ?? __('بدون عميل') }}</span>
                                </div>
                                <x-ed.badge :pair="Labels::project($project->status)" />
                            </div>
                            <x-ed.progress :value="ProjectStats::progress($project, $s)" :late="$late" />
                            <div class="ed-pcard__foot">
                                <span>
                                    @if ($project->manager)<x-ed.avatar :name="$project->manager->name" size="sm" /> {{ $project->manager->name }}@else {{ __('بدون مدير') }} @endif
                                </span>
                                <span class="ed-due {{ $late ? 'is-late' : '' }}">
                                    <i class="ti ti-calendar"></i>
                                    @if ($late) {{ __('متأخر') }} {{ ProjectStats::daysLate($project) }} {{ __('يوم') }} @else {{ Labels::date($project->end_date, 'j M Y') }} @endif
                                </span>
                            </div>
                            <div class="ed-pcard__foot">
                                <span class="ed-num">{{ $s['done'] ?? 0 }}/{{ $s['total'] ?? 0 }} {{ __('مهمة') }}</span>
                                @if (($s['overdue'] ?? 0) > 0)<span class="tone-text-bad ed-num">{{ $s['overdue'] }} {{ __('متأخرة') }}</span>@endif
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
            <x-ed.pager :items="$paginator" />

        @elseif ($view === 'table')
            <div class="ed-table-wrap">
                <table class="ed-table">
                    <thead><tr><th>{{ __('المشروع') }}</th><th>{{ __('المدير') }}</th><th>{{ __('المهام') }}</th><th>{{ __('التسليم') }}</th><th>{{ __('الإنجاز') }}</th><th>{{ __('الحالة') }}</th></tr></thead>
                    <tbody>
                        @foreach ($projects as $project)
                            @php
                                $s = $stats[$project->id] ?? [];
                                $late = ProjectStats::isDelayed($project);
                            @endphp
                            <tr>
                                <td>
                                    <a class="ed-table__title" href="{{ route('projects.show', $project->id) }}">{{ $project->project_name }}</a>
                                    <span class="ed-table__sub">{{ $project->customer->name ?? __('بدون عميل') }}</span>
                                </td>
                                <td>{{ $project->manager->name ?? '—' }}</td>
                                <td class="ed-num">{{ $s['done'] ?? 0 }}/{{ $s['total'] ?? 0 }}</td>
                                <td class="is-tight">
                                    <span class="ed-due {{ $late ? 'is-late' : '' }}">{{ Labels::date($project->end_date) }}</span>
                                    @if ($late)<span class="ed-table__sub tone-text-bad">{{ __('متأخر') }} {{ ProjectStats::daysLate($project) }} {{ __('يوم') }}</span>@endif
                                </td>
                                <td style="min-width:150px"><x-ed.progress :value="ProjectStats::progress($project, $s)" :late="$late" /></td>
                                <td><x-ed.badge :pair="Labels::project($project->status)" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-ed.pager :items="$paginator" />

        @elseif ($view === 'board')
            <div class="ed-panel__body">
                <div class="ed-board">
                    @foreach (Labels::PROJECT_OPEN as $status)
                        @php $inColumn = $projects->where('status', $status); @endphp
                        <div class="ed-col" @if ($canEdit) data-col="{{ $status }}" data-col-field="status" @endif>
                            <div class="ed-col__head"><span>{{ __(Labels::project($status)[0]) }}</span><small class="ed-num" data-col-count>{{ $inColumn->count() }}</small></div>
                            <div class="ed-col__list" data-col-list>
                                @foreach ($inColumn as $project)
                                    @php
                                        $s = $stats[$project->id] ?? [];
                                        $late = ProjectStats::isDelayed($project);
                                    @endphp
                                    <div class="ed-card" @if ($canEdit) draggable="true" data-id="{{ $project->id }}" data-move-url="{{ route('edata.projects.status', $project->id) }}" @endif>
                                        <a class="ed-card__title" href="{{ route('projects.show', $project->id) }}">{{ $project->project_name }}</a>
                                        <span class="ed-card__sub">{{ $project->customer->name ?? __('بدون عميل') }}</span>
                                        <div style="margin-top:8px"><x-ed.progress :value="ProjectStats::progress($project, $s)" :late="$late" /></div>
                                        <div class="ed-card__meta">
                                            @if ($project->manager)<x-ed.avatar :name="$project->manager->name" size="sm" />@else<span></span>@endif
                                            <span class="ed-due {{ $late ? 'is-late' : '' }}"><i class="ti ti-calendar"></i>{{ Labels::date($project->end_date, 'j M') }}</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

        @else
            @php
                $dated = $projects->filter(fn ($p) => $p->start_date && $p->end_date);
                $undated = $projects->count() - $dated->count();
                $min = $dated->isNotEmpty() ? \Carbon\Carbon::parse($dated->min('start_date'))->startOfMonth() : null;
                $max = $dated->isNotEmpty() ? \Carbon\Carbon::parse($dated->max('end_date'))->endOfMonth() : null;
                if ($max && $max->lt(now())) { $max = now()->endOfMonth(); }
                $span = $min ? max(1, $min->diffInDays($max, true)) : 1;
                $months = [];
                if ($min) { for ($c = $min->copy(); $c->lte($max); $c->addMonth()) { $months[] = $c->copy(); } }
                $todayPos = $min ? min(100, max(0, $min->diffInDays(now(), false) / $span * 100)) : 0;
            @endphp
            @if ($dated->isEmpty())
                <x-ed.empty icon="ti-calendar-stats" :title="__('لا توجد مشاريع بتواريخ بداية وتسليم.')" />
            @else
                <div class="ed-table-wrap">
                    <div class="ed-gantt">
                        <div class="ed-gantt__scale">
                            <span></span>
                            <div class="ed-gantt__months">
                                @foreach ($months as $m)<span>{{ $m->copy()->locale(app()->getLocale())->translatedFormat('M Y') }}</span>@endforeach
                            </div>
                        </div>
                        @foreach ($dated as $project)
                            @php
                                $start = \Carbon\Carbon::parse($project->start_date);
                                $end = \Carbon\Carbon::parse($project->end_date);
                                $offset = max(0, $min->diffInDays($start, false) / $span * 100);
                                $width = max(0.8, $start->diffInDays($end, true) / $span * 100);
                                $progress = ProjectStats::progress($project, $stats[$project->id] ?? []);
                                $late = ProjectStats::isDelayed($project);
                            @endphp
                            <div class="ed-gantt__row">
                                <a class="ed-gantt__name" href="{{ route('projects.show', $project->id) }}" title="{{ $project->project_name }}">{{ $project->project_name }}</a>
                                <div class="ed-gantt__track">
                                    <div class="ed-gantt__today" style="inset-inline-start:{{ $todayPos }}%"></div>
                                    <div class="ed-gantt__bar {{ $late ? 'is-late' : '' }}" style="inset-inline-start:{{ $offset }}%;width:{{ $width }}%" title="{{ Labels::date($start) }} - {{ Labels::date($end) }}, {{ $progress }}%"><i style="width:{{ $progress }}%"></i></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
                @if ($undated > 0)<p class="ed-hint" style="padding:0 18px 14px">{{ $undated }} {{ __('مشروع بلا تواريخ لا يظهر هنا.') }}</p>@endif
            @endif
        @endif
    </section>
</div>
@endsection
