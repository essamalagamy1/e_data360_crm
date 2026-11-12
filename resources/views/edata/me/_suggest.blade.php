@php
    use App\Http\Controllers\Edata\FeedbackController;
    use App\Support\Edata\Labels;
@endphp
<section class="ed-panel" id="suggest">
    <div class="ed-panel__head">
        <div><h2>{{ __('صندوق الاقتراحات') }}</h2><p>{{ __('فكرة تحسّن الشغل أو ملاحظة تهمّك؟ اكتبها هنا.') }}</p></div>
    </div>
    <form method="POST" action="{{ route('edata.suggestions.store') }}" class="ed-panel__body ed-suggest">
        @csrf
        <select class="ed-select" name="category" aria-label="{{ __('نوع الاقتراح') }}" required>
            @foreach (FeedbackController::CATEGORIES as $value => $label)
                <option value="{{ $value }}" @selected(old('category', 'work') === $value)>{{ __($label) }}</option>
            @endforeach
        </select>
        <textarea class="ed-textarea" name="body" rows="3" minlength="5" maxlength="2000" required placeholder="{{ __('اكتب اقتراحك...') }}" aria-label="{{ __('الاقتراح') }}">{{ old('body') }}</textarea>
        @error('body')<span class="ed-error" role="alert">{{ $message }}</span>@enderror
        <div class="ed-suggest__foot">
            <label class="ed-check"><input type="checkbox" name="anonymous" value="1" @checked(old('anonymous'))> {{ __('أرسله بدون اسمي') }}</label>
            <button type="submit" class="ed-btn ed-btn--primary ed-btn--sm"><i class="ti ti-send"></i>{{ __('إرسال') }}</button>
        </div>
        <span class="ed-hint">{{ __('الاقتراح بدون اسم لا يُحفظ معه أي شيء يدل عليك، لذلك لن يصلك رد عليه.') }}</span>
    </form>
    @if ($mySuggestions->isNotEmpty())
        <div class="ed-suggest__mine">
            <span class="ed-hint">{{ __('اقتراحاتك') }}</span>
            @foreach ($mySuggestions as $s)
                <div class="ed-suggest__item">
                    <div class="ed-suggest__row">
                        <span>{{ \Illuminate\Support\Str::limit($s->body, 90) }}</span>
                        <x-ed.badge :pair="FeedbackController::SUGGESTION_STATUSES[$s->status] ?? ['—', 'muted']" />
                    </div>
                    @if ($s->reply)<p class="ed-suggest__reply"><i class="ti ti-message-reply"></i> {{ $s->reply }}</p>@endif
                    <span class="ed-hint ed-num">{{ Labels::date($s->created_at, 'j M Y') }}</span>
                </div>
            @endforeach
        </div>
    @endif
</section>
