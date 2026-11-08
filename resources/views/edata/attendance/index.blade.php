@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', __('الحضور والانصراف'))

@php
    use App\Support\Edata\Labels;
    $weekdays = [0 => 'الأحد', 1 => 'الاثنين', 2 => 'الثلاثاء', 3 => 'الأربعاء', 4 => 'الخميس', 5 => 'الجمعة', 6 => 'السبت'];
    $hasFilter = request()->hasAny(['q', 'employee', 'status']) || request('from') || request('to');
@endphp

@section('content')
<x-ed.head :title="__('الحضور والانصراف')" :sub="__('من يعمل الآن، من تأخر ومن غاب، وكم ساعة عمل كل موظف.')">
    @if ($canConfigure)
        <button type="button" class="ed-btn" onclick="document.getElementById('dlgRules').showModal()"><i class="ti ti-adjustments"></i>{{ __('قواعد الدوام') }}</button>
    @endif
    @if ($canCreate)
        <button type="button" class="ed-btn" onclick="document.getElementById('dlgAdd').showModal()"><i class="ti ti-plus"></i>{{ __('إضافة سجل') }}</button>
    @endif
    <a class="ed-btn ed-btn--primary" href="{{ route('edata.attendance.export', request()->query()) }}"><i class="ti ti-file-export"></i>{{ __('تصدير Excel') }}</a>
</x-ed.head>

