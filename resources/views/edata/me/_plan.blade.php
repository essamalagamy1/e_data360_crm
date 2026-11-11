@php use App\Support\Edata\Labels; @endphp
<section class="ed-panel" id="plan">
    <div class="ed-panel__head">
        <div><h2>{{ __('خطة اليوم') }}</h2><p>{{ __('اختر حتى 3 مهام تركّز عليها اليوم.') }}</p></div>
    </div>

    @if ($running)
        <div class="ed-timerbar" data-timer-bar>
            <span class="ed-timerbar__dot" aria-hidden="true"></span>
            <div class="ed-timerbar__main">
                <span>{{ __('المؤقت يعمل على') }}</span>
                <b>{{ $runningTask }}</b>
            </div>
            <b class="ed-num ed-timerbar__time" data-timer-since="{{ \Carbon\Carbon::parse($running->started_at, 'UTC')->getTimestamp() }}">00:00:00</b>
            <form method="POST" action="{{ route('edata.timer.stop') }}">
                @csrf
                <button type="submit" class="ed-btn ed-btn--sm ed-btn--primary"><i class="ti ti-player-stop"></i>{{ __('إيقاف') }}</button>
            </form>
        </div>
    @endif

    @if ($planTasks->isNotEmpty())
        <ol class="ed-plan">
            @foreach ($planTasks as $planned)
                @php $isDone = (int) $planned->is_complete === 1 || ($doneStage && (int) $planned->stage_id === (int) $doneStage); @endphp
                <li class="{{ $isDone ? 'is-done' : '' }}">
                    <i class="ti {{ $isDone ? 'ti-circle-check' : 'ti-target' }}" aria-hidden="true"></i>
                    <a href="{{ route('edata.tasks.show', $planned->id) }}">{{ $planned->name }}</a>
                    @if ($isDone)<span class="ed-badge tone-good">{{ __('منجزة') }}</span>@endif
                </li>
            @endforeach
        </ol>
    @endif

    @if ($planChoices->isEmpty() && $planTasks->isEmpty())
        <x-ed.empty small icon="ti-checks" :title="__('لا توجد مهام مفتوحة لتخطط لها اليوم.')" />
    @elseif ($planChoices->isNotEmpty())
        <form method="POST" action="{{ route('edata.my.plan') }}" class="ed-plan__form" data-plan-form>
            @csrf
            <details {{ $planTasks->isEmpty() ? 'open' : '' }}>
                <summary class="ed-link">{{ $planTasks->isEmpty() ? __('اختر مهام اليوم') : __('تعديل خطة اليوم') }}</summary>
                <div class="ed-chips" style="margin-top:10px">
                    @foreach ($planChoices as $choice)
                        <label class="ed-chip" title="{{ $choice->project->project_name ?? '' }}">
                            <input type="checkbox" name="tasks[]" value="{{ $choice->id }}" @checked(in_array((int) $choice->id, $planIds, true)) data-plan-pick>
                            <span>{{ \Illuminate\Support\Str::limit($choice->name, 42) }}</span>
                        </label>
                    @endforeach
                </div>
                @error('tasks')<span class="ed-error" role="alert">{{ $message }}</span>@enderror
                <div style="margin-top:12px"><button type="submit" class="ed-btn ed-btn--primary ed-btn--sm"><i class="ti ti-list-check"></i>{{ __('حفظ الخطة') }}</button></div>
            </details>
        </form>
    @endif

    @if ($dayLog && $dayLog->report)
        <div class="ed-plan__report"><b>{{ __('تقريرك اليوم') }}:</b> {{ $dayLog->report }}</div>
    @endif
</section>
