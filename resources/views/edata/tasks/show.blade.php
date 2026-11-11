@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', $task->name)

@php
    use App\Support\Edata\Labels;
    use App\Support\Edata\TaskRules;
    $isLate = TaskRules::isOverdue($task, $company);
    $edMeId = (int) auth()->id();
    $edTimerOk = \Illuminate\Support\Facades\Schema::hasTable('edata_task_timers');
    $edCanTime = $edTimerOk && (auth()->user()->can('edit project task') || in_array($edMeId, TaskRules::assigneeIds($task), true));
    $edRunning = $edTimerOk ? \App\Support\Edata\EmployeeServices::runningTimer($edMeId) : null;
    $edRunningHere = $edRunning && (int) $edRunning->task_id === (int) $task->id;
    $edTaskSeconds = $edTimerOk ? (\App\Support\Edata\EmployeeServices::taskSeconds([$task->id])[$task->id] ?? 0) : 0;
@endphp

@section('content')
<x-ed.head :title="$task->name" :crumbs="[__('المهام') => route('edata.tasks.index')]">
    @if ($canEdit)
        <a class="ed-btn ed-btn--primary" href="{{ route('edata.tasks.edit', $task->id) }}"><i class="ti ti-pencil"></i>{{ __('تعديل') }}</a>
        <form method="POST" action="{{ route('edata.tasks.cancel', $task->id) }}"
              data-confirm="{{ $task->cancelled_at ? __('إعادة فتح هذه المهمة؟') : __('إلغاء هذه المهمة؟') }}"
              data-confirm-text="{{ __('تبقى المهمة وسجلها محفوظين.') }}"
              @if ($task->cancelled_at) data-confirm-safe @endif>
            @csrf
            <button class="ed-btn" type="submit">{{ $task->cancelled_at ? __('إعادة الفتح') : __('إلغاء المهمة') }}</button>
        </form>
    @endif
    @if ($canDelete)
        <form method="POST" action="{{ route('edata.tasks.destroy', $task->id) }}" data-confirm="{{ __('حذف هذه المهمة نهائياً؟') }}" data-confirm-text="{{ __('يحذف أيضاً ملاحظاتها ومرفقاتها. الإلغاء أفضل لو كنت تريد الاحتفاظ بسجلها.') }}">
            @csrf @method('DELETE')
            <button class="ed-btn ed-btn--danger" type="submit" aria-label="{{ __('حذف') }}"><i class="ti ti-trash"></i></button>
        </form>
    @endif
</x-ed.head>

