@if ($announcements->isNotEmpty())
    <section class="ed-news" aria-label="{{ __('إعلانات الشركة') }}">
        @foreach ($announcements as $news)
            <article class="ed-news__item {{ $news->read_at ? 'is-read' : '' }}" data-news>
                <i class="ti ti-speakerphone ed-news__icon" aria-hidden="true"></i>
                <div class="ed-news__main">
                    <h3>{{ $news->title }}</h3>
                    @if ($news->body)<p>{!! nl2br(e($news->body)) !!}</p>@endif
                    <span class="ed-news__meta">{{ \Carbon\Carbon::parse($news->created_at)->locale(app()->getLocale())->diffForHumans() }}</span>
                </div>
                @unless ($news->read_at)
                    <form method="POST" action="{{ route('edata.announcements.read', $news->id) }}" data-news-read>
                        @csrf
                        <button type="submit" class="ed-btn ed-btn--ghost ed-btn--sm"><i class="ti ti-check"></i>{{ __('تم') }}</button>
                    </form>
                @endunless
            </article>
        @endforeach
    </section>
@endif
