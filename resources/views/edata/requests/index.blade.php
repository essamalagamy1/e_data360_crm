@extends('layouts.admin')
@section('edata-page', true)
@section('page-title', __('الطلبات'))

@php
    use App\Support\Edata\Labels;
    $kinds = ['permission' => __('إذن'), 'correction' => __('تصحيح حضور'), 'leave' => __('إجازة')];
    $tabs = ['pending' => __('تنتظر الرد'), 'approved' => __('تمت الموافقة'), 'rejected' => __('مرفوضة')];

    // One list for leave and attendance requests, newest first.
    $rows = collect();
    foreach ($leaves as $leave) {
        $rows->push((object) [
            'type' => 'leave', 'id' => $leave->id, 'kind' => 'leave', 'employee' => $employees[$leave->employee_id] ?? null,
            'date' => $leave->start_date, 'until' => $leave->end_date, 'from' => null, 'to' => null,
            'detail' => ($leave->leaveType->title ?? __('إجازة')) . '، ' . $leave->total_leave_days . ' ' . __('يوم'),
            'reason' => $leave->leave_reason, 'note' => $leave->remark, 'created' => $leave->created_at,
            'balance' => isset($employees[$leave->employee_id])
                ? \App\Support\Edata\EmployeeServices::leaveBalances($employees[$leave->employee_id], $company, \Carbon\Carbon::parse($leave->start_date))->firstWhere('id', (int) $leave->leave_type_id)
                : null,
        ]);
    }
    foreach ($requests as $req) {
        $rows->push((object) [
            'type' => 'request', 'id' => $req->id, 'kind' => $req->kind, 'employee' => $employees[$req->employee_id] ?? null,
            'date' => $req->date, 'until' => null, 'from' => $req->time_from, 'to' => $req->time_to,
            'detail' => $req->kind === 'correction'
                ? __('دخول') . ' ' . substr((string) $req->time_from, 0, 5) . ($req->time_to ? '، ' . __('خروج') . ' ' . substr((string) $req->time_to, 0, 5) : '')
                : __('من') . ' ' . substr((string) $req->time_from, 0, 5) . ' ' . __('إلى') . ' ' . substr((string) $req->time_to, 0, 5),
            'reason' => $req->reason, 'note' => $req->review_note, 'created' => \Carbon\Carbon::parse($req->created_at),
            'balance' => null,
        ]);
    }
    $rows = $rows->sortByDesc('created')->values();
@endphp

@section('content')
<x-ed.head :title="__('الطلبات')" :sub="__('طلبات الإجازة والإذن وتصحيح الحضور من الفريق. يصل للموظف إشعار بقرارك.')" />

<details class="ed-panel ed-terms" @if ($errors->has('terms')) open @endif>
    <summary class="ed-terms__head">
        <span><i class="ti ti-file-certificate"></i>{{ __('شروط الإجازات') }}</span>
        @if ($terms !== '')<span class="ed-badge tone-good">{{ __('ظاهرة للموظفين') }}</span>@else<span class="ed-badge tone-muted">{{ __('بدون شروط') }}</span>@endif
    </summary>
    <form method="POST" action="{{ route('edata.requests.terms') }}" class="ed-panel__body" style="display:grid;gap:10px">
        @csrf
        <textarea class="ed-textarea" name="terms" rows="5" maxlength="4000" placeholder="{{ __('مثال: تقديم الطلب قبل 3 أيام على الأقل. الإجازة المرضية تحتاج تقريراً طبياً. لا تزيد الإجازة المتصلة عن 10 أيام.') }}" aria-label="{{ __('شروط الإجازات') }}">{{ old('terms', $terms) }}</textarea>
        <span class="ed-hint">{{ __('تظهر لكل موظف عند طلب إجازة، ولا يُرسل الطلب إلا بعد الموافقة عليها. اتركها فارغة لإلغائها.') }}</span>
        <div><button type="submit" class="ed-btn ed-btn--primary ed-btn--sm"><i class="ti ti-device-floppy"></i>{{ __('حفظ الشروط') }}</button></div>
    </form>
</details>

