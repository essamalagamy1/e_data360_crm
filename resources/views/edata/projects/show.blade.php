@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', $project->project_name)

@php
    use App\Support\Edata\Fmt;
    use App\Support\Edata\Labels;
    use App\Support\Edata\ProjectStats;
    use App\Support\Edata\TaskRules;

    $delayed = ProjectStats::isDelayed($project);
    $tabs = [
        'overview' => [__('نظرة عامة'), null],
        'tasks' => [__('المهام'), $tasks->count()],
        'team' => [__('الفريق'), $team->count()],
        'deadlines' => [__('المواعيد النهائية'), $deadlines->count()],
    ];
    if ($canSeeInvoices) { $tabs['invoices'] = [__('الفواتير'), $invoices->count()]; }
    $tabs['notes'] = [__('الملاحظات'), $notes->count()];
    $tabs['activity'] = [__('سجل الأنشطة'), null];
    $tab = array_key_exists($tab, $tabs) ? $tab : 'overview';
    $userNames = $team->keyBy('id');
    $available = $staff->reject(fn ($s) => $team->contains('id', $s->id));
@endphp

@section('content')
<x-ed.head :title="$project->project_name" :crumbs="[__('المشاريع') => route('projects.index')]">
    @if ($canAddTask)<a class="ed-btn" href="{{ route('edata.tasks.create', ['project' => $project->id]) }}"><i class="ti ti-plus"></i>{{ __('مهمة جديدة') }}</a>@endif
    @if ($canInvoice)<a class="ed-btn" href="{{ route('edata.invoices.create', ['project' => $project->id, 'customer' => $project->customer_id]) }}"><i class="ti ti-file-plus"></i>{{ __('فاتورة') }}</a>@endif
    @if ($canEdit)<a class="ed-btn ed-btn--primary" href="{{ route('projects.edit', $project->id) }}"><i class="ti ti-pencil"></i>{{ __('تعديل') }}</a>@endif
    @if ($canDelete && $tasks->isEmpty() && $invoices->isEmpty())
        <form method="POST" action="{{ route('projects.destroy', $project->id) }}" data-confirm="{{ __('حذف هذا المشروع؟') }}" data-confirm-text="{{ __('لا يوجد عليه مهام أو فواتير.') }}">
            @csrf @method('DELETE')
            <button class="ed-btn ed-btn--danger" type="submit" aria-label="{{ __('حذف') }}"><i class="ti ti-trash"></i></button>
        </form>
    @endif
</x-ed.head>

