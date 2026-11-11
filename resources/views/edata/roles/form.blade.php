@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', $role->exists ? __('تعديل دور') : __('إضافة دور'))

@section('content')
<x-ed.head :title="$role->exists ? $role->name : __('إضافة دور')" :crumbs="[__('الأدوار والصلاحيات') => route('roles.index')]" />

<form class="ed-panel" method="POST" action="{{ $role->exists ? route('roles.update', $role->id) : route('roles.store') }}">
    @csrf
    @if ($role->exists) @method('PUT') @endif
    <div class="ed-panel__body" style="max-width:420px">
        <x-ed.field :label="__('اسم الدور')" name="name" required>
            <input class="ed-input @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $role->name) }}" required maxlength="100">
        </x-ed.field>
    </div>
    <div class="ed-table-wrap">
        <table class="ed-table ed-matrix">
            <thead>
                <tr>
                    <th>{{ __('الوحدة') }}</th>
                    @foreach ($actions as $label)<th>{{ __($label) }}</th>@endforeach
                    <th>{{ __('نطاق البيانات') }}</th>
                </tr>
            </thead>
            <tbody>
                @php $edGroupShown = false; @endphp
                @foreach ($modules as $key => $module)
                    @if (($module['group'] ?? null) === 'services' && !$edGroupShown)
                        @php $edGroupShown = true; @endphp
                        <tr class="ed-matrix__group"><th colspan="{{ count($actions) + 2 }}">{{ __('خدمات الموظفين') }}</th></tr>
                    @endif
                    <tr>
                        <td>{{ __($module['label']) }}</td>
                        @foreach ($actions as $action => $label)
                            <td>
                                @if (!empty($module[$action]))
                                    <input type="checkbox" name="actions[{{ $key }}][{{ $action }}]" value="1" aria-label="{{ __($label) }} {{ __($module['label']) }}"
                                        @checked(old("actions.$key.$action", $checked[$key][$action] ?? false))>
                                @else
                                    <span class="ed-hint">—</span>
                                @endif
                            </td>
                        @endforeach
                        <td>
                            @if (!empty($module['scoped']))
                                <select class="ed-select" name="scopes[{{ $key }}]" aria-label="{{ __('نطاق البيانات') }} {{ __($module['label']) }}">
                                    @foreach ($scopeOptions as $value => $label)
                                        <option value="{{ $value }}" @selected(old("scopes.$key", $scopes[$key] ?? ($key === 'invoices' ? 'all' : 'own')) === $value)>{{ __($label) }}</option>
                                    @endforeach
                                </select>
                            @else
                                <span class="ed-hint">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="ed-form__foot">
        <a class="ed-btn" href="{{ route('roles.index') }}">{{ __('إلغاء') }}</a>
        <button class="ed-btn ed-btn--primary" type="submit">{{ $role->exists ? __('حفظ الدور') : __('إنشاء الدور') }}</button>
    </div>
</form>
<p class="ed-hint" style="margin-top:10px">{{ __('تسجيل الحضور والانصراف وعرض المهام المسندة وقراءة الإعلانات متاحة لكل موظف ولا تحتاج صلاحية منفصلة.') }}</p>
@endsection
