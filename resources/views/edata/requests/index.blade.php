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
        ]);
    }
    $rows = $rows->sortByDesc('created')->values();
@endphp

@section('content')
<x-ed.head :title="__('الطلبات')" :sub="__('طلبات الإجازة والإذن وتصحيح الحضور من الفريق. يصل للموظف إشعار بقرارك.')" />

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
                    </div>
                    @if ($status === 'pending')
                        <form method="POST" action="{{ route('edata.requests.decide', [$row->type, $row->id]) }}" class="ed-reqcard__act">
                            @csrf
                            <input class="ed-input" name="note" maxlength="400" placeholder="{{ __('ملاحظة للموظف (اختياري)') }}" aria-label="{{ __('ملاحظة للموظف') }}">
                            <div>
                                <button type="submit" name="decision" value="approve" class="ed-btn ed-btn--primary ed-btn--sm"><i class="ti ti-check"></i>{{ __('موافقة') }}</button>
                                <button type="submit" name="decision" value="reject" class="ed-btn ed-btn--ghost ed-btn--sm"><i class="ti ti-x"></i>{{ __('رفض') }}</button>
                            </div>
                        </form>
                    @endif
                </article>
            @endforeach
        </div>
    @endif
</section>
@endsection
