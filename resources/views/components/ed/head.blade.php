@props(['title', 'sub' => null, 'crumbs' => []])
<div class="ed-head">
    <div>
        @if (count($crumbs))
            <div class="ed-crumb">
                @foreach ($crumbs as $label => $url)
                    <a href="{{ $url }}">{{ $label }}</a><i class="ti ti-chevron-left" style="font-size:13px"></i>
                @endforeach
            </div>
        @endif
        <h1><bdi>{{ $title }}</bdi></h1>
        @if ($sub)<p>{{ $sub }}</p>@endif
    </div>
    @if (trim($slot))
        <div class="ed-head__actions no-print">{{ $slot }}</div>
    @endif
</div>