<div class="ed-stack ed-reveal">
    <div class="ed-figures">
        <div class="ed-figure">
            <span class="ed-figure__label">{{ __('الحالة') }}</span>
            <div style="margin-top:6px">
                @if ($canEdit)
                    <form method="POST" action="{{ route('edata.projects.status', $project->id) }}">
                        @csrf
                        <select class="ed-select" name="status" aria-label="{{ __('تغيير الحالة') }}" onchange="this.form.submit()" style="height:34px">
                            @foreach (Labels::PROJECT_STATUSES as $value => $pair)
                                <option value="{{ $value }}" @selected($project->status === $value)>{{ __($pair[0]) }}</option>
                            @endforeach
                        </select>
                    </form>
                @else
                    <x-ed.badge :pair="Labels::project($project->status)" />
                @endif
            </div>
        </div>
        <div class="ed-figure">
            <span class="ed-figure__label">{{ __('الإنجاز') }}</span>
            <div style="margin-top:12px"><x-ed.progress :value="$progress" :late="$delayed" /></div>
        </div>
        <x-ed.figure :label="__('المهام')" :value="$stats['done'] . '/' . $stats['total']" :hint="$stats['overdue'] ? $stats['overdue'] . ' ' . __('متأخرة') : null" />
        <x-ed.figure :label="__('التسليم')" :value="Labels::date($project->end_date, 'j M Y')" :tone="$delayed ? 'bad' : null" :hint="$delayed ? __('متأخر') . ' ' . ProjectStats::daysLate($project) . ' ' . __('يوم') : null" />
        <x-ed.figure :label="__('قيمة المشروع')" :money="(float) $project->budget" :company="$company" />
    </div>

    <section class="ed-panel">
        <nav class="ed-tabs" data-tabs aria-label="{{ __('أقسام المشروع') }}">
            @foreach ($tabs as $key => [$label, $count])
                <a href="?tab={{ $key }}" data-tab="{{ $key }}" class="{{ $tab === $key ? 'is-active' : '' }}">{{ $label }}@if ($count !== null)<small class="ed-num">{{ $count }}</small>@endif</a>
            @endforeach
        </nav>

        <div class="ed-tabpane {{ $tab === 'overview' ? 'is-active' : '' }}" id="tab-overview">
            <div class="ed-panel__body">
                <dl class="ed-facts">
                    <dt>{{ __('العميل') }}</dt>
                    <dd>
                        @if ($project->customer)
                            @if (\App\Support\Edata\Access::isOwner(auth()->user()) || auth()->user()->can('edit customer'))
                                <a class="ed-link" href="{{ route('edata.clients.show', $project->customer_id) }}">{{ $project->customer->name }}</a>
                            @else
                                {{ $project->customer->name }}
                            @endif
                        @else
                            {{ __('بدون عميل') }}
                            @if ($canEdit)<a class="ed-link" href="{{ route('projects.edit', $project->id) }}">{{ __('اربطه بعميل') }}</a>@endif
                        @endif
                    </dd>
                    <dt>{{ __('مدير المشروع') }}</dt><dd>{{ $project->manager->name ?? '—' }}</dd>
                    <dt>{{ __('تاريخ البداية') }}</dt><dd>{{ Labels::date($project->start_date) }}</dd>
                    <dt>{{ __('تاريخ التسليم') }}</dt><dd>{{ Labels::date($project->end_date) }}</dd>
                    @if ($canSeeInvoices)
                        <dt>{{ __('المفوتر') }}</dt><dd class="ed-num">{{ Fmt::money($invoiceMoney['total'], $company) }}</dd>
                        <dt>{{ __('المحصّل') }}</dt><dd class="ed-num">{{ Fmt::money($invoiceMoney['paid'], $company) }}</dd>
                    @endif
                    <dt>{{ __('الوصف') }}</dt><dd style="white-space:pre-wrap">{!! nl2br(e(strip_tags((string) $project->description))) ?: '—' !!}</dd>
                </dl>
            </div>
        </div>

        <div class="ed-tabpane {{ $tab === 'tasks' ? 'is-active' : '' }}" id="tab-tasks">
            @if ($tasks->isEmpty())
                <x-ed.empty icon="ti-checkbox" :title="__('لا توجد مهام في هذا المشروع.')">
                    @if ($canAddTask)<a class="ed-btn ed-btn--primary" href="{{ route('edata.tasks.create', ['project' => $project->id]) }}">{{ __('مهمة جديدة') }}</a>@endif
                </x-ed.empty>
            @else
                <div class="ed-table-wrap">
                    <table class="ed-table">
                        <thead><tr><th>{{ __('المهمة') }}</th><th>{{ __('المسؤول') }}</th><th>{{ __('الأولوية') }}</th><th>{{ __('التسليم') }}</th><th>{{ __('الحالة') }}</th></tr></thead>
                        <tbody>
                            @foreach ($tasks as $task)
                                @php $isLate = TaskRules::isOverdue($task, $company); @endphp
                                <tr>
                                    <td><a class="ed-table__title" href="{{ route('edata.tasks.show', $task->id) }}">{{ $task->name }}</a></td>
                                    <td>
                                        <span class="ed-avatars">
                                            @foreach (TaskRules::assigneeIds($task) as $userId)
                                                @if (isset($userNames[$userId]))<x-ed.avatar :user="$userNames[$userId]" size="sm" />@endif
                                            @endforeach
                                        </span>
                                    </td>
                                    <td><x-ed.badge :pair="Labels::priority($task->priority)" /></td>
                                    <td class="is-tight"><span class="ed-due {{ $isLate ? 'is-late' : '' }}">{{ Labels::date($task->end_date, 'j M Y') }}</span></td>
                                    <td><x-ed.badge :pair="TaskRules::state($task, $company)" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="ed-tabpane {{ $tab === 'team' ? 'is-active' : '' }}" id="tab-team">
            @if ($canEdit && $available->isNotEmpty())
                <form method="POST" action="{{ route('edata.projects.team.add', $project->id) }}" class="ed-filters">
                    @csrf
                    <select class="ed-select" name="user_id" required aria-label="{{ __('اختر موظفاً') }}" style="flex:1 1 240px">
                        <option value="">{{ __('اختر موظفاً لإضافته') }}</option>
                        @foreach ($available as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                    </select>
                    <button class="ed-btn ed-btn--primary" type="submit">{{ __('إضافة للفريق') }}</button>
                </form>
            @endif
            @if ($team->isEmpty())
                <x-ed.empty small icon="ti-users" :title="__('لا يوجد أعضاء في الفريق.')" />
            @else
                <div class="ed-list">
                    @foreach ($team as $member)
                        @php $load = $workload[$member->id] ?? ['total' => 0, 'done' => 0]; @endphp
                        <div>
                            <x-ed.avatar :user="$member" />
                            <div class="ed-list__main">
                                <a class="ed-list__title" href="{{ route('edata.people.show', $member->id) }}">{{ $member->name }}</a>
                                <span class="ed-list__sub">
                                    {{ $member->type === 'company' ? __('صاحب الحساب') : $member->type }}
                                    @if ((int) $project->manager_id === (int) $member->id) ، {{ __('مدير المشروع') }} @endif
                                </span>
                            </div>
                            <span class="ed-num ed-hint">{{ $load['done'] ?? 0 }}/{{ $load['total'] ?? 0 }} {{ __('مهمة') }}</span>
                            @if ($canEdit && $member->type !== 'company')
                                <form method="POST" action="{{ route('edata.projects.team.remove', [$project->id, $member->id]) }}" data-confirm="{{ __('إزالة') }} {{ $member->name }} {{ __('من الفريق؟') }}" data-confirm-text="{{ __('مهامه السابقة تبقى كما هي.') }}">
                                    @csrf @method('DELETE')
                                    <button class="ed-btn ed-btn--ghost ed-btn--sm" type="submit" aria-label="{{ __('إزالة') }}"><i class="ti ti-user-minus"></i></button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="ed-tabpane {{ $tab === 'deadlines' ? 'is-active' : '' }}" id="tab-deadlines">
            @if ($deadlines->isEmpty() && $milestones->isEmpty())
                <x-ed.empty small icon="ti-calendar" :title="__('لا توجد مواعيد قادمة.')" />
            @else
                <div class="ed-list">
                    @foreach ($milestones as $milestone)
                        <div>
                            <i class="ti ti-flag" style="font-size:20px;color:var(--teal)"></i>
                            <div class="ed-list__main">
                                <span class="ed-list__title">{{ $milestone->title }}</span>
                                <span class="ed-list__sub">{{ __('مرحلة رئيسية') }}</span>
                            </div>
                            <span class="ed-due">{{ Labels::date($milestone->due_date) }}</span>
                        </div>
                    @endforeach
                    @foreach ($deadlines as $task)
                        <a href="{{ route('edata.tasks.show', $task->id) }}">
                            <i class="ti ti-checkbox" style="font-size:20px;color:var(--text-3)"></i>
                            <div class="ed-list__main"><span class="ed-list__title">{{ $task->name }}</span></div>
                            <span class="ed-due {{ TaskRules::isOverdue($task, $company) ? 'is-late' : '' }}">{{ Labels::date($task->end_date) }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>

        @if ($canSeeInvoices)
            <div class="ed-tabpane {{ $tab === 'invoices' ? 'is-active' : '' }}" id="tab-invoices">
                @if ($invoices->isEmpty())
                    <x-ed.empty icon="ti-file-invoice" :title="__('لا توجد فواتير مرتبطة بهذا المشروع.')">
                        @if ($canInvoice)<a class="ed-btn ed-btn--primary" href="{{ route('edata.invoices.create', ['project' => $project->id, 'customer' => $project->customer_id]) }}">{{ __('فاتورة جديدة') }}</a>@endif
                    </x-ed.empty>
                @else
                    <div class="ed-table-wrap">
                        <table class="ed-table">
                            <thead><tr><th>{{ __('الفاتورة') }}</th><th>{{ __('العميل') }}</th><th>{{ __('الاستحقاق') }}</th><th>{{ __('الإجمالي') }}</th><th>{{ __('المتبقي') }}</th><th>{{ __('الحالة') }}</th></tr></thead>
                            <tbody>
                                @foreach ($invoices as $invoice)
                                    <tr>
                                        <td><a class="ed-table__title ed-num" href="{{ route('edata.invoices.show', $invoice->id) }}"><bdi>{{ Fmt::invoiceNumber($invoice->invoice_id, $company) }}</bdi></a></td>
                                        <td>{{ $invoice->customer->name ?? '—' }}</td>
                                        <td class="is-tight">{{ Labels::date($invoice->due_date) }}</td>
                                        <td class="ed-num">{{ Fmt::money($invoice->calc['total'], $company) }}</td>
                                        <td class="ed-num">{{ Fmt::money(max(0, $invoice->calc['due']), $company) }}</td>
                                        <td><x-ed.badge :pair="Labels::invoice($invoice->calc['state'])" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif

        <div class="ed-tabpane {{ $tab === 'notes' ? 'is-active' : '' }}" id="tab-notes">
            <x-ed.notes :notes="$notes" type="project" :id="$project->id" />
        </div>

        <div class="ed-tabpane {{ $tab === 'activity' ? 'is-active' : '' }}" id="tab-activity">
            <x-ed.trail :logs="$activity" />
        </div>
    </section>
</div>
@endsection
