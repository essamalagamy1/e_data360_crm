@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', $client->name)

@php
    use App\Support\Edata\Fmt;
    use App\Support\Edata\Labels;
    use App\Support\Edata\ProjectStats;
    use App\Support\Edata\TaskRules;
    $tabs = ['overview' => [__('نظرة عامة'), null], 'projects' => [__('المشاريع'), $projects->count()], 'invoices' => [__('الفواتير'), $invoices->count()], 'tasks' => [__('المهام'), $tasks->count()], 'notes' => [__('الملاحظات'), $notes->count()], 'activity' => [__('سجل الأنشطة'), null]];
    $tab = array_key_exists($tab, $tabs) ? $tab : 'overview';
@endphp

@section('content')
<x-ed.head :title="$client->name" :sub="$client->industry" :crumbs="[__('العملاء') => route('edata.clients.index')]">
    @if ($canInvoice)<a class="ed-btn" href="{{ route('edata.invoices.create', ['customer' => $client->id]) }}"><i class="ti ti-file-plus"></i>{{ __('فاتورة جديدة') }}</a>@endif
    @if ($canProject)<a class="ed-btn" href="{{ route('projects.create', ['customer' => $client->id]) }}"><i class="ti ti-briefcase"></i>{{ __('مشروع جديد') }}</a>@endif
    @if ($canEdit)
        <a class="ed-btn ed-btn--primary" href="{{ route('edata.clients.edit', $client->id) }}"><i class="ti ti-pencil"></i>{{ __('تعديل') }}</a>
        <form method="POST" action="{{ route('edata.clients.archive', $client->id) }}"
              data-confirm="{{ $client->archived_at ? __('استعادة هذا العميل من الأرشيف؟') : __('أرشفة هذا العميل؟') }}"
              data-confirm-text="{{ __('لا يُحذف شيء. مشاريعه وفواتيره تبقى كما هي.') }}" data-confirm-safe>
            @csrf
            <button class="ed-btn" type="submit"><i class="ti {{ $client->archived_at ? 'ti-archive' : 'ti-archive' }}"></i>{{ $client->archived_at ? __('استعادة') : __('أرشفة') }}</button>
        </form>
    @endif
</x-ed.head>

