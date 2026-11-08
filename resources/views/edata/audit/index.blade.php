@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', __('سجل الأنشطة'))

@php use App\Support\Edata\Labels; @endphp

@section('content')
<x-ed.head :title="__('سجل الأنشطة')" :sub="__('من عمل ماذا ومتى، بالقيم القديمة والجديدة. لا يمكن تعديل هذا السجل أو حذفه.')" />

<section class="ed-panel ed-reveal">
    <form class="ed-filters" method="GET" data-autosubmit>
        <div class="ed-search">
            <i class="ti ti-search" aria-hidden="true"></i>
            <input class="ed-input" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('ابحث باسم العنصر') }}" aria-label="{{ __('بحث') }}">
        </div>
        <select class="ed-select" name="user" aria-label="{{ __('المستخدم') }}">
            <option value="">{{ __('كل المستخدمين') }}</option>
            @foreach ($users as $u)<option value="{{ $u->id }}" @selected((int) request('user') === (int) $u->id)>{{ $u->name }}</option>@endforeach
        </select>
        <select class="ed-select" name="action" aria-label="{{ __('نوع العملية') }}">
            <option value="">{{ __('كل العمليات') }}</option>
            @foreach (Labels::AUDIT_ACTIONS as $value => $pair)<option value="{{ $value }}" @selected(request('action') === $value)>{{ __($pair[0]) }}</option>@endforeach
        </select>
        <select class="ed-select" name="entity" aria-label="{{ __('العنصر') }}">
            <option value="">{{ __('كل العناصر') }}</option>
            @foreach (config('edata.audit_entities') as $value => $label)<option value="{{ $value }}" @selected(request('entity') === $value)>{{ __($label) }}</option>@endforeach
        </select>
        <input class="ed-input" type="date" name="from" value="{{ request('from') }}" aria-label="{{ __('من تاريخ') }}">
        <input class="ed-input" type="date" name="to" value="{{ request('to') }}" aria-label="{{ __('إلى تاريخ') }}">
        @if (request()->hasAny(['q', 'user', 'action', 'entity', 'from', 'to']))
            <a class="ed-btn ed-btn--ghost ed-btn--sm" href="{{ route('edata.audit.index') }}">{{ __('مسح الفلاتر') }}</a>
        @endif
    </form>
    <x-ed.trail :logs="$logs->getCollection()" />
    <x-ed.pager :items="$logs" />
</section>
@endsection
