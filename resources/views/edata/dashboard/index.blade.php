@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', __('لوحة التحكم'))

@php
    use App\Support\Edata\Fmt;
    use App\Support\Edata\Labels;
    use App\Support\Edata\ProjectStats;
    use App\Support\Edata\TaskRules;

    $hour = (int) $now->format('G');
    $greeting = $hour < 12 ? __('صباح الخير') : __('مساء الخير');
    $hasFilter = request()->hasAny(['project', 'employee', 'range', 'from', 'to']);

    // Chart payloads. Every value below comes from the controller's live queries.
    $statusRows = collect(Labels::PROJECT_STATUSES)
        ->map(fn ($pair, $key) => ['label' => __($pair[0]), 'value' => (int) ($statusCounts[$key] ?? 0)])
        ->filter(fn ($row) => $row['value'] > 0)
        ->sortByDesc('value')
        ->values();

    $taskLabels = array_keys($taskTrend);
    $taskCreated = array_column($taskTrend, 'created');
    $taskCompleted = array_column($taskTrend, 'completed');

    $moneyLabels = $money ? array_keys($money['trend']) : [];
    $moneyBilled = $money ? array_map(fn ($m) => round($m['billed'], 2), array_values($money['trend'])) : [];
    $moneyCollected = $money ? array_map(fn ($m) => round($m['collected'], 2), array_values($money['trend'])) : [];

    $attendanceTrend = $team ? $team['trend'] : [];
@endphp

@section('content')
<div class="ed-today">
    <div>
        <h1>{{ $greeting }}{{ __('،') }} {{ $greetingName }}</h1>
        <p class="ed-today__line">
            @if ($team)
                <b class="ed-num">{{ count($team['working']) }}</b> {{ __('من') }} <b class="ed-num">{{ $team['total'] }}</b> {{ __('موظفين يعملون الآن') }}{{ __('،') }}
            @endif
            <b class="ed-num">{{ $projectCounts['active'] }}</b> {{ __('مشروع قيد التنفيذ') }}{{ __('،') }}
            <b class="ed-num {{ $taskCounts['overdue'] ? 'tone-text-bad' : '' }}">{{ $taskCounts['overdue'] }}</b> {{ __('مهمة متأخرة') }}@if ($money){{ __('،') }}
                <b class="ed-num">{{ Fmt::money($money['summary']['due'], $company) }}</b> {{ __('مستحقة التحصيل') }}@endif.
        </p>
    </div>
    <form class="ed-filters" method="GET" style="padding:0;border:0;flex:1 1 480px;justify-content:flex-end" data-autosubmit aria-label="{{ __('فلترة لوحة التحكم') }}">
        <select class="ed-select" name="range" aria-label="{{ __('الفترة') }}" style="flex:0 1 150px">
            <option value="7" @selected($range === '7')>{{ __('آخر 7 أيام') }}</option>
            <option value="30" @selected($range === '30')>{{ __('آخر 30 يوماً') }}</option>
            <option value="month" @selected($range === 'month')>{{ __('هذا الشهر') }}</option>
            <option value="year" @selected($range === 'year')>{{ __('هذا العام') }}</option>
            <option value="custom" @selected($range === 'custom')>{{ __('فترة محددة') }}</option>
        </select>
        @if ($range === 'custom')
            <input class="ed-input" type="date" name="from" value="{{ $from }}" aria-label="{{ __('من تاريخ') }}" style="flex:0 1 150px">
            <input class="ed-input" type="date" name="to" value="{{ $to }}" aria-label="{{ __('إلى تاريخ') }}" style="flex:0 1 150px">
        @endif
        <select class="ed-select" name="employee" aria-label="{{ __('الموظف') }}" style="flex:0 1 170px">
            <option value="">{{ __('كل الموظفين') }}</option>
            @foreach ($allStaff as $person)<option value="{{ $person->id }}" @selected($employeeFilter === (int) $person->id)>{{ $person->name }}</option>@endforeach
        </select>
        <select class="ed-select" name="project" aria-label="{{ __('المشروع') }}" style="flex:0 1 190px">
            <option value="">{{ __('كل المشاريع') }}</option>
            @foreach ($allProjects as $p)<option value="{{ $p->id }}" @selected($projectFilter === (int) $p->id)>{{ $p->project_name }}</option>@endforeach
        </select>
        @if ($hasFilter)
            <a class="ed-btn ed-btn--ghost ed-btn--sm" href="{{ route('dashboard') }}">{{ __('مسح الفلاتر') }}</a>
        @endif
    </form>