<div class="ed-stack ed-reveal">
    <section class="ed-panel">
        <div class="ed-panel__head">
            <div>
                <h2>{{ __('الفريق اليوم') }}</h2>
                <p>{{ count($board['working']) }} {{ __('يعمل الآن') }}، {{ $board['present'] }} {{ __('حضر') }}، {{ count($board['late']) }} {{ __('متأخر') }}، {{ count($board['absent']) }} {{ __('غائب') }}</p>
            </div>
        </div>
        @if ($board['days']->isEmpty())
            <x-ed.empty small icon="ti-users" :title="__('لا يوجد موظفون لعرضهم.')" />
        @else
            <div class="ed-roster">
                @foreach ($board['days'] as $day)
                    <a class="ed-roster__item" href="{{ route('edata.attendance.index', ['employee' => $day['employee_id']]) }}">
                        <x-ed.avatar :name="$day['employee_name']" />
                        <div style="min-width:0;flex:1">
                            <span class="ed-person__name">{{ $day['employee_name'] }}</span>
                            <span class="ed-person__sub ed-num">
                                @if ($day['in']) {{ $day['in'] }} @if ($day['out']) - {{ $day['out'] }} @endif @else — @endif
                            </span>
                        </div>
                        <x-ed.badge :pair="Labels::attendance($day['status'])" />
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    <div class="ed-figures">
        <x-ed.figure :label="__('إجمالي الساعات')" :value="Labels::hours($totals['seconds'])" />
        <x-ed.figure :label="__('الالتزام بالحضور')" :value="$totals['rate'] !== null ? $totals['rate'] . '%' : '—'" />
        <x-ed.figure :label="__('حالات التأخير')" :count="$totals['late']" :tone="$totals['late'] ? 'warn' : null" />
        <x-ed.figure :label="__('أيام الغياب')" :count="$totals['absent']" :tone="$totals['absent'] ? 'bad' : null" />
        <x-ed.figure :label="__('لم يسجل الانصراف')" :count="$totals['missing_out']" :tone="$totals['missing_out'] ? 'bad' : null" />
        <x-ed.figure :label="__('ساعات إضافية')" :value="Labels::hours($totals['overtime_seconds'])" />
    </div>

    <section class="ed-panel">
        <form class="ed-filters" method="GET" data-autosubmit>
            <div class="ed-search">
                <i class="ti ti-search" aria-hidden="true"></i>
                <input class="ed-input" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('ابحث باسم الموظف') }}" aria-label="{{ __('ابحث باسم الموظف') }}">
            </div>
            <select class="ed-select" name="employee" aria-label="{{ __('الموظف') }}">
                <option value="">{{ __('كل الموظفين') }}</option>
                @foreach ($allEmployees as $emp)
                    <option value="{{ $emp->id }}" @selected((int) request('employee') === (int) $emp->id)>{{ $emp->name }}</option>
                @endforeach
            </select>
            <select class="ed-select" name="status" aria-label="{{ __('الحالة') }}">
                <option value="">{{ __('كل الحالات') }}</option>
                @foreach (['present', 'late', 'absent', 'leave', 'overtime', 'missing_out', 'working'] as $s)
                    <option value="{{ $s }}" @selected(request('status') === $s)>{{ __(Labels::attendance($s)[0]) }}</option>
                @endforeach
            </select>
            <input class="ed-input" type="date" name="from" value="{{ $from }}" aria-label="{{ __('من تاريخ') }}">
            <input class="ed-input" type="date" name="to" value="{{ $to }}" aria-label="{{ __('إلى تاريخ') }}">
            @if ($hasFilter)
                <a class="ed-btn ed-btn--ghost ed-btn--sm" href="{{ route('edata.attendance.index') }}">{{ __('مسح الفلاتر') }}</a>
            @endif
        </form>

        @if ($paged->isEmpty())
            <x-ed.empty icon="ti-calendar-off" :title="__('لا توجد سجلات تطابق هذا البحث.')" :text="__('جرّب فترة أوسع أو امسح الفلاتر.')" />
        @else
            <div class="ed-table-wrap">
                <table class="ed-table">
                    <thead>
                        <tr>
                            <th>{{ __('التاريخ') }}</th>
                            <th>{{ __('الموظف') }}</th>
                            <th>{{ __('وقت الدخول') }}</th>
                            <th>{{ __('وقت الخروج') }}</th>
                            <th>{{ __('ساعات العمل') }}</th>
                            <th>{{ __('الحالة') }}</th>
                            @if ($canEdit || $canDelete)<th class="is-end"><span class="visually-hidden">{{ __('إجراءات') }}</span></th>@endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($paged as $day)
                            <tr>
                                <td class="is-tight">{{ Labels::date($day['date'], 'D، j M') }}</td>
                                <td>
                                    <div class="ed-person">
                                        <x-ed.avatar :name="$day['employee_name']" size="sm" />
                                        <a class="ed-person__name" href="{{ route('edata.people.show', $day['user_id']) }}">{{ $day['employee_name'] }}</a>
                                    </div>
                                </td>
                                <td class="ed-num">{{ $day['in'] ?? '—' }}</td>
                                <td class="ed-num">{{ $day['out'] ?? '—' }}</td>
                                <td class="ed-num">
                                    {{ $day['seconds'] ? Labels::hours($day['seconds']) : '—' }}
                                    @if ($day['overtime_seconds'] > 0)<span class="ed-table__sub">+{{ Labels::hours($day['overtime_seconds']) }} {{ __('إضافي') }}</span>@endif
                                </td>
                                <td>
                                    <x-ed.badge :pair="Labels::attendance($day['status'])" />
                                    @foreach ($day['flags'] as $flag)
                                        @if ($flag !== $day['status'])<x-ed.badge :pair="Labels::attendance($flag)" />@endif
                                    @endforeach
                                    @if ($day['late_minutes'] > 0)<span class="ed-table__sub">{{ $day['late_minutes'] }} {{ __('دقيقة تأخير') }}</span>@endif
                                </td>
                                @if ($canEdit || $canDelete)
                                    <td class="is-end is-tight">
                                        @foreach ($day['sessions'] as $session)
                                            <span class="ed-actions">
                                                @if ($canEdit)
                                                    <button type="button" class="ed-btn ed-btn--ghost ed-btn--sm" aria-label="{{ __('تعديل') }}"
                                                        data-edit-row="{{ route('edata.attendance.update', $session->id) }}"
                                                        data-in="{{ substr((string) $session->clock_in, 0, 5) }}"
                                                        data-out="{{ $session->clock_out !== '00:00:00' ? substr((string) $session->clock_out, 0, 5) : '' }}"
                                                        data-title="{{ $day['employee_name'] }} - {{ Labels::date($day['date']) }}"><i class="ti ti-pencil"></i></button>
                                                @endif
                                                @if ($canDelete)
                                                    <form method="POST" action="{{ route('edata.attendance.destroy', $session->id) }}" data-confirm="{{ __('حذف هذا السجل؟') }}" data-confirm-text="{{ __('لا يمكن التراجع عن الحذف، ويبقى أثره في سجل الأنشطة.') }}" data-confirm-reason data-confirm-yes="{{ __('حذف السجل') }}">
                                                        @csrf @method('DELETE')
                                                        <button class="ed-btn ed-btn--ghost ed-btn--sm" type="submit" aria-label="{{ __('حذف') }}"><i class="ti ti-trash"></i></button>
                                                    </form>
                                                @endif
                                            </span>
                                        @endforeach
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-ed.pager :items="$paged" />
        @endif
    </section>

    <section class="ed-panel">
        <div class="ed-panel__head">
            <div>
                <h2>{{ __('ملخص كل موظف') }}</h2>
                <p>{{ Labels::date($from) }} - {{ Labels::date($to) }}</p>
            </div>
        </div>
        @if ($perEmployee->isEmpty())
            <x-ed.empty small icon="ti-users" :title="__('لا توجد بيانات في هذه الفترة.')" />
        @else
            <div class="ed-table-wrap">
                <table class="ed-table">
                    <thead><tr><th>{{ __('الموظف') }}</th><th>{{ __('ساعات العمل') }}</th><th>{{ __('أيام الحضور') }}</th><th>{{ __('غياب') }}</th><th>{{ __('تأخير') }}</th><th>{{ __('إجازة') }}</th><th>{{ __('إضافي') }}</th><th>{{ __('الالتزام') }}</th></tr></thead>
                    <tbody>
                        @foreach ($perEmployee as $row)
                            <tr>
                                <td><a class="ed-person__name" href="{{ route('edata.people.show', $row['user_id']) }}">{{ $row['name'] }}</a></td>
                                <td class="ed-num">{{ Labels::hours($row['totals']['seconds']) }}</td>
                                <td class="ed-num">{{ $row['totals']['attended'] }}</td>
                                <td class="ed-num {{ $row['totals']['absent'] ? 'tone-text-bad' : '' }}">{{ $row['totals']['absent'] }}</td>
                                <td class="ed-num {{ $row['totals']['late'] ? 'tone-text-warn' : '' }}">{{ $row['totals']['late'] }}</td>
                                <td class="ed-num">{{ $row['totals']['leave'] }}</td>
                                <td class="ed-num">{{ Labels::hours($row['totals']['overtime_seconds']) }}</td>
                                <td style="min-width:130px"><x-ed.progress :value="$row['totals']['rate'] ?? 0" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>

