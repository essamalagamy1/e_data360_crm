@props(['items'])
@if ($items->total() > 0)
    <div class="ed-pager no-print">
        <span>{{ __('عرض') }} <b class="ed-num">{{ number_format($items->firstItem()) }}</b>-<b class="ed-num">{{ number_format($items->lastItem()) }}</b> {{ __('من') }} <b class="ed-num">{{ number_format($items->total()) }}</b></span>
        @if ($items->hasPages())
            @php
                $current = $items->currentPage();
                $last = $items->lastPage();
                $pages = collect([1, $current - 1, $current, $current + 1, $last])->filter(fn ($p) => $p >= 1 && $p <= $last)->unique()->sort()->values();
            @endphp
            <nav class="ed-pager__links" aria-label="{{ __('التنقل بين الصفحات') }}">
                <a href="{{ $items->previousPageUrl() ?? '#' }}" class="{{ $items->onFirstPage() ? 'is-off' : '' }}" aria-label="{{ __('السابق') }}"><i class="ti ti-chevron-right"></i></a>
                @foreach ($pages as $i => $page)
                    @if ($i > 0 && $page - $pages[$i - 1] > 1)<span class="is-page">…</span>@endif
                    <a href="{{ $items->url($page) }}" class="ed-num {{ $page === $current ? 'is-current' : '' }}" @if ($page === $current) aria-current="page" @endif>{{ $page }}</a>
                @endforeach
                <a href="{{ $items->nextPageUrl() ?? '#' }}" class="{{ $items->hasMorePages() ? '' : 'is-off' }}" aria-label="{{ __('التالي') }}"><i class="ti ti-chevron-left"></i></a>
            </nav>
        @endif
    </div>
@endif