</div>

<div class="ed-stack ed-reveal">
    <div class="ed-band">
        @if ($team)
            <section class="ed-group" style="grid-column: span 6">
                <div class="ed-group__head"><h2><i class="ti ti-users"></i>{{ __('الفريق اليوم') }}</h2><a class="ed-link" style="font-size:12.5px" href="{{ route('edata.attendance.index') }}">{{ __('السجل') }}</a></div>
                <div class="ed-group__nums">
                    <x-ed.figure :label="__('الموظفون')" :count="$team['total']" :href="route('edata.people.index')" />
                    <x-ed.figure :label="__('يعملون الآن')" :count="count($team['working'])" :href="route('edata.attendance.index', ['status' => 'working', 'from' => $now->toDateString(), 'to' => $now->toDateString()])" />
                    <x-ed.figure :label="__('متأخرون')" :count="count($team['late'])" :tone="count($team['late']) ? 'warn' : null" :href="route('edata.attendance.index', ['status' => 'late', 'from' => $now->toDateString(), 'to' => $now->toDateString()])" />
                    <x-ed.figure :label="__('غائبون')" :count="count($team['absent'])" :tone="count($team['absent']) ? 'bad' : null" :href="route('edata.attendance.index', ['status' => 'absent', 'from' => $now->toDateString(), 'to' => $now->toDateString()])" />
                </div>
            </section>
        @endif

        <section class="ed-group" style="grid-column: span {{ $team ? 6 : 12 }}">
            <div class="ed-group__head"><h2><i class="ti ti-briefcase"></i>{{ __('المشاريع') }}</h2><a class="ed-link" style="font-size:12.5px" href="{{ route('projects.index') }}">{{ __('كل المشاريع') }}</a></div>
            <div class="ed-group__nums">
                <x-ed.figure :label="__('الإجمالي')" :count="$projectCounts['total']" :href="route('projects.index', ['view' => 'table'])" />
                <x-ed.figure :label="__('قيد التنفيذ')" :count="$projectCounts['active']" :href="route('projects.index', ['status' => 'in_progress'])" />
                <x-ed.figure :label="__('متأخرة')" :count="$projectCounts['delayed']" :tone="$projectCounts['delayed'] ? 'bad' : null" :href="route('projects.index', ['delayed' => 1, 'view' => 'table'])" />
                <x-ed.figure :label="__('مكتملة')" :count="$projectCounts['complete']" tone="good" :href="route('projects.index', ['status' => 'complete', 'view' => 'table'])" />
            </div>
        </section>

        <section class="ed-group" style="grid-column: span {{ $money ? 4 : 12 }}">
            <div class="ed-group__head"><h2><i class="ti ti-checkbox"></i>{{ __('المهام') }}</h2><a class="ed-link" style="font-size:12.5px" href="{{ route('edata.tasks.index') }}">{{ __('كل المهام') }}</a></div>
            <div class="ed-group__nums">
                <x-ed.figure :label="__('مفتوحة')" :count="$taskCounts['open']" :href="route('edata.tasks.index')" />
                <x-ed.figure :label="__('متأخرة')" :count="$taskCounts['overdue']" :tone="$taskCounts['overdue'] ? 'bad' : null" :href="route('edata.tasks.index', ['state' => 'overdue'])" />
                <x-ed.figure :label="__('أُنجزت في الفترة')" :count="$taskCounts['done_period']" tone="good" :href="route('edata.tasks.index', ['state' => 'done'])" />
            </div>
        </section>

        @if ($money)
            <section class="ed-group" style="grid-column: span 8">
                <div class="ed-group__head"><h2><i class="ti ti-file-invoice"></i>{{ __('الفواتير والتحصيل') }}</h2><a class="ed-link" style="font-size:12.5px" href="{{ route('edata.invoices.index') }}">{{ __('كل الفواتير') }}</a></div>
                <div class="ed-group__nums">
                    <x-ed.figure :label="__('قيمة الفواتير')" :money="$money['summary']['billed']" :company="$company" :hint="$money['summary']['count'] . ' ' . __('فاتورة')" />
                    <x-ed.figure :label="__('تم تحصيله')" :money="$money['summary']['collected']" :company="$company" tone="good" :hint="($money['summary']['states']['paid'] ?? 0) . ' ' . __('مدفوعة بالكامل')" />
                    <x-ed.figure :label="__('مستحق')" :money="$money['summary']['due']" :company="$company" :tone="$money['summary']['due'] > 0 ? 'bad' : null" :hint="(($money['summary']['states']['sent'] ?? 0) + ($money['summary']['states']['partial'] ?? 0) + ($money['summary']['states']['overdue'] ?? 0)) . ' ' . __('غير مدفوعة')" :href="route('edata.invoices.index', ['state' => 'overdue'])" />
                    <x-ed.figure :label="__('إيراد هذا الشهر')" :money="$money['revenue_month']" :company="$company" :hint="__('هذا العام') . ': ' . Fmt::money($money['revenue_year'], $company)" />
                </div>
            </section>
        @endif

        @if ($clientCount !== null)
            <section class="ed-group" style="grid-column: span 12;display:flex;align-items:center;justify-content:space-between;padding:10px 18px;gap:12px;flex-wrap:wrap">
                <span style="font-weight:600;color:var(--text-2)"><i class="ti ti-building-store" style="color:var(--teal)"></i> {{ __('العملاء') }}: <b class="ed-num" style="color:var(--text)">{{ number_format($clientCount) }}</b></span>
                @if ($team && $team['rate'] !== null)
                    <span style="font-weight:600;color:var(--text-2)"><i class="ti ti-calendar-stats" style="color:var(--teal)"></i> {{ __('الالتزام بالحضور في الفترة') }}: <b class="ed-num" style="color:var(--text)">{{ $team['rate'] }}%</b></span>
                @endif
                <a class="ed-link" href="{{ route('edata.clients.index') }}">{{ __('كل العملاء') }}</a>
            </section>
        @endif
    </div>

    @if (count($alerts))
        <section class="ed-panel" aria-label="{{ __('أهم التنبيهات') }}">
            <div class="ed-panel__head"><h2>{{ __('أهم التنبيهات') }}</h2></div>
            <div class="ed-alerts">
                @foreach ($alerts as $alert)
                    <a class="ed-alert tone-{{ $alert['tone'] }}" href="{{ $alert['url'] }}">
                        <i class="ti {{ $alert['tone'] === 'bad' ? 'ti-alert-circle' : 'ti-alert-triangle' }}"></i>
                        <span>{{ $alert['text'] }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @php
        $sumCreated = array_sum($taskCreated);
        $sumCompleted = array_sum($taskCompleted);
        $pct = fn ($part, $whole) => $whole > 0 ? (int) round($part / $whole * 100) : 0;
    @endphp
    <div class="ed-grid ed-grid--2">
        <section class="ed-panel">
            <div class="ed-panel__head"><div><h2>{{ __('المهام: الجديدة والمنجزة') }}</h2><p>{{ Labels::date($from) }} - {{ Labels::date($to) }}</p></div></div>
            <div class="ed-chartsum">
                <div><span><i style="background:#0086AD"></i>{{ __('مهام جديدة') }}</span><b class="ed-num">{{ number_format($sumCreated) }}</b></div>
                <div><span><i style="background:#6FAE00"></i>{{ __('مهام أُنجزت') }}</span><b class="ed-num">{{ number_format($sumCompleted) }}</b></div>
                <div><span>{{ __('نسبة الإنجاز إلى الجديد') }}</span><b class="ed-num">{{ $pct($sumCompleted, $sumCreated) }}%</b></div>
            </div>
            <div class="ed-panel__body" style="padding-top:0"><div id="chartTasks" class="ed-chart is-loading"></div></div>
        </section>

        <section class="ed-panel">
            <div class="ed-panel__head"><div><h2>{{ __('توزيع المشاريع حسب الحالة') }}</h2><p>{{ number_format($projectCounts['total']) }} {{ __('مشروع') }}</p></div></div>
            <div class="ed-panel__body" style="padding-top:6px">
                @if ($statusRows->isEmpty())
                    <x-ed.empty small icon="ti-chart-bar" :title="__('لا توجد مشاريع لعرضها.')" />
                @else
                    @php
                        $statusMax = max(1, $statusRows->max('value'));
                        $statusSum = max(1, $statusRows->sum('value'));
                    @endphp
                    <div class="ed-hbars">
                        @foreach ($statusRows as $row)
                            <div class="ed-hbar" title="{{ $row['label'] }}: {{ $row['value'] }}">
                                <span class="ed-hbar__label">{{ $row['label'] }}</span>
                                <div class="ed-hbar__track"><div class="ed-hbar__seg ed-hbar__seg--a" style="--w:{{ round($row['value'] / $statusMax * 100, 1) }}%"></div></div>
                                <span class="ed-hbar__value">{{ number_format($row['value']) }} <small>{{ $pct($row['value'], $statusSum) }}%</small></span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        @if ($money)
            <section class="ed-panel">
                <div class="ed-panel__head"><div><h2>{{ __('الفواتير مقابل التحصيل') }}</h2><p>{{ __('آخر 6 أشهر') }}</p></div></div>
                @php
                    $sumBilled = array_sum($moneyBilled);
                    $sumCollected = array_sum($moneyCollected);
                @endphp
                <div class="ed-chartsum">
                    <div><span><i style="background:#0086AD"></i>{{ __('قيمة الفواتير') }}</span><b class="ed-num">{{ Fmt::money($sumBilled, $company) }}</b></div>
                    <div><span><i style="background:#6FAE00"></i>{{ __('المحصّل') }}</span><b class="ed-num">{{ Fmt::money($sumCollected, $company) }}</b></div>
                    <div><span>{{ __('نسبة التحصيل') }}</span><b class="ed-num">{{ $pct($sumCollected, $sumBilled) }}%</b></div>
                </div>
                <div class="ed-panel__body" style="padding-top:0"><div id="chartMoney" class="ed-chart is-loading"></div></div>
            </section>
        @endif

        <section class="ed-panel">
            <div class="ed-panel__head"><div><h2>{{ __('أداء الموظفين في المهام') }}</h2><p>{{ __('المنجز في الفترة والمفتوح حالياً') }}</p></div></div>
            <div class="ed-panel__body" style="padding-top:6px">
                @if (empty($employeeTasks))
                    <x-ed.empty small icon="ti-chart-bar" :title="__('لا توجد مهام مسندة في هذه الفترة.')" />
                @else
                    @php $taskMax = max(1, collect($employeeTasks)->map(fn ($e) => $e['completed'] + $e['open'])->max()); @endphp
                    <div class="ed-legend">
                        <span><i style="background:#6FAE00"></i>{{ __('أنجزها في الفترة') }}</span>
                        <span><i style="background:#0086AD"></i>{{ __('مفتوحة حالياً') }}</span>
                    </div>
                    <div class="ed-hbars">
                        @foreach ($employeeTasks as $row)
                            <a class="ed-hbar" href="{{ route('edata.people.show', $row['id']) }}" title="{{ $row['name'] }}: {{ $row['completed'] }} {{ __('منجزة') }}، {{ $row['open'] }} {{ __('مفتوحة') }}">
                                <span class="ed-hbar__label">{{ $row['name'] }}</span>
                                <div class="ed-hbar__track">
                                    <div class="ed-hbar__seg ed-hbar__seg--b" style="--w:{{ round($row['completed'] / $taskMax * 100, 1) }}%"></div>
                                    <div class="ed-hbar__seg ed-hbar__seg--a" style="--w:{{ round($row['open'] / $taskMax * 100, 1) }}%"></div>
                                </div>
                                <span class="ed-hbar__value">{{ $row['completed'] }}/{{ $row['open'] }} <small>{{ $pct($row['completed'], $row['completed'] + $row['open']) }}%</small></span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        @if ($team)
            <section class="ed-panel">
                <div class="ed-panel__head"><div><h2>{{ __('الالتزام بالحضور') }}</h2><p>{{ __('نسبة من حضر من المتوقع حضورهم في كل يوم عمل') }}</p></div></div>
                <div class="ed-panel__body" style="padding-top:6px">
                    @if (empty($attendanceTrend))
                        <x-ed.empty small icon="ti-chart-line" :title="__('لا توجد أيام عمل في هذه الفترة.')" />
                    @else
                        <div class="ed-chartsum is-inline">
                            <div><span>{{ __('متوسط الالتزام') }}</span><b class="ed-num">{{ (int) round(array_sum($attendanceTrend) / count($attendanceTrend)) }}%</b></div>
                            <div><span>{{ __('أعلى يوم') }}</span><b class="ed-num">{{ max($attendanceTrend) }}%</b></div>
                            <div><span>{{ __('أقل يوم') }}</span><b class="ed-num">{{ min($attendanceTrend) }}%</b></div>
                        </div>
                        <div id="chartAttendance" class="ed-chart is-loading"></div>
                    @endif
                </div>
            </section>

            <section class="ed-panel">
                <div class="ed-panel__head"><h2>{{ __('ساعات عمل الفريق') }}</h2><a class="ed-link" style="font-size:12.5px" href="{{ route('edata.attendance.index') }}">{{ __('التفاصيل') }}</a></div>
                @if ($team['perEmployee']->isEmpty())
                    <x-ed.empty small icon="ti-clock" :title="__('لا توجد سجلات في هذه الفترة.')" />
                @else
                    <div class="ed-table-wrap">
                        <table class="ed-table">
                            <thead><tr><th>{{ __('الموظف') }}</th><th>{{ __('الساعات') }}</th><th>{{ __('تأخير') }}</th><th>{{ __('غياب') }}</th><th>{{ __('الالتزام') }}</th></tr></thead>
                            <tbody>
                                @foreach ($team['perEmployee'] as $row)
                                    <tr>
                                        <td><a class="ed-person__name" href="{{ route('edata.people.show', $row['user_id']) }}">{{ $row['name'] }}</a></td>
                                        <td class="ed-num is-tight">{{ Labels::hours($row['totals']['seconds']) }}</td>
                                        <td class="ed-num {{ $row['totals']['late'] ? 'tone-text-warn' : '' }}">{{ $row['totals']['late'] }}</td>
                                        <td class="ed-num {{ $row['totals']['absent'] ? 'tone-text-bad' : '' }}">{{ $row['totals']['absent'] }}</td>
                                        <td style="min-width:120px"><x-ed.progress :value="$row['totals']['rate'] ?? 0" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif
    </div>

    <div class="ed-grid ed-grid--2">
        <section class="ed-panel">
            <div class="ed-panel__head"><h2>{{ __('مشاريع تقترب مواعيدها') }}</h2><a class="ed-link" style="font-size:12.5px" href="{{ route('projects.index') }}">{{ __('كل المشاريع') }}</a></div>
            @if ($latestProjects->isEmpty())
                <x-ed.empty small icon="ti-briefcase" :title="__('لا توجد مشاريع مفتوحة.')" />
            @else
                <div class="ed-list">
                    @foreach ($latestProjects as $project)
                        @php $late = ProjectStats::isDelayed($project); @endphp
                        <a href="{{ route('projects.show', $project->id) }}">
                            <div class="ed-list__main">
                                <span class="ed-list__title">{{ $project->project_name }}</span>
                                <span class="ed-list__sub">{{ $project->customer->name ?? __('بدون عميل') }}</span>
                            </div>
                            <x-ed.team :users="$project->users" :exclude="$company" :max="3" style="flex:none" />
                            <div style="width:110px;flex:none"><x-ed.progress :value="ProjectStats::progress($project, $latestStats[$project->id] ?? [])" :late="$late" /></div>
                            <span class="ed-due {{ $late ? 'is-late' : '' }}" style="flex:none">{{ Labels::date($project->end_date, 'j M') }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="ed-panel">
            <div class="ed-panel__head"><h2>{{ __('مهام تحتاج تدخلاً') }}</h2><a class="ed-link" style="font-size:12.5px" href="{{ route('edata.tasks.index', ['state' => 'attention']) }}">{{ __('عرض الكل') }}</a></div>
            @if ($attention->isEmpty())
                <x-ed.empty small icon="ti-circle-check" :title="__('لا توجد مهام تحتاج تدخلاً الآن.')" />
            @else
                <div class="ed-list">
                    @foreach ($attention as $row)
                        <a href="{{ route('edata.tasks.show', $row['task']->id) }}">
                            <div class="ed-list__main">
                                <span class="ed-list__title">{{ $row['task']->name }}</span>
                                <span class="ed-list__sub">{{ $row['task']->project->project_name ?? '' }}</span>
                            </div>
                            <span class="ed-badge tone-warn" style="flex:none">{{ __($row['reason'] ?? '') }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>

        @if ($money)
            <section class="ed-panel">
                <div class="ed-panel__head"><h2>{{ __('الفواتير المستحقة') }}</h2><a class="ed-link" style="font-size:12.5px" href="{{ route('edata.invoices.index') }}">{{ __('كل الفواتير') }}</a></div>
                @if ($money['due_list']->isEmpty())
                    <x-ed.empty small icon="ti-circle-check" :title="__('لا توجد مبالغ مستحقة.')" />
                @else
                    <div class="ed-list">
                        @foreach ($money['due_list'] as $invoice)
                            <a href="{{ route('edata.invoices.show', $invoice->id) }}">
                                <div class="ed-list__main">
                                    <span class="ed-list__title">{{ $invoice->customer->name ?? '—' }}</span>
                                    <span class="ed-list__sub ed-num"><bdi>{{ Fmt::invoiceNumber($invoice->invoice_id, $company) }}</bdi>، {{ Labels::date($invoice->due_date, 'j M') }}</span>
                                </div>
                                <span class="ed-num" style="font-weight:700;flex:none">{{ Fmt::money($invoice->calc['due'], $company) }}</span>
                                <x-ed.badge :pair="Labels::invoice($invoice->calc['state'])" style="flex:none" />
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>
        @endif

        @if ($activity->isNotEmpty())
            <section class="ed-panel">
                <div class="ed-panel__head"><h2>{{ __('آخر الأنشطة') }}</h2><a class="ed-link" style="font-size:12.5px" href="{{ route('edata.audit.index') }}">{{ __('السجل الكامل') }}</a></div>
                <x-ed.trail :logs="$activity" :with-diff="false" />
            </section>
        @endif
    </div>
</div>
@endsection

@push('edata-vendor')
    <script src="{{ asset('assets/js/plugins/apexcharts.min.js') }}"></script>
@endpush

@push('edata-scripts')
<script>
(function () {
    var teal = '#0086AD', leaf = '#6FAE00';
    var locale = @json(app()->getLocale());

    function shortDate(key) {
        var parts = key.split('-');
        var date = new Date(parts[0], parts[1] - 1, parts[2] || 1);
        var opts = parts[2] ? { day: 'numeric', month: 'short' } : { month: 'short' };
        return new Intl.DateTimeFormat(locale === 'en' ? 'en-GB' : 'ar-EG', Object.assign({ numberingSystem: 'latn' }, opts)).format(date);
    }

    function compact(v) {
        if (!v) { return ''; }
        return v >= 1000 ? (v / 1000).toFixed(v >= 10000 ? 0 : 1).replace(/\.0$/, '') + 'K' : String(Math.round(v));
    }
    // Values sit on top of each bar, so they read as numbers, not as a shape to guess.
    var labelsOnTop = { enabled: true, offsetY: -18, style: { fontSize: '11px', fontWeight: 700, colors: ['#46596B'] }, background: { enabled: false } };

    // A month of days is too many bars to label, so long ranges are shown week by week.
    (function () {
        var keys = @json($taskLabels), created = @json($taskCreated), done = @json($taskCompleted);
        var labels = keys.map(shortDate), a = created, b = done;
        if (keys.length > 14) {
            labels = []; a = []; b = [];
            for (var i = 0; i < keys.length; i += 7) {
                var end = Math.min(i + 6, keys.length - 1);
                labels.push(shortDate(keys[i]));
                a.push(created.slice(i, end + 1).reduce(function (s, v) { return s + v; }, 0));
                b.push(done.slice(i, end + 1).reduce(function (s, v) { return s + v; }, 0));
            }
        }
        edChart('#chartTasks', {
            chart: { type: 'bar', height: 280 },
            series: [
                { name: @json(__('مهام جديدة')), data: a },
                { name: @json(__('مهام أُنجزت')), data: b }
            ],
            colors: [teal, leaf],
            plotOptions: { bar: { columnWidth: '58%', borderRadius: 4, borderRadiusApplication: 'end', dataLabels: { position: 'top' } } },
            stroke: { show: true, width: 2, colors: ['transparent'] },
            // Tasks finished in a week are often older tasks, so a per-bar percentage would mislead;
            // the overall ratio sits in the summary above the chart.
            dataLabels: Object.assign({}, labelsOnTop, { formatter: function (v) { return v ? v : ''; } }),
            legend: { show: false },
            xaxis: { categories: labels, labels: { rotate: 0, hideOverlappingLabels: true, trim: true } },
            yaxis: { min: 0, forceNiceScale: true, labels: { formatter: function (v) { return Math.round(v); } } },
            tooltip: { shared: true, intersect: false }
        });
    })();

    (function () {
        var billed = @json($moneyBilled), collected = @json($moneyCollected);
        edChart('#chartMoney', {
            chart: { type: 'bar', height: 280 },
            series: [
                { name: @json(__('قيمة الفواتير')), data: billed },
                { name: @json(__('المحصّل')), data: collected }
            ],
            colors: [teal, leaf],
            // Two bars of similar height would put their numbers on top of each other, so the
            // numbers run upright inside each bar. A monthly percentage is left out on purpose:
            // money collected this month often belongs to last month's invoices.
            plotOptions: { bar: { columnWidth: '62%', borderRadius: 4, borderRadiusApplication: 'end', dataLabels: { position: 'center', orientation: 'vertical' } } },
            stroke: { show: true, width: 2, colors: ['transparent'] },
            dataLabels: {
                enabled: true,
                style: { fontSize: '11px', fontWeight: 700, colors: ['#fff'] },
                formatter: function (v) { return v ? compact(v) : ''; }
            },
            legend: { show: false },
            grid: { padding: { left: 18, right: 8 } },
            xaxis: { categories: @json($moneyLabels).map(shortDate) },
            yaxis: { min: 0, labels: { formatter: function (v) { return compact(v) || '0'; } } },
            tooltip: { shared: true, intersect: false, y: { formatter: function (v) { return v.toLocaleString('en-US') + ' ' + @json(\App\Support\Edata\Fmt::settings($company)['site_currency_symbol'] ?? ''); } } }
        });
    })();

    (function () {
        var values = @json(array_values($attendanceTrend));
        var many = values.length > 12;
        edChart('#chartAttendance', {
            chart: { type: 'line', height: 280 },
            series: [{ name: @json(__('الالتزام')), data: values }],
            colors: [teal],
            stroke: { width: 2, curve: 'straight' },
            markers: { size: 4, strokeWidth: 0, hover: { size: 6 } },
            dataLabels: {
                enabled: true, offsetY: -8,
                style: { fontSize: '10.5px', fontWeight: 700, colors: ['#01485A'] },
                background: { enabled: true, foreColor: '#01485A', borderWidth: 0, borderRadius: 3, padding: 3, opacity: .9 },
                // With many days, every other point is labelled so the numbers stay legible.
                formatter: function (v, o) { return (!many || o.dataPointIndex % 2 === 0 || o.dataPointIndex === values.length - 1) ? v + '%' : ''; }
            },
            xaxis: { categories: @json(array_keys($attendanceTrend)).map(shortDate), tickAmount: 6, labels: { rotate: 0, hideOverlappingLabels: true }, tooltip: { enabled: false } },
            yaxis: { min: 0, max: 110, tickAmount: 4, labels: { formatter: function (v) { return v > 100 ? '' : Math.round(v) + '%'; } } },
            legend: { show: false }
        });
    })();
})();
</script>
@endpush
