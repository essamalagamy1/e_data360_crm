@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', __('الفواتير'))

@php
    use App\Support\Edata\Fmt;
    use App\Support\Edata\Labels;
@endphp

@section('content')
<x-ed.head :title="__('الفواتير')" :sub="__('قيمة الفواتير شيء، والمبالغ المحصّلة شيء آخر. الإيراد يُحسب من الدفعات المسجلة فقط.')">
    <a class="ed-btn" href="{{ route('edata.invoices.export', request()->query()) }}"><i class="ti ti-file-export"></i>{{ __('تصدير Excel') }}</a>
    @if ($canCreate)
        <a class="ed-btn ed-btn--primary" href="{{ route('edata.invoices.create') }}"><i class="ti ti-plus"></i>{{ __('فاتورة جديدة') }}</a>
    @endif
</x-ed.head>

<div class="ed-stack ed-reveal">
    <div class="ed-figures">
        <x-ed.figure :label="__('إجمالي قيمة الفواتير')" :money="$summary['billed']" :company="$company" :hint="$summary['count'] . ' ' . __('فاتورة')" />
        <x-ed.figure :label="__('إجمالي المدفوعات')" :money="$summary['collected']" :company="$company" tone="good" />
        <x-ed.figure :label="__('المبالغ المستحقة')" :money="$summary['due']" :company="$company" :tone="$summary['due'] > 0 ? 'bad' : null" :href="route('edata.invoices.index')" />
        <x-ed.figure :label="__('متأخرة')" :count="$summary['states']['overdue'] ?? 0" :tone="($summary['states']['overdue'] ?? 0) ? 'bad' : null" :hint="Fmt::money($summary['overdue_amount'], $company)" :href="route('edata.invoices.index', ['state' => 'overdue'])" />
        <x-ed.figure :label="__('إيراد هذا الشهر')" :money="$monthRevenue" :company="$company" />
        <x-ed.figure :label="__('إيراد هذا العام')" :money="$yearRevenue" :company="$company" />
    </div>

    <section class="ed-panel">
        <form class="ed-filters" method="GET" data-autosubmit>
            <div class="ed-search">
                <i class="ti ti-search" aria-hidden="true"></i>
                <input class="ed-input" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('ابحث برقم الفاتورة أو اسم العميل') }}" aria-label="{{ __('بحث') }}">
            </div>
            <select class="ed-select" name="state" aria-label="{{ __('الحالة') }}">
                <option value="">{{ __('كل الحالات') }}</option>
                @foreach (Labels::INVOICE_STATES as $value => $pair)<option value="{{ $value }}" @selected(request('state') === $value)>{{ __($pair[0]) }}</option>@endforeach
            </select>
            <select class="ed-select" name="customer" aria-label="{{ __('العميل') }}">
                <option value="">{{ __('كل العملاء') }}</option>
                @foreach ($customers as $c)<option value="{{ $c->id }}" @selected((int) request('customer') === (int) $c->id)>{{ $c->name }}</option>@endforeach
            </select>
            <select class="ed-select" name="project" aria-label="{{ __('المشروع') }}">
                <option value="">{{ __('كل المشاريع') }}</option>
                @foreach ($projects as $p)<option value="{{ $p->id }}" @selected((int) request('project') === (int) $p->id)>{{ $p->project_name }}</option>@endforeach
            </select>
            <input class="ed-input" type="date" name="from" value="{{ request('from') }}" aria-label="{{ __('من تاريخ') }}">
            <input class="ed-input" type="date" name="to" value="{{ request('to') }}" aria-label="{{ __('إلى تاريخ') }}">
            @if (request()->hasAny(['q', 'state', 'customer', 'project', 'from', 'to']))
                <a class="ed-btn ed-btn--ghost ed-btn--sm" href="{{ route('edata.invoices.index') }}">{{ __('مسح الفلاتر') }}</a>
            @endif
        </form>

        @if ($invoices->isEmpty())
            <x-ed.empty icon="ti-file-invoice" :title="__('لا توجد فواتير تطابق هذا البحث.')">
                @if ($canCreate)<a class="ed-btn ed-btn--primary" href="{{ route('edata.invoices.create') }}">{{ __('فاتورة جديدة') }}</a>@endif
            </x-ed.empty>
        @else
            <div class="ed-table-wrap">
                <table class="ed-table">
                    <thead><tr><th>{{ __('الفاتورة') }}</th><th>{{ __('العميل') }}</th><th>{{ __('الإصدار') }}</th><th>{{ __('الاستحقاق') }}</th><th>{{ __('الإجمالي') }}</th><th>{{ __('المدفوع') }}</th><th>{{ __('المتبقي') }}</th><th>{{ __('الحالة') }}</th></tr></thead>
                    <tbody>
                        @foreach ($invoices as $invoice)
                            <tr>
                                <td>
                                    <a class="ed-table__title ed-num" href="{{ route('edata.invoices.show', $invoice->id) }}"><bdi>{{ Fmt::invoiceNumber($invoice->invoice_id, $company) }}</bdi></a>
                                    @if ($invoice->project)<span class="ed-table__sub">{{ \Illuminate\Support\Str::limit($invoice->project->project_name, 34) }}</span>@endif
                                </td>
                                <td>{{ $invoice->customer->name ?? '—' }}</td>
                                <td class="is-tight">{{ Labels::date($invoice->issue_date) }}</td>
                                <td class="is-tight {{ $invoice->calc['state'] === 'overdue' ? 'tone-text-bad' : '' }}">{{ Labels::date($invoice->due_date) }}</td>
                                <td class="ed-num is-tight">{{ Fmt::money($invoice->calc['total'], $company) }}</td>
                                <td class="ed-num is-tight">{{ Fmt::money($invoice->calc['paid'], $company) }}</td>
                                <td class="ed-num is-tight">{{ Fmt::money(max(0, $invoice->calc['due']), $company) }}</td>
                                <td><x-ed.badge :pair="Labels::invoice($invoice->calc['state'])" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-ed.pager :items="$invoices" />
        @endif
    </section>
</div>
@endsection
