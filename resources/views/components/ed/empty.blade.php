@props(['icon' => 'ti-inbox', 'title' => null, 'text' => null, 'small' => false])
<div class="ed-empty {{ $small ? 'ed-empty--sm' : '' }}">
    <i class="ti {{ $icon }}" aria-hidden="true"></i>
    <b>{{ $title ?? __('لا توجد بيانات لعرضها حالياً.') }}</b>
    @if ($text)<span>{{ $text }}</span>@endif
    @if (trim($slot))<div>{{ $slot }}</div>@endif
</div>