<section class="ed-panel ed-reveal">
    <div class="ed-tabs" role="tablist">
        @foreach ($tabs as $key => $label)
            <a href="{{ route('edata.requests.index', ['status' => $key]) }}" class="{{ $status === $key ? 'is-active' : '' }}">{{ $label }}@if ($key === 'pending' && $pending > 0) <b class="ed-nav__count">{{ $pending }}</b>@endif</a>
        @endforeach
    </div>

    @if ($rows->isEmpty())
        <x-ed.empty icon="ti-inbox" :title="$status === 'pending' ? __('لا توجد طلبات تنتظر الرد.') : __('لا توجد طلبات هنا.')" />
    @else
        <div class="ed-reqdesk">
            @foreach ($rows as $row)
                <article class="ed-reqcard">
                    <div class="ed-reqcard__who">
                        <x-ed.avatar :user="$row->employee?->user" :name="$row->employee->name ?? '—'" />
                        <div>
                            <b>{{ $row->employee->name ?? '—' }}</b>
                            <span class="ed-badge tone-{{ $row->kind === 'leave' ? 'info' : ($row->kind === 'correction' ? 'warn' : 'primary') }}">{{ $kinds[$row->kind] ?? $row->kind }}</span>
                        </div>
                    </div>
                    <div class="ed-reqcard__what">
                        <span class="ed-num"><i class="ti ti-calendar"></i>{{ Labels::date($row->date, 'l، j M') }}@if ($row->until && \Carbon\Carbon::parse($row->until)->toDateString() !== \Carbon\Carbon::parse($row->date)->toDateString()) - {{ Labels::date($row->until, 'j M') }}@endif</span>
                        <span class="ed-num">{{ $row->detail }}</span>
                        <p>{{ $row->reason }}</p>
                        @if ($row->note)<p class="ed-hint">{{ __('ملاحظة الرد') }}: {{ $row->note }}</p>@endif
                        @if ($row->balance && $row->balance->left !== null)
                            <span class="ed-reqcard__bal ed-num"><i class="ti ti-calendar-stats"></i>{{ __('رصيده') }}: {{ \App\Support\Edata\EmployeeServices::days($row->balance->days) }} {{ __('يوم') }} · {{ __('أخذ') }} {{ \App\Support\Edata\EmployeeServices::days($row->balance->used) }} · {{ __('المتبقي') }} <b>{{ \App\Support\Edata\EmployeeServices::days($row->balance->left) }}</b></span>
                        @endif
                    </div>
                    @if ($status === 'pending')
                        <form method="POST" action="{{ route('edata.requests.decide', [$row->type, $row->id]) }}" class="ed-reqcard__act">
                            @csrf
                            <input class="ed-input" name="note" maxlength="400" placeholder="{{ __('ملاحظة للموظف (اختياري)') }}" aria-label="{{ __('ملاحظة للموظف') }}">
                            <div>
                                <button type="submit" name="decision" value="approve" class="ed-btn ed-btn--primary ed-btn--sm"><i class="ti ti-check"></i>{{ __('موافقة') }}</button>
                                <button type="submit" name="decision" value="reject" class="ed-btn ed-btn--ghost ed-btn--sm"><i class="ti ti-x"></i>{{ __('رفض') }}</button>
                                <button type="submit" form="edDel-{{ $row->type }}-{{ $row->id }}" class="ed-btn ed-btn--ghost ed-btn--sm tone-text-bad" title="{{ __('حذف الطلب') }}" aria-label="{{ __('حذف الطلب') }}"><i class="ti ti-trash"></i></button>
                            </div>
                        </form>
                    @else
                        <div class="ed-reqcard__act"><div><button type="submit" form="edDel-{{ $row->type }}-{{ $row->id }}" class="ed-btn ed-btn--ghost ed-btn--sm tone-text-bad"><i class="ti ti-trash"></i>{{ __('حذف') }}</button></div></div>
                    @endif
                    <form method="POST" action="{{ route('edata.requests.destroy', [$row->type, $row->id]) }}" id="edDel-{{ $row->type }}-{{ $row->id }}" hidden
                        data-confirm="{{ __('حذف هذا الطلب نهائياً؟') }}" data-confirm-text="{{ $row->kind === 'correction' && $status === 'approved' ? __('يُحذف الطلب فقط، ويبقى تعديل الحضور الذي تم. تبقى تفاصيله في سجل الأنشطة.') : __('لا يمكن التراجع، وتبقى تفاصيله في سجل الأنشطة.') }}" data-confirm-yes="{{ __('حذف') }}">
                        @csrf
                        @method('DELETE')
                    </form>
                </article>
            @endforeach
        </div>
    @endif
</section>
@endsection