<div class="ed-grid ed-grid--side ed-reveal">
    <div class="ed-stack">
        <section class="ed-panel">
            <div class="ed-panel__head"><h2>{{ __('الوصف') }}</h2></div>
            <div class="ed-panel__body" style="white-space:pre-wrap;overflow-wrap:anywhere">{{ strip_tags((string) $task->description) ?: __('لا يوجد وصف.') }}</div>
        </section>

        @if ($checklist->isNotEmpty())
            <section class="ed-panel">
                <div class="ed-panel__head"><h2>{{ __('قائمة التحقق') }}</h2><span class="ed-hint ed-num">{{ $checklist->where('status', 1)->count() }}/{{ $checklist->count() }}</span></div>
                <div class="ed-list">
                    @foreach ($checklist as $item)
                        <div>
                            <i class="ti {{ $item->status ? 'ti-circle-check' : 'ti-circle' }}" style="font-size:19px;color:{{ $item->status ? 'var(--leaf)' : 'var(--text-3)' }}"></i>
                            <div class="ed-list__main">{{ $item->name }}</div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="ed-panel">
            <div class="ed-panel__head"><h2>{{ __('الملاحظات') }}</h2></div>
            <form method="POST" action="{{ route('edata.tasks.comment', $task->id) }}" style="padding:14px 18px;border-bottom:1px solid var(--line);display:grid;gap:8px">
                @csrf
                <textarea class="ed-textarea" name="comment" rows="2" required maxlength="3000" placeholder="{{ __('اكتب تحديثاً أو ملاحظة...') }}" aria-label="{{ __('ملاحظة') }}"></textarea>
                <div><button class="ed-btn ed-btn--primary ed-btn--sm" type="submit">{{ __('إضافة ملاحظة') }}</button></div>
            </form>
            @forelse ($comments as $comment)
                <div class="ed-note">
                    <div class="ed-note__meta">
                        <span>{{ $commenters[$comment->user_id]->name ?? __('مستخدم') }}، {{ $comment->created_at?->diffForHumans() }}</span>
                    </div>
                    <div class="ed-note__body">{{ strip_tags((string) $comment->comment) }}</div>
                </div>
            @empty
                <x-ed.empty small icon="ti-message-2" :title="__('لا توجد ملاحظات بعد.')" />
            @endforelse
        </section>

        <section class="ed-panel">
            <div class="ed-panel__head"><h2>{{ __('سجل التغييرات') }}</h2></div>
            <x-ed.trail :logs="$history" />
        </section>
    </div>

    <div class="ed-stack">
        <section class="ed-panel">
            <div class="ed-panel__head"><h2>{{ __('الحالة') }}</h2><x-ed.badge :pair="$state" /></div>
            <div class="ed-panel__body ed-stack">
                @if ($canMove && !$task->cancelled_at)
                    <form method="POST" action="{{ route('edata.tasks.move', $task->id) }}">
                        @csrf
                        <x-ed.field :label="__('تغيير الحالة')" for="moveStage">
                            <select class="ed-select" id="moveStage" name="stage_id" onchange="this.form.submit()">
                                @foreach ($stages as $s)
                                    <option value="{{ $s->id }}" @selected((int) $task->stage_id === (int) $s->id)>{{ __(TaskRules::stageName($s->name)) }}</option>
                                @endforeach
                            </select>
                        </x-ed.field>
                    </form>
                    <form method="POST" action="{{ route('edata.tasks.progress', $task->id) }}">
                        @csrf
                        <x-ed.field :label="__('نسبة الإنجاز')" for="progressRange">
                            <input type="range" id="progressRange" name="progress" min="0" max="100" step="5" value="{{ (int) $task->progress }}" style="width:100%;accent-color:var(--navy)" oninput="document.getElementById('progressOut').textContent=this.value+'%'">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px">
                                <b class="ed-num" id="progressOut">{{ (int) $task->progress }}%</b>
                                <button class="ed-btn ed-btn--sm" type="submit">{{ __('حفظ النسبة') }}</button>
                            </div>
                        </x-ed.field>
                    </form>
                @else
                    <x-ed.progress :value="(int) $task->progress" :late="$isLate" />
                @endif
            </div>
        </section>

        @if ($edCanTime || $edTaskSeconds > 0)
            <section class="ed-panel">
                <div class="ed-panel__head"><h2>{{ __('مؤقت المهمة') }}</h2><span class="ed-num ed-hint" title="{{ __('مجموع الوقت المسجّل على المهمة من كل الفريق') }}">{{ Labels::hours($edTaskSeconds) }}</span></div>
                <div class="ed-panel__body">
                    @if ($edCanTime)
                        @if ($edRunningHere)
                            <div class="ed-timerbar" style="margin:0 0 10px">
                                <span class="ed-timerbar__dot" aria-hidden="true"></span>
                                <div class="ed-timerbar__main"><span>{{ __('يعمل الآن') }}</span></div>
                                <b class="ed-num ed-timerbar__time" data-timer-since="{{ \Carbon\Carbon::parse($edRunning->started_at, 'UTC')->getTimestamp() }}">00:00:00</b>
                            </div>
                        @endif
                        <form method="POST" action="{{ $edRunningHere ? route('edata.timer.stop') : route('edata.timer.start', $task->id) }}">
                            @csrf
                            <button type="submit" class="ed-btn {{ $edRunningHere ? '' : 'ed-btn--primary' }}" style="width:100%"><i class="ti {{ $edRunningHere ? 'ti-player-stop' : 'ti-player-play' }}"></i>{{ $edRunningHere ? __('إيقاف المؤقت') : __('بدء المؤقت') }}</button>
                        </form>
                        @if ($edRunning && !$edRunningHere)<p class="ed-hint" style="margin-top:8px">{{ __('البدء هنا يوقف المؤقت الذي يعمل على مهمة أخرى.') }}</p>@endif
                    @endif
                </div>
            </section>
        @endif

        <section class="ed-panel">
            <div class="ed-panel__head"><h2>{{ __('التفاصيل') }}</h2></div>
            <div class="ed-panel__body">
                <dl class="ed-facts">
                    <dt>{{ __('المشروع') }}</dt><dd>@if ($task->project)<a class="ed-link" href="{{ route('projects.show', $task->project_id) }}">{{ $task->project->project_name }}</a>@else — @endif</dd>
                    <dt>{{ __('الأولوية') }}</dt><dd><x-ed.badge :pair="Labels::priority($task->priority)" /></dd>
                    <dt>{{ __('البداية') }}</dt><dd>{{ Labels::date($task->start_date) }}</dd>
                    <dt>{{ __('التسليم') }}</dt><dd class="{{ $isLate ? 'tone-text-bad' : '' }}">{{ Labels::date($task->end_date) }}</dd>
                    <dt>{{ __('الساعات المقدرة') }}</dt><dd class="ed-num">{{ $task->estimated_hrs ?: '—' }}</dd>
                    <dt>{{ __('تاريخ الإنشاء') }}</dt><dd>{{ Labels::date($task->created_at) }}</dd>
                    <dt>{{ __('آخر تحديث') }}</dt><dd>{{ $task->updated_at?->diffForHumans() }}</dd>
                </dl>
            </div>
        </section>

        <section class="ed-panel">
            <div class="ed-panel__head"><h2>{{ __('المسؤولون') }}</h2></div>
            @if ($assignees->isEmpty())
                <x-ed.empty small icon="ti-user-x" :title="__('لم تُسند لأحد بعد.')" />
            @else
                <div class="ed-list">
                    @foreach ($assignees as $person)
                        <a href="{{ route('edata.people.show', $person->id) }}"><x-ed.avatar :user="$person" /><div class="ed-list__main"><span class="ed-list__title">{{ $person->name }}</span></div></a>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</div>
@endsection
