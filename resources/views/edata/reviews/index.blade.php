@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', __('التقييم الشهري'))

@php
    use App\Support\Edata\Labels;
    $key = $month->format('Y-m');
    $prev = $month->copy()->subMonth()->format('Y-m');
    $next = $month->copy()->addMonth()->format('Y-m');
    $done = $reviews->count();
@endphp

@section('content')
<x-ed.head :title="__('التقييم الشهري')" :sub="__('تقييم بسيط لكل موظف من 1 إلى 5، بجانب أرقامه الحقيقية في الشهر. يراه الموظف في «مساحتي».')" />

<section class="ed-panel ed-reveal">
    <div class="ed-panel__head">
        <div>
            <h2>{{ Labels::date($key . '-01', 'F Y') }}</h2>
            <p><b class="ed-num">{{ $done }}</b> {{ __('من') }} <b class="ed-num">{{ $employees->count() }}</b> {{ __('تم تقييمهم') }}</p>
        </div>
        <div class="ed-seg" aria-label="{{ __('اختيار الشهر') }}">
            <a href="{{ route('edata.reviews.index', ['m' => $prev]) }}" title="{{ __('الشهر السابق') }}" aria-label="{{ __('الشهر السابق') }}"><i class="ti ti-chevron-right"></i></a>
            @unless ($isThisMonth)
                <a href="{{ route('edata.reviews.index', ['m' => $next]) }}" title="{{ __('الشهر التالي') }}" aria-label="{{ __('الشهر التالي') }}"><i class="ti ti-chevron-left"></i></a>
            @endunless
        </div>
    </div>

    @if ($employees->isEmpty())
        <x-ed.empty icon="ti-users" :title="__('لا يوجد موظفون لتقييمهم.')" />
    @else
        <div class="ed-reviewdesk">
            @foreach ($employees as $employee)
                @php
                    $review = $reviews[$employee->id] ?? null;
                    $sum = $summaries[$employee->id];
                    $rating = (int) old('rating_for') === $employee->id ? (int) old('rating') : ($review->rating ?? 0);
                @endphp
                <article class="ed-reviewcard {{ $review ? 'is-done' : '' }}" id="emp-{{ $employee->id }}">
                    <div class="ed-reviewcard__who">
                        <x-ed.avatar :user="$employee->user" :name="$employee->name" />
                        <div>
                            <b>{{ $employee->name }}</b>
                            @if ($review)<span class="ed-badge tone-good">{{ __('تم التقييم') }}</span>@else<span class="ed-badge tone-muted">{{ __('لم يُقيَّم بعد') }}</span>@endif
                        </div>
                    </div>
                    <div class="ed-reviewcard__facts">
                        <span><b class="ed-num">{{ Labels::hours($sum['seconds']) }}</b>{{ __('ساعات') }}</span>
                        <span><b class="ed-num">{{ $sum['attended'] }}</b>{{ __('أيام حضور') }}</span>
                        <span><b class="ed-num">{{ $sum['rate'] !== null ? $sum['rate'] . '%' : '—' }}</b>{{ __('الالتزام') }}</span>
                        <span><b class="ed-num">{{ $sum['tasks'] }}</b>{{ __('مهام أُنجزت') }}</span>
                        <span><b class="ed-num">{{ $sum['kudos'] }}</b>{{ __('كلمات شكر') }}</span>
                    </div>
                    <form method="POST" action="{{ route('edata.reviews.save', $employee->id) }}#emp-{{ $employee->id }}" class="ed-reviewcard__form">
                        @csrf
                        <input type="hidden" name="month" value="{{ $key }}">
                        <input type="hidden" name="rating_for" value="{{ $employee->id }}">
                        <fieldset class="ed-starpick" aria-label="{{ __('التقييم') }}">
                            @foreach ([5, 4, 3, 2, 1] as $value)
                                <input type="radio" id="r{{ $employee->id }}-{{ $value }}" name="rating" value="{{ $value }}" @checked($rating === $value) required>
                                <label for="r{{ $employee->id }}-{{ $value }}" title="{{ __($ratings[$value]) }}"><x-ed.star /></label>
                            @endforeach
                        </fieldset>
                        <textarea class="ed-textarea" name="strengths" rows="2" maxlength="1500" placeholder="{{ __('نقاط القوة هذا الشهر') }}" aria-label="{{ __('نقاط القوة') }}">{{ $review->strengths ?? '' }}</textarea>
                        <textarea class="ed-textarea" name="improve" rows="2" maxlength="1500" placeholder="{{ __('ما يحتاج إلى تحسين') }}" aria-label="{{ __('ما يحتاج إلى تحسين') }}">{{ $review->improve ?? '' }}</textarea>
                        <div><button type="submit" class="ed-btn ed-btn--primary ed-btn--sm"><i class="ti ti-device-floppy"></i>{{ $review ? __('تحديث التقييم') : __('حفظ التقييم') }}</button></div>
                    </form>
                </article>
            @endforeach
        </div>
    @endif
</section>
@endsection
