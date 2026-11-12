@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', $person->exists ? __('تعديل موظف') : __('إضافة موظف'))

@section('content')
<x-ed.head :title="$person->exists ? $person->name : __('إضافة موظف')" :crumbs="[__('الموظفون') => route('edata.people.index')]" />

<div class="ed-grid ed-grid--side">
    <form class="ed-panel" method="POST" enctype="multipart/form-data" action="{{ $person->exists ? route('edata.people.update', $person->id) : route('edata.people.store') }}">
        @csrf
        @if ($person->exists) @method('PUT') @endif
        <div class="ed-panel__body">
            <div class="ed-form">
                <x-ed.field :label="__('الاسم الكامل')" name="name" required>
                    <input class="ed-input @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $person->name) }}" required maxlength="120">
                </x-ed.field>
                <x-ed.field :label="__('البريد الإلكتروني')" name="email" required>
                    <input class="ed-input @error('email') is-invalid @enderror" type="email" id="email" name="email" value="{{ old('email', $person->email) }}" required dir="ltr">
                </x-ed.field>
                <x-ed.field :label="__('رقم الهاتف')" name="phone">
                    <input class="ed-input @error('phone') is-invalid @enderror" id="phone" name="phone" value="{{ old('phone', $employee->phone ?? '') }}" dir="ltr">
                </x-ed.field>
                <x-ed.field :label="__('الدور')" name="role" required :hint="__('يحدد ما يمكن لهذا الموظف رؤيته وعمله.')">
                    <select class="ed-select @error('role') is-invalid @enderror" id="role" name="role" required>
                        <option value="">{{ __('اختر دوراً') }}</option>
                        @foreach ($roles as $role)
                            <option value="{{ $role->id }}" @selected((int) old('role', $currentRole) === (int) $role->id)>{{ $role->name }}</option>
                        @endforeach
                    </select>
                </x-ed.field>
                <x-ed.field :label="__('القسم')" name="department_id">
                    <select class="ed-select" id="department_id" name="department_id">
                        <option value="">{{ __('بدون قسم') }}</option>
                        @foreach ($departments as $d)
                            <option value="{{ $d->id }}" @selected((int) old('department_id', $employee->department_id ?? 0) === (int) $d->id)>{{ $d->name }}</option>
                        @endforeach
                    </select>
                </x-ed.field>
                <x-ed.field :label="__('المسمى الوظيفي')" name="designation_id">
                    <select class="ed-select" id="designation_id" name="designation_id">
                        <option value="">{{ __('بدون مسمى') }}</option>
                        @foreach ($designations as $d)
                            <option value="{{ $d->id }}" @selected((int) old('designation_id', $employee->designation_id ?? 0) === (int) $d->id)>{{ $d->name }}</option>
                        @endforeach
                    </select>
                </x-ed.field>
                <x-ed.field :label="__('تاريخ التعيين')" name="company_doj">
                    <input class="ed-input" type="date" id="company_doj" name="company_doj" value="{{ old('company_doj', $employee->company_doj ?? '') }}">
                </x-ed.field>
                <x-ed.field :label="__('الحالة الوظيفية')" name="employment_status" required>
                    <select class="ed-select" id="employment_status" name="employment_status" required>
                        @foreach ($statuses as $value => $pair)
                            <option value="{{ $value }}" @selected(old('employment_status', $employee->employment_status ?? 'active') === $value)>{{ __($pair[0]) }}</option>
                        @endforeach
                    </select>
                </x-ed.field>
                @php
                    $edOwnDays = \App\Support\Edata\AttendanceService::ownDays($employee);
                    $edDays = array_map('intval', (array) old('work_days', $edOwnDays ?? []));
                    $edType = old('work_type', $edOwnDays ? 'part' : 'full');
                @endphp
                <x-ed.field :label="__('نوع الدوام')" name="work_type" full :hint="__('الدوام الكامل يتبع أيام عمل الشركة. في الدوام الجزئي يُحسب الحضور والغياب في الأيام المختارة فقط.')">
                    <div class="ed-worktype">
                        <div class="ed-chips">
                            <label class="ed-chip"><input type="radio" name="work_type" value="full" @checked($edType === 'full')>{{ __('دوام كامل') }}</label>
                            <label class="ed-chip"><input type="radio" name="work_type" value="part" @checked($edType === 'part')>{{ __('دوام جزئي') }}</label>
                        </div>
                        <div class="ed-worktype__days">
                            <span class="ed-hint">{{ __('أيام الحضور') }}</span>
                            <div class="ed-chips">
                                @foreach (\App\Support\Edata\Labels::WEEKDAYS as $value => $label)
                                    <label class="ed-chip"><input type="checkbox" name="work_days[]" value="{{ $value }}" @checked(in_array($value, $edDays, true))>{{ __($label) }}</label>
                                @endforeach
                            </div>
                            @error('work_days')<span class="ed-error" role="alert">{{ $message }}</span>@enderror
                        </div>
                    </div>
                </x-ed.field>
                <x-ed.field :label="__('الصورة الشخصية')" name="avatar" full :hint="__('JPG أو PNG أو WEBP، حتى 2 ميجابايت.')">
                    <input class="ed-input" style="padding-block:8px" type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/webp">
                </x-ed.field>
                @unless ($person->exists)
                    <x-ed.field :label="__('كلمة المرور')" name="password" :hint="__('8 أحرف على الأقل. اتركها فارغة لو لن يدخل النظام الآن.')">
                        <input class="ed-input @error('password') is-invalid @enderror" type="password" id="password" name="password" autocomplete="new-password" dir="ltr">
                    </x-ed.field>
                    <x-ed.field :label="__('تأكيد كلمة المرور')" for="password_confirmation">
                        <input class="ed-input" type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" dir="ltr">
                    </x-ed.field>
                @endunless
            </div>
        </div>
        <div class="ed-form__foot">
            <a class="ed-btn" href="{{ $person->exists ? route('edata.people.show', $person->id) : route('edata.people.index') }}">{{ __('إلغاء') }}</a>
            <button class="ed-btn ed-btn--primary" type="submit">{{ $person->exists ? __('حفظ التعديلات') : __('إضافة الموظف') }}</button>
        </div>
    </form>

    @if ($person->exists)
        <div class="ed-stack">
            <form class="ed-panel" method="POST" action="{{ route('edata.people.password', $person->id) }}">
                @csrf
                <div class="ed-panel__head"><h2>{{ __('تغيير كلمة المرور') }}</h2></div>
                <div class="ed-panel__body ed-stack">
                    <x-ed.field :label="__('كلمة المرور الجديدة')" name="password" required>
                        <input class="ed-input" type="password" id="password" name="password" autocomplete="new-password" required minlength="8" dir="ltr">
                    </x-ed.field>
                    <x-ed.field :label="__('تأكيد كلمة المرور')" for="new_confirm">
                        <input class="ed-input" type="password" id="new_confirm" name="password_confirmation" autocomplete="new-password" required dir="ltr">
                    </x-ed.field>
                    <button class="ed-btn" type="submit">{{ __('تغيير كلمة المرور') }}</button>
                </div>
            </form>
            <section class="ed-panel">
                <div class="ed-panel__head"><h2>{{ __('الدخول للنظام') }}</h2></div>
                <div class="ed-panel__body ed-stack">
                    <p class="ed-hint">{{ $person->is_enable_login ? __('يستطيع هذا الموظف الدخول الآن. الإيقاف لا يمس أي بيانات.') : __('الدخول متوقف. بياناته وسجلاته كلها محفوظة.') }}</p>
                    <form method="POST" action="{{ route('edata.people.access', $person->id) }}"
                          data-confirm="{{ $person->is_enable_login ? __('إيقاف دخول هذا الموظف؟') : __('تفعيل دخول هذا الموظف؟') }}"
                          @unless ($person->is_enable_login) data-confirm-safe @endunless>
                        @csrf
                        <button class="ed-btn {{ $person->is_enable_login ? 'ed-btn--danger' : '' }}" type="submit" style="width:100%">{{ $person->is_enable_login ? __('إيقاف الدخول') : __('تفعيل الدخول') }}</button>
                    </form>
                </div>
            </section>
        </div>
    @endif
</div>
@endsection
