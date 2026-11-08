@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', __('الإشعارات'))

@section('content')
<x-ed.head :title="__('الإشعارات')" :sub="$unread ? $unread . ' ' . __('غير مقروء') : __('لا توجد إشعارات غير مقروءة.')">
    @if ($unread)
        <form method="POST" action="{{ route('edata.notifications.read-all') }}">
            @csrf
            <button class="ed-btn" type="submit"><i class="ti ti-checks"></i>{{ __('تعليم الكل كمقروء') }}</button>
        </form>
    @endif
</x-ed.head>

<section class="ed-panel ed-reveal">
    @if ($notifications->isEmpty())
        <x-ed.empty icon="ti-bell-off" :title="__('لا توجد إشعارات حالياً.')" :text="__('تصلك هنا المهام الجديدة ومواعيد التسليم والفواتير والدفعات.')" />
    @else
        @foreach ($notifications as $notification)
            @php $data = json_decode($notification->data, true) ?: []; @endphp
            <a class="ed-bell__row {{ $notification->is_read ? '' : 'is-new' }}" href="{{ route('edata.notifications.open', $notification->id) }}">
                <b>{{ $data['title'] ?? '' }}</b>
                @if (!empty($data['body']))<span>{{ $data['body'] }}</span>@endif
                <small>{{ $notification->created_at?->diffForHumans() }}</small>
            </a>
        @endforeach
        <x-ed.pager :items="$notifications" />
    @endif
</section>
@endsection