<div class="ed-stack ed-reveal">
    <div class="ed-figures">
        <x-ed.figure :label="__('إجمالي التعاملات')" :money="$money['billed']" :company="$company" />
        <x-ed.figure :label="__('المدفوع')" :money="$money['collected']" :company="$company" tone="good" />
        <x-ed.figure :label="__('المتبقي')" :money="$money['due']" :company="$company" :tone="$money['due'] > 0 ? 'bad' : null" />
        <x-ed.figure :label="__('المشاريع')" :count="$projects->count()" />
    </div>

    <section class="ed-panel">
        <nav class="ed-tabs" data-tabs aria-label="{{ __('أقسام العميل') }}">
            @foreach ($tabs as $key => [$label, $count])
                <a href="?tab={{ $key }}" data-tab="{{ $key }}" class="{{ $tab === $key ? 'is-active' : '' }}">{{ $label }}@if ($count !== null)<small class="ed-num">{{ $count }}</small>@endif</a>
            @endforeach
        </nav>

        <div class="ed-tabpane {{ $tab === 'overview' ? 'is-active' : '' }}" id="tab-overview">
            <div class="ed-panel__body">
                <dl class="ed-facts">
                    <dt>{{ __('الشخص المسؤول') }}</dt><dd>{{ $client->contact_person ?: '—' }}</dd>
                    <dt>{{ __('البريد الإلكتروني') }}</dt><dd><span class="ed-ltr">{{ $client->email ?: '—' }}</span></dd>
                    <dt>{{ __('الهاتف') }}</dt><dd><span class="ed-ltr">{{ $client->contact ?: '—' }}</span></dd>
                    <dt>{{ __('الدولة والمدينة') }}</dt><dd>{{ collect([$client->billing_country, $client->billing_city])->filter()->implode('، ') ?: '—' }}</dd>
                    <dt>{{ __('العنوان') }}</dt><dd>{{ $client->billing_address ?: '—' }}</dd>
                    <dt>{{ __('الرقم الضريبي') }}</dt><dd>{{ $client->tax_number ?: '—' }}</dd>
                    <dt>{{ __('نوع النشاط') }}</dt><dd>{{ $client->industry ?: '—' }}</dd>
                    <dt>{{ __('المصدر') }}</dt><dd>{{ $client->source ?: '—' }}</dd>
                    <dt>{{ __('تاريخ التسجيل') }}</dt><dd>{{ Labels::date($client->created_at) }}</dd>
                    <dt>{{ __('الحالة') }}</dt><dd>@if ($client->archived_at)<x-ed.badge tone="muted" :text="__('مؤرشف')" />@else<x-ed.badge :pair="Labels::client($client->crm_status)" />@endif</dd>
                    @if ($client->notes)<dt>{{ __('ملاحظات') }}</dt><dd style="white-space:pre-wrap">{{ $client->notes }}</dd>@endif
                </dl>
            </div>
        </div>

        <div class="ed-tabpane {{ $tab === 'projects' ? 'is-active' : '' }}" id="tab-projects">
            @if ($projects->isEmpty())
                <x-ed.empty icon="ti-briefcase" :title="__('لا توجد مشاريع مرتبطة بهذا العميل.')" :text="__('المشاريع القديمة لم تكن مرتبطة بالعملاء. اربطها من صفحة تعديل المشروع.')">
                    @if ($canProject)<a class="ed-btn ed-btn--primary" href="{{ route('projects.create', ['customer' => $client->id]) }}">{{ __('مشروع جديد') }}</a>@endif
                </x-ed.empty>
            @else
                <div class="ed-table-wrap">
                    <table class="ed-table">
                        <thead><tr><th>{{ __('المشروع') }}</th><th>{{ __('المدير') }}</th><th>{{ __('التسليم') }}</th><th>{{ __('الإنجاز') }}</th><th>{{ __('الحالة') }}</th></tr></thead>
                        <tbody>
                            @foreach ($projects as $project)
                                <tr>
                                    <td><a class="ed-table__title" href="{{ route('projects.show', $project->id) }}">{{ $project->project_name }}</a></td>
                                    <td>{{ $project->manager->name ?? '—' }}</td>
                                    <td class="is-tight">{{ Labels::date($project->end_date) }}</td>
                                    <td style="min-width:140px"><x-ed.progress :value="ProjectStats::progress($project, $projectStats[$project->id] ?? [])" :late="ProjectStats::isDelayed($project)" /></td>
                                    <td><x-ed.badge :pair="Labels::project($project->status)" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="ed-tabpane {{ $tab === 'invoices' ? 'is-active' : '' }}" id="tab-invoices">
            @if ($invoices->isEmpty())
                <x-ed.empty icon="ti-file-invoice" :title="__('لا توجد فواتير لهذا العميل.')">
                    @if ($canInvoice)<a class="ed-btn ed-btn--primary" href="{{ route('edata.invoices.create', ['customer' => $client->id]) }}">{{ __('فاتورة جديدة') }}</a>@endif
                </x-ed.empty>
            @else
                <div class="ed-table-wrap">
                    <table class="ed-table">
                        <thead><tr><th>{{ __('الفاتورة') }}</th><th>{{ __('الإصدار') }}</th><th>{{ __('الاستحقاق') }}</th><th>{{ __('الإجمالي') }}</th><th>{{ __('المدفوع') }}</th><th>{{ __('المتبقي') }}</th><th>{{ __('الحالة') }}</th></tr></thead>
                        <tbody>
                            @foreach ($invoices as $invoice)
                                <tr>
                                    <td><a class="ed-table__title ed-num" href="{{ route('edata.invoices.show', $invoice->id) }}"><bdi>{{ Fmt::invoiceNumber($invoice->invoice_id, $company) }}</bdi></a></td>
                                    <td class="is-tight">{{ Labels::date($invoice->issue_date) }}</td>
                                    <td class="is-tight">{{ Labels::date($invoice->due_date) }}</td>
                                    <td class="ed-num">{{ Fmt::money($invoice->calc['total'], $company) }}</td>
                                    <td class="ed-num">{{ Fmt::money($invoice->calc['paid'], $company) }}</td>
                                    <td class="ed-num">{{ Fmt::money(max(0, $invoice->calc['due']), $company) }}</td>
                                    <td><x-ed.badge :pair="Labels::invoice($invoice->calc['state'])" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="ed-tabpane {{ $tab === 'tasks' ? 'is-active' : '' }}" id="tab-tasks">
            @if ($tasks->isEmpty())
                <x-ed.empty icon="ti-checkbox" :title="__('لا توجد مهام مرتبطة بهذا العميل.')" />
            @else
                <div class="ed-list">
                    @foreach ($tasks as $task)
                        <a href="{{ route('edata.tasks.show', $task->id) }}">
                            <div class="ed-list__main">
                                <span class="ed-list__title">{{ $task->name }}</span>
                                <span class="ed-list__sub">{{ $task->project->project_name ?? '' }}</span>
                            </div>
                            <x-ed.badge :pair="TaskRules::state($task, $company)" />
                        </a>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="ed-tabpane {{ $tab === 'notes' ? 'is-active' : '' }}" id="tab-notes">
            <x-ed.notes :notes="$notes" type="client" :id="$client->id" :can-add="$canNote" />
        </div>

        <div class="ed-tabpane {{ $tab === 'activity' ? 'is-active' : '' }}" id="tab-activity">
            <x-ed.trail :logs="$activity" />
        </div>
    </section>
</div>
@endsection
