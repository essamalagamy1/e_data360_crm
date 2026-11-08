@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', __('العملاء'))

@php
    use App\Support\Edata\Fmt;
    use App\Support\Edata\Labels;
@endphp

@section('content')
<x-ed.head :title="__('العملاء')" :sub="__('كل عميل بمشاريعه وفواتيره وما دفعه وما تبقى عليه.')">
    <a class="ed-btn" href="{{ route('edata.clients.export', request()->query()) }}"><i class="ti ti-file-export"></i>{{ __('تصدير Excel') }}</a>
    @if ($canCreate)
        <a class="ed-btn ed-btn--primary" href="{{ route('edata.clients.create') }}"><i class="ti ti-plus"></i>{{ __('إضافة عميل') }}</a>
    @endif
</x-ed.head>

<div class="ed-stack ed-reveal">
    <div class="ed-figures">
        <x-ed.figure :label="__('إجمالي العملاء')" :count="$stats['total']" :href="route('edata.clients.index')" />
        <x-ed.figure :label="__('نشطون')" :count="$stats['active']" tone="good" :href="route('edata.clients.index', ['status' => 'active'])" />
        <x-ed.figure :label="__('في الأرشيف')" :count="$stats['archived']" :href="route('edata.clients.index', ['archived' => 1])" />
    </div>

    <section class="ed-panel">
        <form class="ed-filters" method="GET" data-autosubmit>
            <div class="ed-search">
                <i class="ti ti-search" aria-hidden="true"></i>
                <input class="ed-input" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('ابحث بالاسم أو البريد أو الهاتف') }}" aria-label="{{ __('بحث') }}">
            </div>
            <select class="ed-select" name="status" aria-label="{{ __('الحالة') }}">
                <option value="">{{ __('كل الحالات') }}</option>
                @foreach (Labels::CLIENT_STATUSES as $value => $pair)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ __($pair[0]) }}</option>
                @endforeach
            </select>
            @if ($countries->isNotEmpty())
                <select class="ed-select" name="country" aria-label="{{ __('الدولة') }}">
                    <option value="">{{ __('كل الدول') }}</option>
                    @foreach ($countries as $country)<option value="{{ $country }}" @selected(request('country') === $country)>{{ $country }}</option>@endforeach
                </select>
            @endif
            @if ($industries->isNotEmpty())
                <select class="ed-select" name="industry" aria-label="{{ __('نوع النشاط') }}">
                    <option value="">{{ __('كل الأنشطة') }}</option>
                    @foreach ($industries as $industry)<option value="{{ $industry }}" @selected(request('industry') === $industry)>{{ $industry }}</option>@endforeach
                </select>
            @endif
            @if (request('archived'))<input type="hidden" name="archived" value="1">@endif
            @if (request()->hasAny(['q', 'status', 'country', 'industry', 'archived']))
                <a class="ed-btn ed-btn--ghost ed-btn--sm" href="{{ route('edata.clients.index') }}">{{ __('مسح الفلاتر') }}</a>
            @endif
        </form>

        @if ($clients->isEmpty())
            <x-ed.empty icon="ti-building-store" :title="__('لا يوجد عملاء لعرضهم حالياً.')">
                @if ($canCreate)<a class="ed-btn ed-btn--primary" href="{{ route('edata.clients.create') }}">{{ __('إضافة عميل') }}</a>@endif
            </x-ed.empty>
        @else
            <div class="ed-table-wrap">
                <table class="ed-table">
                    <thead>
                        <tr><th>{{ __('العميل') }}</th><th>{{ __('التواصل') }}</th><th>{{ __('الموقع') }}</th><th>{{ __('المشاريع') }}</th><th>{{ __('قيمة التعاملات') }}</th><th>{{ __('المتبقي') }}</th><th>{{ __('الحالة') }}</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($clients as $client)
                            <tr>
                                <td>
                                    <a class="ed-table__title" href="{{ route('edata.clients.show', $client->id) }}">{{ $client->name }}</a>
                                    <span class="ed-table__sub">{{ $client->contact_person ?: $client->industry }}</span>
                                </td>
                                <td>
                                    <span class="ed-ltr">{{ $client->contact }}</span>
                                    <span class="ed-table__sub">{{ $client->email }}</span>
                                </td>
                                <td>{{ collect([$client->billing_city, $client->billing_country])->filter()->implode('، ') ?: '—' }}</td>
                                <td class="ed-num">{{ $projectCounts[$client->id] ?? 0 }}</td>
                                <td class="ed-num">{{ Fmt::money($money[$client->id]['total'] ?? 0, $company) }}</td>
                                <td class="ed-num {{ ($money[$client->id]['due'] ?? 0) > 0 ? 'tone-text-bad' : '' }}">{{ Fmt::money($money[$client->id]['due'] ?? 0, $company) }}</td>
                                <td>
                                    @if ($client->archived_at)
                                        <x-ed.badge tone="muted" :text="__('مؤرشف')" />
                                    @else
                                        <x-ed.badge :pair="Labels::client($client->crm_status)" />
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-ed.pager :items="$clients" />
        @endif
    </section>
</div>
@endsection
