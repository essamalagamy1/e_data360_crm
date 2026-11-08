@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', $task->exists ? __('تعديل مهمة') : __('مهمة جديدة'))

@php
    use App\Support\Edata\TaskRules;
    $start = $task->start_date ? \Carbon\Carbon::parse($task->start_date)->toDateString() : '';
    $end = $task->end_date ? \Carbon\Carbon::parse($task->end_date)->toDateString() : '';
    $selected = array_map('intval', (array) old('assign_to', $assigned));
@endphp

@section('content')
<x-ed.head :title="$task->exists ? $task->name : __('مهمة جديدة')" :crumbs="[__('المهام') => route('edata.tasks.index')]" />

<form class="ed-panel" method="POST" action="{{ $task->exists ? route('edata.tasks.update', $task->id) : route('edata.tasks.store') }}" style="max-width:920px">
    @csrf
    @if ($task->exists) @method('PUT') @endif
    <div class="ed-panel__body">
        <div class="ed-form">
            <x-ed.field :label="__('اسم المهمة')" name="name" required full>
                <input class="ed-input @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $task->name) }}" required maxlength="191">
            </x-ed.field>
            <x-ed.field :label="__('المشروع')" name="project_id" required>
                <select class="ed-select @error('project_id') is-invalid @enderror" id="project_id" name="project_id" required>
                    <option value="">{{ __('اختر مشروعاً') }}</option>
                    @foreach ($projects as $p)
                        <option value="{{ $p->id }}" @selected((int) old('project_id', $task->project_id) === (int) $p->id)>{{ $p->project_name }}</option>
                    @endforeach
                </select>
            </x-ed.field>
            <x-ed.field :label="__('الأولوية')" name="priority" required>
                <select class="ed-select" id="priority" name="priority" required>
                    @foreach ($priorities as $value => $pair)
                        <option value="{{ $value }}" @selected(old('priority', $task->priority ?: 'medium') === $value)>{{ __($pair[0]) }}</option>
                    @endforeach
                </select>
            </x-ed.field>
            <x-ed.field :label="__('تاريخ البداية')" name="start_date">
                <input class="ed-input" type="date" id="start_date" name="start_date" value="{{ old('start_date', $start) }}">
            </x-ed.field>
            <x-ed.field :label="__('تاريخ التسليم')" name="end_date">
                <input class="ed-input @error('end_date') is-invalid @enderror" type="date" id="end_date" name="end_date" value="{{ old('end_date', $end) }}">
            </x-ed.field>
            <x-ed.field :label="__('الحالة')" name="stage_id">
                <select class="ed-select" id="stage_id" name="stage_id">
                    @foreach ($stages as $s)
                        <option value="{{ $s->id }}" @selected((int) old('stage_id', $task->stage_id) === (int) $s->id)>{{ __(TaskRules::stageName($s->name)) }}</option>
                    @endforeach
                </select>
            </x-ed.field>
            <x-ed.field :label="__('الساعات المقدرة')" name="estimated_hrs">
                <input class="ed-input" type="number" min="0" id="estimated_hrs" name="estimated_hrs" value="{{ old('estimated_hrs', $task->estimated_hrs) }}" dir="ltr">
            </x-ed.field>
            <x-ed.field :label="__('الموظف المسؤول')" name="assign_to" full :hint="__('يصله إشعار فور الإسناد.')">
                <div class="ed-chips">
                    @foreach ($staff as $s)
                        <label class="ed-chip"><input type="checkbox" name="assign_to[]" value="{{ $s->id }}" @checked(in_array((int) $s->id, $selected, true))>{{ $s->name }}</label>
                    @endforeach
                </div>
            </x-ed.field>
            <x-ed.field :label="__('الوصف')" name="description" full>
                <textarea class="ed-textarea" id="description" name="description" rows="4">{{ old('description', $task->description) }}</textarea>
            </x-ed.field>
        </div>
    </div>
    <div class="ed-form__foot">
        <a class="ed-btn" href="{{ $task->exists ? route('edata.tasks.show', $task->id) : route('edata.tasks.index') }}">{{ __('إلغاء') }}</a>
        <button class="ed-btn ed-btn--primary" type="submit">{{ $task->exists ? __('حفظ التعديلات') : __('إنشاء المهمة') }}</button>
    </div>
</form>
@endsection
