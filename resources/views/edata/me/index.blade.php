@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', __('مساحتي'))

@php
    use App\Support\Edata\Labels;
    use App\Support\Edata\TaskRules;

    // The calendar for this month, with the real status of every day so far.
    $nowInZone = \Carbon\Carbon::now($zone);
    $firstOfMonth = $nowInZone->copy()->startOfMonth();
    $monthByDate = $monthDays->keyBy('date');
    $dayClass = [
        'present' => 'is-present', 'overtime' => 'is-overtime', 'working' => 'is-working', 'late' => 'is-late',
        'missing_out' => 'is-missing', 'absent' => 'is-absent', 'leave' => 'is-leave', 'holiday' => 'is-holiday', 'weekend' => 'is-rest',
    ];
    $weekDays = app()->getLocale() === 'en' ? ['S', 'M', 'T', 'W', 'T', 'F', 'S'] : ['ح', 'ن', 'ث', 'ر', 'خ', 'ج', 'س'];
@endphp

@section('content')
<div class="ed-stack ed-reveal">
    @include('edata.attendance._clock')

    <div class="ed-figures" data-my-figures>
        <x-ed.figure :label="__('مهام مفتوحة')" :count="$openCount" :href="route('edata.tasks.index', ['state' => 'mine'])" />
        <x-ed.figure :label="__('مهام متأخرة')" :count="$overdueCount" :tone="$overdueCount ? 'bad' : null" :href="route('edata.tasks.index', ['state' => 'overdue'])" />
        <x-ed.figure :label="__('مهام أنجزتها')" :count="$doneCount" tone="good" />
        @if ($employee)
            <x-ed.figure :label="__('ساعات هذا الشهر')" :value="Labels::hours($monthTotals['seconds'])" :href="route('edata.me.attendance')" />
            <x-ed.figure :label="__('الالتزام بالحضور')" :value="$monthTotals['rate'] !== null ? $monthTotals['rate'] . '%' : '—'" />
        @endif
    </div>

    <div class="ed-me-grid">
        <div class="ed-stack">
            <section class="ed-panel">
                <div class="ed-panel__head">
                    <h2>{{ __('مهامي القادمة') }}</h2>
                    <a class="ed-link" href="{{ route('edata.tasks.index', ['state' => 'mine', 'view' => 'board']) }}">{{ __('لوحة مهامي') }}</a>
                </div>
                @if ($tasks->isEmpty())
                    <x-ed.empty icon="ti-checks" :title="__('لا توجد مهام مفتوحة لك الآن.')" />
                @else
                    <div class="ed-list" data-my-tasks>
                        @foreach ($tasks as $task)
                            <div class="ed-taskrow" data-task-row>
                                @if ($doneStage)
                                    <button type="button" class="ed-tick" data-task-done data-url="{{ route('edata.tasks.move', $task->id) }}" data-stage="{{ $doneStage }}" title="{{ __('تمييز كمنجزة') }}" aria-label="{{ __('تمييز كمنجزة') }}: {{ $task->name }}"><i class="ti ti-check"></i></button>
                                @endif
                                <a href="{{ route('edata.tasks.show', $task->id) }}">
                                    <div class="ed-list__main">
                                        <span class="ed-list__title">{{ $task->name }}</span>
                                        <span class="ed-list__sub">{{ $task->project->project_name ?? '' }}</span>
                                    </div>
                                    <x-ed.badge :pair="Labels::priority($task->priority)" />
                                    @if ($task->end_date)
                                        <span class="ed-due {{ TaskRules::isOverdue($task, $company) ? 'is-late' : '' }}"><i class="ti ti-calendar"></i>{{ Labels::date($task->end_date, 'j M') }}</span>
                                    @endif
                                </a>
                            </div>
                        @endforeach
                    </div>
                    <template id="edTasksClear"><x-ed.empty icon="ti-confetti" :title="__('خلصت كل مهامك هنا. عمل رائع.')" /></template>
                @endif
            </section>

            <section class="ed-panel">
                <div class="ed-panel__head"><h2>{{ __('مشاريعي الحالية') }}</h2></div>
                @if ($projects->isEmpty())
                    <x-ed.empty small icon="ti-briefcase" :title="__('لست ضمن فريق أي مشروع مفتوح.')" />
                @else
                    <div class="ed-list">
                        @foreach ($projects as $project)
                            <a href="{{ route('projects.show', $project->id) }}">
                                <div class="ed-list__main">
                                    <span class="ed-list__title">{{ $project->project_name }}</span>
                                    <span class="ed-list__sub">{{ __('التسليم') }}: {{ Labels::date($project->end_date) }}</span>
                                </div>
                                <x-ed.badge :pair="Labels::project($project->status)" />
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>
        </div>

        <div class="ed-stack">
            <section class="ed-panel">
                <div class="ed-panel__head"><h2>{{ __('رحلتك معنا') }}</h2></div>
                <div class="ed-journey">
                    @if ($journey['span'])
                        <div class="ed-journey__span">
                            {{ __('معنا منذ :span', ['span' => $journey['span']]) }}
                            <small>{{ __('من') }} {{ Labels::date($journey['since']) }}</small>
                        </div>
                    @endif
                    <div class="ed-journey__nums">
                        <div><b class="ed-num" data-count="{{ $journey['tasks'] }}">{{ number_format($journey['tasks']) }}</b><span>{{ __('مهمة أنجزتها') }}</span></div>
                        <div><b class="ed-num" data-count="{{ $journey['projects'] }}">{{ number_format($journey['projects']) }}</b><span>{{ __('مشروع شاركت فيه') }}</span></div>
                        <div><b class="ed-num" data-count="{{ $journey['hours'] }}">{{ number_format($journey['hours']) }}</b><span>{{ __('ساعة عمل') }}</span></div>
                    </div>
                    @if ($streak >= 2)
                        <div class="ed-journey__streak"><i class="ti ti-flame"></i><span>{{ __(':count يوم عمل متتالي في موعدك. استمر.', ['count' => $streak]) }}</span></div>
                    @endif
                    @if ($journey['milestone_next'])
                        <div class="ed-journey__next">
                            <span>{{ __('باقي :left على المهمة رقم :target', ['left' => $journey['milestone_left'], 'target' => $journey['milestone_next']]) }}</span>
                            <div class="ed-progress is-good"><i style="--v: {{ $journey['milestone_percent'] }}%"></i></div>
                        </div>
                    @endif
                </div>
            </section>

            @if ($employee)
                <section class="ed-panel">
                    <div class="ed-panel__head">
                        <h2>{{ __('شهرك') }}</h2>
                        <a class="ed-link" href="{{ route('edata.me.attendance') }}">{{ __('سجل حضوري') }}</a>
                    </div>
                    <div class="ed-month">
                        <div class="ed-month__grid">
                            @foreach ($weekDays as $weekDay)<div class="ed-month__dow">{{ $weekDay }}</div>@endforeach
                            @for ($blank = 0; $blank < $firstOfMonth->dayOfWeek; $blank++)<div></div>@endfor
                            @for ($d = $firstOfMonth->copy(); $d->lte($firstOfMonth->copy()->endOfMonth()); $d->addDay())
                                @php
                                    $row = $monthByDate[$d->toDateString()] ?? null;
                                    $isRest = !in_array($d->dayOfWeek, $rules['work_days'] ?? [], true);
                                    $class = $row ? ($dayClass[$row['status']] ?? '') : ($isRest ? 'is-rest' : '');
                                    $label = $row ? __(Labels::attendance($row['status'])[0]) : ($isRest ? __('راحة أسبوعية') : '');
                                @endphp
                                <div class="ed-month__day {{ $class }} {{ $d->isSameDay($nowInZone) ? 'is-today' : '' }} ed-num" title="{{ Labels::date($d->toDateString(), 'l، j F') }}{{ $label ? ': ' . $label : '' }}">{{ $d->day }}</div>
                            @endfor
                        </div>
                        <div class="ed-month__legend">
                            <span><i style="background:#6FAE00"></i>{{ __('حاضر') }}</span>
                            <span><i style="background:#FFE6A8"></i>{{ __('متأخر') }}</span>
                            <span><i style="background:#01485A"></i>{{ __('عمل إضافي') }}</span>
                            <span><i style="background:var(--info-bg)"></i>{{ __('إجازة') }}</span>
                            <span><i style="background:var(--bad-bg)"></i>{{ __('غائب') }}</span>
                        </div>
                    </div>
                </section>
            @endif

            <section class="ed-panel" id="kudos">
                <div class="ed-panel__head"><h2>{{ __('كلمة شكر') }}</h2></div>
                @if ($teammates->isNotEmpty())
                    <form class="ed-kudos__form" method="POST" action="{{ route('edata.kudos.store') }}" data-kudos>
                        @csrf
                        <select class="ed-select" name="to_user_id" required aria-label="{{ __('الزميل') }}">
                            <option value="">{{ __('لمن تريد أن تقول شكراً؟') }}</option>
                            @foreach ($teammates as $mate)
                                <option value="{{ $mate->id }}" @selected((int) old('to_user_id') === (int) $mate->id)>{{ $mate->name }}</option>
                            @endforeach
                        </select>
                        <div class="ed-kudos__presets">
                            @foreach ((array) config('edata_moments.kudos_presets') as $preset)
                                <button type="button" class="ed-kudos__preset" data-kudos-preset>{{ __($preset) }}</button>
                            @endforeach
                        </div>
                        <textarea class="ed-textarea" name="message" rows="2" maxlength="200" minlength="3" required placeholder="{{ __('اكتب كلمتك هنا') }}" aria-label="{{ __('كلمة الشكر') }}" style="min-height:62px">{{ old('message') }}</textarea>
                        @error('message')<span class="ed-error">{{ $message }}</span>@enderror
                        @error('to_user_id')<span class="ed-error">{{ $message }}</span>@enderror
                        <button class="ed-btn ed-btn--primary" type="submit"><i class="ti ti-heart"></i>{{ __('إرسال الشكر') }}</button>
                    </form>
                @endif
                @if ($kudos->isEmpty())
                    <x-ed.empty small icon="ti-heart" :title="__('كن أول من يشكر زميلاً هنا.')" />
                @else
                    <div>
                        @foreach ($kudos as $note)
                            <div class="ed-kudo {{ (int) $note->to_user_id === (int) auth()->id() ? 'is-mine' : '' }}">
                                <x-ed.avatar :user="$note->sender" />
                                <div style="min-width:0;flex:1">
                                    <div class="ed-kudo__line"><b>{{ $note->sender->name ?? '' }}</b> {{ __('إلى') }} <b>{{ $note->recipient->name ?? '' }}</b>، {{ $note->created_at?->locale(app()->getLocale())->diffForHumans() }}</div>
                                    <div class="ed-kudo__msg">{{ $note->message }}</div>
                                </div>
                                @if ((int) $note->from_user_id === (int) auth()->id() || \App\Support\Edata\Access::isOwner(auth()->user()))
                                    <form method="POST" action="{{ route('edata.kudos.destroy', $note->id) }}" data-confirm="{{ __('حذف كلمة الشكر؟') }}">
                                        @csrf @method('DELETE')
                                        <button class="ed-btn ed-btn--ghost ed-btn--sm" type="submit" aria-label="{{ __('حذف') }}"><i class="ti ti-x"></i></button>
                                    </form>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        </div>
    </div>
</div>
@endsection
