@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', $project->exists ? __('تعديل مشروع') : __('مشروع جديد'))

@php
    $start = $project->start_date ? \Carbon\Carbon::parse($project->start_date)->toDateString() : '';
    $end = $project->end_date ? \Carbon\Carbon::parse($project->end_date)->toDateString() : '';
    $selectedTeam = array_map('intval', (array) old('team', $team));
@endphp

@section('content')
<x-ed.head :title="$project->exists ? $project->project_name : __('مشروع جديد')" :crumbs="[__('المشاريع') => route('projects.index')]" />

<form class="ed-panel" method="POST" action="{{ $project->exists ? route('projects.update', $project->id) : route('projects.store') }}" style="max-width:980px">
    @csrf
    @if ($project->exists) @method('PUT') @endif
    <div class="ed-panel__body">
        <div class="ed-form">
            <x-ed.field :label="__('اسم المشروع')" name="project_name" required full>
                <input class="ed-input @error('project_name') is-invalid @enderror" id="project_name" name="project_name" value="{{ old('project_name', $project->project_name) }}" required maxlength="191">
            </x-ed.field>
            <x-ed.field :label="__('العميل')" name="customer_id">
                <select class="ed-select" id="customer_id" name="customer_id">
                    <option value="">{{ __('بدون عميل') }}</option>
                    @foreach ($customers as $c)
                        <option value="{{ $c->id }}" @selected((int) old('customer_id', $selectedCustomer) === (int) $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
            </x-ed.field>
            <x-ed.field :label="__('مدير المشروع')" name="manager_id">
                <select class="ed-select" id="manager_id" name="manager_id">
                    <option value="">{{ __('بدون مدير') }}</option>
                    @foreach ($staff as $s)
                        <option value="{{ $s->id }}" @selected((int) old('manager_id', $project->manager_id) === (int) $s->id)>{{ $s->name }}</option>
                    @endforeach
                </select>
            </x-ed.field>
            <x-ed.field :label="__('تاريخ البداية')" name="start_date">
                <input class="ed-input @error('start_date') is-invalid @enderror" type="date" id="start_date" name="start_date" value="{{ old('start_date', $start) }}">
            </x-ed.field>
            <x-ed.field :label="__('تاريخ التسليم المتوقع')" name="end_date">
                <input class="ed-input @error('end_date') is-invalid @enderror" type="date" id="end_date" name="end_date" value="{{ old('end_date', $end) }}">
            </x-ed.field>
            <x-ed.field :label="__('الحالة')" name="status" required>
                <select class="ed-select" id="status" name="status" required>
                    @foreach ($statuses as $value => $pair)
                        <option value="{{ $value }}" @selected(old('status', $project->status) === $value)>{{ __($pair[0]) }}</option>
                    @endforeach
                </select>
            </x-ed.field>
            <x-ed.field :label="__('قيمة المشروع')" name="budget">
                <input class="ed-input" type="number" min="0" step="1" id="budget" name="budget" value="{{ old('budget', $project->budget) }}" dir="ltr">
            </x-ed.field>
            <x-ed.field :label="__('نسبة الإنجاز')" name="manual_progress" :hint="__('اتركها فارغة لتُحسب تلقائياً من المهام المكتملة.')">
                <input class="ed-input" type="number" min="0" max="100" id="manual_progress" name="manual_progress" value="{{ old('manual_progress', $project->manual_progress) }}" dir="ltr">
            </x-ed.field>
            <x-ed.field :label="__('أعضاء الفريق')" name="team" full>
                <div class="ed-chips">
                    @foreach ($staff as $s)
                        <label class="ed-chip"><input type="checkbox" name="team[]" value="{{ $s->id }}" @checked(in_array((int) $s->id, $selectedTeam, true))>{{ $s->name }}</label>
                    @endforeach
                </div>
            </x-ed.field>
            <x-ed.field :label="__('وصف المشروع')" name="description" full>
                <textarea class="ed-textarea" id="description" name="description" rows="4">{{ old('description', $project->description) }}</textarea>
            </x-ed.field>
        </div>
    </div>
    <div class="ed-form__foot">
        <a class="ed-btn" href="{{ $project->exists ? route('projects.show', $project->id) : route('projects.index') }}">{{ __('إلغاء') }}</a>
        <button class="ed-btn ed-btn--primary" type="submit">{{ $project->exists ? __('حفظ التعديلات') : __('إنشاء المشروع') }}</button>
    </div>
</form>
@endsection
