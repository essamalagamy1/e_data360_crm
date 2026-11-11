@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', __('الإعلانات'))

@php use App\Support\Edata\Labels; @endphp

@section('content')
<x-ed.head :title="__('الإعلانات')" :sub="__('إعلان يظهر لكل الفريق أعلى «مساحتي» ويصلهم إشعار به.')" />

<div class="ed-grid ed-grid--side ed-reveal">
    @if ($canCreate)
        <section class="ed-panel">
            <div class="ed-panel__head"><h2>{{ __('إعلان جديد') }}</h2></div>
            <form method="POST" action="{{ route('edata.announcements.store') }}" class="ed-panel__body">
                @csrf
                <div class="ed-form" style="grid-template-columns:1fr">
                    <x-ed.field :label="__('العنوان')" name="title" required>
                        <input class="ed-input" id="title" name="title" maxlength="191" value="{{ old('title') }}" required>
                    </x-ed.field>
                    <x-ed.field :label="__('التفاصيل')" name="body">
                        <textarea class="ed-textarea" id="body" name="body" rows="5" maxlength="3000">{{ old('body') }}</textarea>
                    </x-ed.field>
                    <x-ed.field :label="__('يظهر حتى')" name="show_until" :hint="__('اتركه فارغاً ليبقى ظاهراً حتى تخفيه.')">
                        <input class="ed-input" type="date" id="show_until" name="show_until" min="{{ now()->toDateString() }}" value="{{ old('show_until') }}">
                    </x-ed.field>
                    <div><button type="submit" class="ed-btn ed-btn--primary"><i class="ti ti-speakerphone"></i>{{ __('نشر الإعلان') }}</button></div>
                </div>
            </form>
        </section>
    @endif

    <section class="ed-panel">
        <div class="ed-panel__head"><h2>{{ __('الإعلانات المنشورة') }}</h2></div>
        @if ($rows->isEmpty())
            <x-ed.empty icon="ti-speakerphone" :title="__('لا توجد إعلانات بعد.')" />
        @else
            <div class="ed-annlist">
                @foreach ($rows as $row)
                    @php
                        $readers = $reads[$row->id] ?? collect();
                        $expired = $row->show_until && $row->show_until < now()->toDateString();
                        $hidden = $row->archived_at || $expired;
                    @endphp
                    <article class="ed-ann {{ $hidden ? 'is-hidden' : '' }}">
                        <div class="ed-ann__head">
                            <h3>{{ $row->title }}</h3>
                            @if ($row->archived_at)
                                <span class="ed-badge tone-muted">{{ __('مخفي') }}</span>
                            @elseif ($expired)
                                <span class="ed-badge tone-muted">{{ __('انتهى عرضه') }}</span>
                            @else
                                <span class="ed-badge tone-good">{{ __('ظاهر') }}</span>
                            @endif
                        </div>
                        @if ($row->body)<p>{!! nl2br(e($row->body)) !!}</p>@endif
                        <div class="ed-ann__foot">
                            <span class="ed-num">{{ Labels::date($row->created_at, 'j M Y') }}@if ($row->show_until) · {{ __('حتى') }} {{ Labels::date($row->show_until, 'j M') }}@endif</span>
                            <details>
                                <summary class="ed-link"><i class="ti ti-eye"></i> {{ __('قرأه') }} <b class="ed-num">{{ $readers->count() }}</b> {{ __('من') }} <b class="ed-num">{{ $staffCount }}</b></summary>
                                @if ($readers->isNotEmpty())
                                    <p class="ed-hint">{{ $readers->map(fn ($r) => $names[$r->user_id] ?? '')->filter()->implode('، ') }}</p>
                                @endif
                            </details>
                            @unless ($row->archived_at)
                                <form method="POST" action="{{ route('edata.announcements.archive', $row->id) }}" data-confirm="{{ __('إخفاء هذا الإعلان؟') }}" data-confirm-text="{{ __('لن يظهر للفريق بعد الآن، ويبقى هنا في السجل.') }}" data-confirm-yes="{{ __('إخفاء') }}" data-confirm-safe>
                                    @csrf
                                    <button type="submit" class="ed-btn ed-btn--ghost ed-btn--sm"><i class="ti ti-archive"></i>{{ __('إخفاء') }}</button>
                                </form>
                            @endunless
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
</div>
@endsection
