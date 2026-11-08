@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', __('العملاء'))

{{-- For roles that may only view clients: names, nothing else. --}}
@section('content')
<x-ed.head :title="__('العملاء')" :sub="__('أسماء عملائنا. تفاصيل التواصل والحسابات متاحة لمن لديه صلاحية تعديل العملاء.')" />

<section class="ed-panel ed-reveal">
    <form class="ed-filters" method="GET">
        <div class="ed-search">
            <i class="ti ti-search" aria-hidden="true"></i>
            <input class="ed-input" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('ابحث باسم العميل') }}" aria-label="{{ __('بحث') }}">
        </div>
    </form>

    @if ($clients->isEmpty())
        <x-ed.empty icon="ti-building-store" :title="__('لا يوجد عملاء بهذا الاسم.')" />
    @else
        <ul class="ed-names">
            @foreach ($clients as $client)
                <li><span class="ed-cmark" style="--h: {{ crc32($client->name) % 5 }}" aria-hidden="true">{{ mb_substr(trim($client->name), 0, 1) }}</span><span>{{ $client->name }}</span></li>
            @endforeach
        </ul>
        <x-ed.pager :items="$clients" />
    @endif
</section>
@endsection
