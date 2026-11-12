@php
    use App\Http\Controllers\Edata\FeedbackController;
    use App\Support\Edata\Labels;
    $reviewer = \App\Models\User::find($myReview->reviewer_id, ['id', 'name']);
@endphp
<section class="ed-panel" id="review">
    <div class="ed-panel__head">
        <div><h2>{{ __('تقييمك الشهري') }}</h2><p>{{ Labels::date($myReview->month . '-01', 'F Y') }}</p></div>
    </div>
    <div class="ed-panel__body ed-review">
        <div class="ed-review__score">
            <span class="ed-stars" aria-label="{{ $myReview->rating }} {{ __('من 5') }}">
                @for ($i = 1; $i <= 5; $i++)<span class="{{ $i <= $myReview->rating ? 'is-on' : '' }}"><x-ed.star /></span>@endfor
            </span>
            <b>{{ __(FeedbackController::RATINGS[$myReview->rating] ?? '') }}</b>
        </div>
        @if ($myReview->strengths)
            <div class="ed-review__part"><span><i class="ti ti-thumb-up"></i>{{ __('نقاط القوة') }}</span><p>{!! nl2br(e($myReview->strengths)) !!}</p></div>
        @endif
        @if ($myReview->improve)
            <div class="ed-review__part"><span><i class="ti ti-trending-up"></i>{{ __('ما يحتاج إلى تحسين') }}</span><p>{!! nl2br(e($myReview->improve)) !!}</p></div>
        @endif
        @if ($reviewer)<span class="ed-hint">{{ __('من') }}: {{ $reviewer->name }}</span>@endif
    </div>
</section>