@if ($canEdit)
    <dialog class="ed-dialog" id="dlgEdit">
        <form method="POST" id="dlgEditForm">
            @csrf @method('PUT')
            <div class="ed-dialog__body">
                <h3>{{ __('تعديل سجل حضور') }}</h3>
                <p id="dlgEditTitle"></p>
                <div class="ed-form" style="margin-top:12px">
                    <x-ed.field :label="__('وقت الدخول')" required for="editIn"><input class="ed-input" type="time" name="clock_in" id="editIn" required></x-ed.field>
                    <x-ed.field :label="__('وقت الخروج')" for="editOut"><input class="ed-input" type="time" name="clock_out" id="editOut"></x-ed.field>
                    <x-ed.field :label="__('سبب التعديل')" required full for="editReason" :hint="__('يُحفظ مع القيم القديمة والجديدة في سجل الأنشطة.')">
                        <textarea class="ed-textarea" name="reason" id="editReason" rows="2" required minlength="3" maxlength="400"></textarea>
                    </x-ed.field>
                </div>
            </div>
            <div class="ed-dialog__foot">
                <button type="button" class="ed-btn" onclick="this.closest('dialog').close()">{{ __('إلغاء') }}</button>
                <button type="submit" class="ed-btn ed-btn--primary">{{ __('حفظ التعديل') }}</button>
            </div>
        </form>
    </dialog>
@endif

