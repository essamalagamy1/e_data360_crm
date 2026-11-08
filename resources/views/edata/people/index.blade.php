@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', __('الموظفون'))

@php use App\Support\Edata\Labels; @endphp

@section('content')
<x-ed.head :title="__('الموظفون والمستخدمون')" :sub="__('حساب مستقل لكل موظف، بصلاحيات يحددها دوره.')">
    @if ($canCreate)
        <a class="ed-btn ed-btn--primary" href="{{ route('edata.people.create') }}"><i class="ti ti-user-plus"></i>{{ __('إضافة موظف') }}</a>
    @endif
</x-ed.head>

<section class="ed-panel ed-reveal">
    <form class="ed-filters" method="GET" data-autosubmit>
        <div class="ed-search">
            <i class="ti ti-search" aria-hidden="true"></i>
            <input class="ed-input" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('ابحث بالاسم أو البريد') }}" aria-label="{{ __('ابحث بالاسم أو البريد') }}">
        </div>
        <select class="ed-select" name="role" aria-label="{{ __('الدور') }}">
            <option value="">{{ __('كل الأدوار') }}</option>
            @foreach ($roles as $role)<option value="{{ $role }}" @selected(request('role') === $role)>{{ $role }}</option>@endforeach
        </select>
        <select class="ed-select" name="department" aria-label="{{ __('القسم') }}">
            <option value="">{{ __('كل الأقسام') }}</option>
            @foreach ($departments as $d)<option value="{{ $d->id }}" @selected((int) request('department') === (int) $d->id)>{{ $d->name }}</option>@endforeach
        </select>
        <select class="ed-select" name="access" aria-label="{{ __('الدخول') }}">
            <option value="">{{ __('كل الحسابات') }}</option>
            <option value="on" @selected(request('access') === 'on')>{{ __('الدخول مفعّل') }}</option>
            <option value="off" @selected(request('access') === 'off')>{{ __('الدخول متوقف') }}</option>
        </select>
        @if (request()->hasAny(['q', 'role', 'department', 'access']))
            <a class="ed-btn ed-btn--ghost ed-btn--sm" href="{{ route('edata.people.index') }}">{{ __('مسح الفلاتر') }}</a>
        @endif
    </form>

    @if ($people->isEmpty())
        <x-ed.empty icon="ti-users" :title="__('لا يوجد موظفون يطابقون هذا البحث.')">
            @if ($canCreate)<a class="ed-btn ed-btn--primary" href="{{ route('edata.people.create') }}">{{ __('إضافة موظف') }}</a>@endif
        </x-ed.empty>
    @else
        <div class="ed-table-wrap">
            <table class="ed-table">
                <thead>
                    <tr><th>{{ __('الموظف') }}</th><th>{{ __('المسمى والقسم') }}</th><th>{{ __('الدور') }}</th><th>{{ __('اليوم') }}</th><th>{{ __('مهام مفتوحة') }}</th><th>{{ __('الدخول') }}</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($people as $person)
                        @php
                            $emp = $person->employee;
                            $today = $emp ? ($todayRows[$emp->id] ?? null) : null;
                        @endphp
                        <tr>
                            <td>
                                <a class="ed-person" href="{{ route('edata.people.show', $person->id) }}">
                                    <x-ed.avatar :user="$person" />
                                    <span style="min-width:0">
                                        <span class="ed-person__name">{{ $person->name }}</span>
                                        <span class="ed-person__sub">{{ $person->email }}</span>
                                    </span>
                                </a>
                            </td>
                            <td>
                                <span class="ed-table__title" style="font-weight:500">{{ $emp ? ($designationNames[$emp->designation_id] ?? '—') : '—' }}</span>
                                <span class="ed-table__sub">{{ $emp ? ($departmentNames[$emp->department_id] ?? '') : '' }}</span>
                            </td>
                            <td>{{ $person->type }}</td>
                            <td>
                                @if ($today)
                                    <x-ed.badge :pair="Labels::attendance($today['status'])" />
                                @else
                                    <span class="ed-hint">—</span>
                                @endif
                            </td>
                            <td class="ed-num">{{ $openTasks[$person->id] ?? 0 }}</td>
                            <td>
                                @if ($person->is_enable_login)
                                    <x-ed.badge tone="good" :text="__('مفعّل')" />
                                @else
                                    <x-ed.badge tone="muted" :text="__('متوقف')" />
                                @endif
                            </td>
                            <td class="is-end is-tight">
                                <span class="ed-actions">
                                    @if ($canEdit)
                                        <a class="ed-btn ed-btn--ghost ed-btn--sm" href="{{ route('edata.people.edit', $person->id) }}" aria-label="{{ __('تعديل') }}"><i class="ti ti-pencil"></i></a>
                                    @endif
                                    <a class="ed-btn ed-btn--ghost ed-btn--sm" href="{{ route('edata.people.show', $person->id) }}" aria-label="{{ __('عرض') }}"><i class="ti ti-eye"></i></a>
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <x-ed.pager :items="$people" />
    @endif
</section>
@endsection
