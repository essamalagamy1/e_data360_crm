@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', __('الاقتراحات'))

@php use App\Support\Edata\Labels; @endphp

@section('content')
<x-ed.head :title="__('صندوق الاقتراحات')" :sub="__('اقتراحات الفريق. ردّك يصل لصاحب الاقتراح إشعاراً، إلا إذا أرسله بدون اسم.')" />

<section class="ed-panel ed-reveal">
    <div class="ed-tabs" role="tablist">
        <a href="{{ route('edata.suggestions.index') }}" class="{{ !$status ? 'is-active' : '' }}">{{ __('الكل') }} <b class="ed-num">{{ $counts->sum() }}</b></a>
        @foreach ($statuses as $key => $pair)
            <a href="{{ route('edata.suggestions.index', ['status' => $key]) }}" class="{{ $status === $key ? 'is-active' : '' }}">{{ __($pair[0]) }}@if ($key === 'new' && ($counts['new'] ?? 0) > 0) <b class="ed-nav__count">{{ $counts['new'] }}</b>@else <b class="ed-num">{{ $counts[$key] ?? 0 }}</b>@endif</a>
        @endforeach
    </div>

    @if ($rows->isEmpty())
        <x-ed.empty icon="ti-bulb" :title="__('لا توجد اقتراحات هنا بعد.')" />
    @else
        <div class="ed-reqdesk">
            @foreach ($rows as $row)
                @php $author = $row->user_id ? ($people[$row->user_id] ?? null) : null; @endphp
                <article class="ed-reqcard">
                    <div class="ed-reqcard__who">
                        @if ($author)
                            <x-ed.avatar :user="$author" />
                        @else
                            <span class="ed-avatar ed-avatar--anon" aria-hidden="true"><i class="ti ti-user-question"></i></span>
                        @endif
                        <div>
                            <b>{{ $author->name ?? __('بدون اسم') }}</b>
                            <span class="ed-badge tone-primary">{{ __($categories[$row->category] ?? $row->category) }}</span>
                        </div>
                    </div>
                    <div class="ed-reqcard__what">
                        <span class="ed-num"><i class="ti ti-calendar"></i>{{ Labels::date($row->created_at, 'l، j M Y') }}</span>
                        <p style="color:var(--text);white-space:pre-line">{{ $row->body }}</p>
                        @if ($row->reply)
                            <p class="ed-hint"><i class="ti ti-message-reply"></i> {{ $row->reply }}@if ($row->replied_by && isset($people[$row->replied_by])) — {{ $people[$row->replied_by]->name }}@endif</p>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('edata.suggestions.answer', $row->id) }}" class="ed-reqcard__act">
                        @csrf
                        <select class="ed-select" name="status" aria-label="{{ __('الحالة') }}">
                            @foreach ($statuses as $key => $pair)
                                <option value="{{ $key }}" @selected($row->status === $key || ($row->status === 'new' && $key === 'seen'))>{{ __($pair[0]) }}</option>
                            @endforeach
                        </select>
                        @if ($row->user_id)
                            <textarea class="ed-textarea" name="reply" rows="2" maxlength="2000" placeholder="{{ __('ردّك (اختياري)') }}" aria-label="{{ __('الرد') }}">{{ $row->reply }}</textarea>
                        @endif
                        <div><button type="submit" class="ed-btn ed-btn--primary ed-btn--sm"><i class="ti ti-check"></i>{{ __('حفظ') }}</button></div>
                    </form>
                </article>
            @endforeach
        </div>
        <x-ed.pager :items="$rows" />
    @endif
</section>
@endsection