@if ($canCreate)
    <dialog class="ed-dialog" id="dlgAdd">
        <form method="POST" action="{{ route('edata.attendance.store') }}">
            @csrf
            <div class="ed-dialog__body">
                <h3>{{ __('إضافة سجل حضور') }}</h3>
                <p>{{ __('لتسجيل يوم نسي الموظف تسجيله.') }}</p>
                <div class="ed-form" style="margin-top:12px">
                    <x-ed.field :label="__('الموظف')" required full for="addEmp">
                        <select class="ed-select" name="employee_id" id="addEmp" required>
                            <option value="">{{ __('اختر موظفاً') }}</option>
                            @foreach ($allEmployees as $emp)<option value="{{ $emp->id }}">{{ $emp->name }}</option>@endforeach
                        </select>
                    </x-ed.field>
                    <x-ed.field :label="__('التاريخ')" required full for="addDate"><input class="ed-input" type="date" name="date" id="addDate" max="{{ now()->toDateString() }}" required></x-ed.field>
                    <x-ed.field :label="__('وقت الدخول')" required for="addIn"><input class="ed-input" type="time" name="clock_in" id="addIn" required></x-ed.field>
                    <x-ed.field :label="__('وقت الخروج')" for="addOut"><input class="ed-input" type="time" name="clock_out" id="addOut"></x-ed.field>
                    <x-ed.field :label="__('السبب')" required full for="addReason"><textarea class="ed-textarea" name="reason" id="addReason" rows="2" required minlength="3" maxlength="400"></textarea></x-ed.field>
                </div>
            </div>
            <div class="ed-dialog__foot">
                <button type="button" class="ed-btn" onclick="this.closest('dialog').close()">{{ __('إلغاء') }}</button>
                <button type="submit" class="ed-btn ed-btn--primary">{{ __('إضافة السجل') }}</button>
            </div>
        </form>
    </dialog>
@endif

@if ($canConfigure)
    <dialog class="ed-dialog ed-dialog--wide" id="dlgRules">
        <form method="POST" action="{{ route('edata.attendance.settings') }}">
            @csrf
            <div class="ed-dialog__body">
                <h3>{{ __('قواعد الدوام') }}</h3>
                <p>{{ __('تُحسب الحالات من هذه القواعد لكل السجلات، القديمة والجديدة.') }} {{ __('المنطقة الزمنية') }}: <span class="ed-ltr">{{ $rules['zone'] }}</span></p>
                <div class="ed-form ed-form--3" style="margin-top:12px">
                    <x-ed.field :label="__('بداية الدوام')" required for="ruleStart"><input class="ed-input" type="time" name="company_start_time" id="ruleStart" value="{{ $rules['start'] }}" required></x-ed.field>
                    <x-ed.field :label="__('نهاية الدوام')" required for="ruleEnd"><input class="ed-input" type="time" name="company_end_time" id="ruleEnd" value="{{ $rules['end'] }}" required></x-ed.field>
                    <x-ed.field :label="__('فترة السماح بالدقائق')" required for="ruleGrace"><input class="ed-input" type="number" min="0" max="180" name="edata_grace_minutes" id="ruleGrace" value="{{ $rules['grace'] }}" required></x-ed.field>
                    <x-ed.field :label="__('أيام العمل')" required full>
                        <div class="ed-chips">
                            @foreach ($weekdays as $value => $label)
                                <label class="ed-chip"><input type="checkbox" name="work_days[]" value="{{ $value }}" @checked(in_array($value, $rules['work_days'], true))>{{ __($label) }}</label>
                            @endforeach
                        </div>
                    </x-ed.field>
                </div>
            </div>
            <div class="ed-dialog__foot">
                <button type="button" class="ed-btn" onclick="this.closest('dialog').close()">{{ __('إلغاء') }}</button>
                <button type="submit" class="ed-btn ed-btn--primary">{{ __('حفظ القواعد') }}</button>
            </div>
        </form>
    </dialog>
@endif
@endsection

@push('edata-scripts')
<script>
document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-edit-row]');
    if (!button) { return; }
    var dialog = document.getElementById('dlgEdit');
    document.getElementById('dlgEditForm').action = button.dataset.editRow;
    document.getElementById('dlgEditTitle').textContent = button.dataset.title;
    document.getElementById('editIn').value = button.dataset.in;
    document.getElementById('editOut').value = button.dataset.out;
    document.getElementById('editReason').value = '';
    dialog.showModal();
});
</script>
@endpush
