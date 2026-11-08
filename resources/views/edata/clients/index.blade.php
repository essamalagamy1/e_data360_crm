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
            <div class="ed-ccards">
                @foreach ($clients as $client)
                    @php
                        $m = $money[$client->id] ?? ['total' => 0, 'paid' => 0, 'due' => 0, 'count' => 0];
                        $collectedPct = $m['total'] > 0 ? (int) round($m['paid'] / $m['total'] * 100) : null;
                        $place = collect([$client->billing_city, $client->billing_country])->filter()->implode('، ');
                    @endphp
                    <a class="ed-ccard {{ $client->archived_at ? 'is-archived' : '' }}" href="{{ route('edata.clients.show', $client->id) }}">
                        <div class="ed-ccard__top">
                            <span class="ed-cmark" style="--h: {{ crc32($client->name) % 5 }}" aria-hidden="true">{{ mb_substr(trim($client->name), 0, 1) }}</span>
                            <div class="ed-ccard__id">
                                <b>{{ $client->name }}</b>
                                <span>{{ collect([$client->contact_person, $client->industry])->filter()->implode('، ') ?: __('بدون تفاصيل إضافية') }}</span>
                            </div>
                            @if ($client->archived_at)
                                <x-ed.badge tone="muted" :text="__('مؤرشف')" />
                            @else
                                <x-ed.badge :pair="Labels::client($client->crm_status)" />
                            @endif
                        </div>

                        <div class="ed-ccard__contact">
                            @if ($place)<span><i class="ti ti-map-pin"></i>{{ $place }}</span>@endif
                            @if ($client->contact)<span><i class="ti ti-phone"></i><bdi>{{ $client->contact }}</bdi></span>@endif
                            @if ($client->email)<span><i class="ti ti-mail"></i><bdi>{{ $client->email }}</bdi></span>@endif
                        </div>

                        <dl class="ed-ccard__nums">
                            <div><dt>{{ __('المشاريع') }}</dt><dd class="ed-num">{{ $projectCounts[$client->id] ?? 0 }}</dd></div>
                            <div><dt>{{ __('الفواتير') }}</dt><dd class="ed-num">{{ $m['count'] }}</dd></div>
                            <div><dt>{{ __('قيمة التعاملات') }}</dt><dd class="ed-num">{{ Fmt::money($m['total'], $company) }}</dd></div>
                            <div><dt>{{ __('المتبقي') }}</dt><dd class="ed-num {{ $m['due'] > 0 ? 'tone-text-bad' : '' }}">{{ Fmt::money($m['due'], $company) }}</dd></div>
                        </dl>

                        @if ($collectedPct !== null)
                            <div class="ed-ccard__paid">
                                <div class="ed-progress {{ $collectedPct >= 100 ? 'is-good' : '' }}"><i style="--v: {{ min(100, $collectedPct) }}%"></i></div>
                                <span class="ed-num">{{ __('تم تحصيل :pct%', ['pct' => $collectedPct]) }}</span>
                            </div>
                        @endif
                    </a>
                @endforeach
            </div>
            <x-ed.pager :items="$clients" />
        @endif
    </section>
</div>
@endsection
