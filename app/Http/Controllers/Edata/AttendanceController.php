<?php

namespace App\Http\Controllers\Edata;

use App\Exports\EdataAttendanceExport;
use App\Models\AttendanceEmployee;
use App\Models\Leave;
use App\Support\Edata\Access;
use App\Support\Edata\Audit;
use App\Support\Edata\AttendanceService;
use App\Support\Edata\Fmt;
use App\Support\Edata\Labels;
use App\Support\Edata\Notifier;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class AttendanceController extends BaseController
{
    public function index(Request $request)
    {
        $me = $this->me();
        if (!$me->can('manage attendance')) {
            return redirect()->route('edata.me.attendance');
        }

        [$days, $employees, $from, $to] = $this->filtered($request);
        $service = AttendanceService::for($this->companyId());

        $page = max(1, (int) $request->query('page', 1));
        $perPage = 25;
        $paged = new LengthAwarePaginator(
            $days->forPage($page, $perPage)->values(),
            $days->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('edata.attendance.index', [
            'paged' => $paged,
            'employees' => $employees,
            'allEmployees' => Access::employees($me)->orderBy('name')->get(['id', 'name']),
            'from' => $from,
            'to' => $to,
            'totals' => $service->totals($days),
            'perEmployee' => $days->groupBy('employee_id')->map(fn ($rows) => [
                'name' => $rows->first()['employee_name'],
                'user_id' => $rows->first()['user_id'],
                'totals' => $service->totals($rows),
            ])->sortBy('name')->values(),
            'board' => $service->todayBoard(Access::employees($me)->get()),
            'rules' => $service->rules(),
            'canEdit' => $me->can('edit attendance'),
            'canCreate' => $me->can('create attendance'),
            'canDelete' => $me->can('delete attendance'),
            'canConfigure' => Access::isOwner($me) || $me->can('manage attendance settings'),
        ]);
    }

    public function export(Request $request)
    {
        Access::need($this->me(), 'manage attendance');
        [$days, , $from, $to] = $this->filtered($request);

        Audit::record('created', 'settings', null, 'تصدير سجل الحضور من ' . $from . ' إلى ' . $to);

        return Excel::download(new EdataAttendanceExport($days), 'attendance_' . $from . '_' . $to . '.xlsx');
    }

    /** @return array{0:\Illuminate\Support\Collection,1:\Illuminate\Support\Collection,2:string,3:string} */
    private function filtered(Request $request): array
    {
        $me = $this->me();
        $service = AttendanceService::for($this->companyId());
        $zone = $service->rules()['zone'];
        $today = Carbon::now($zone);

        $from = $this->validDate($request->query('from')) ?? $today->copy()->startOfMonth()->toDateString();
        $to = $this->validDate($request->query('to')) ?? $today->toDateString();
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        // A year is plenty for a screen and keeps the day-by-day report quick.
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to), true) > 366) {
            $from = Carbon::parse($to)->subDays(366)->toDateString();
        }

        $employeesQuery = Access::employees($me);
        if ($request->filled('employee')) {
            $employeesQuery->where('employees.id', (int) $request->query('employee'));
        }
        if ($request->filled('q')) {
            $employeesQuery->where('employees.name', 'like', '%' . $request->query('q') . '%');
        }
        $employees = $employeesQuery->orderBy('name')->get();

        $days = $service->report($employees, $from, $to);

        // Rest days carry no information in a log, so they only appear when asked for.
        $status = (string) $request->query('status', '');
        if ($status !== '') {
            $days = $days->filter(fn ($d) => $d['status'] === $status || in_array($status, $d['flags'], true));
        } else {
            $days = $days->reject(fn ($d) => in_array($d['status'], ['weekend', 'not_yet'], true));
        }

        return [$days->values(), $employees, $from, $to];
    }

    private function validDate($value): ?string
    {
        return (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) ? $value : null;
    }

    public function store(Request $request)
    {
        $me = $this->me();
        Access::need($me, 'create attendance');

        $data = $request->validate([
            'employee_id' => 'required|integer',
            'date' => 'required|date_format:Y-m-d',
            'clock_in' => 'required|date_format:H:i',
            'clock_out' => 'nullable|date_format:H:i',
            'reason' => 'required|string|min:3|max:400',
        ], [], $this->names());

        $employee = Access::employees($me)->findOrFail($data['employee_id']);

        Audit::$nextNote = $data['reason'];

        try {
            AttendanceService::for($this->companyId())->saveManual(null, $employee, $data['date'], $data['clock_in'], $data['clock_out'] ?? null);
        } catch (DomainException $e) {
            Audit::$nextNote = null;

            return $this->failed($e->getMessage());
        }

        return $this->done('تمت إضافة سجل الحضور وتوثيق السبب.');
    }

    public function update(Request $request, int $row)
    {
        $me = $this->me();
        Access::need($me, 'edit attendance');

        $data = $request->validate([
            'clock_in' => 'required|date_format:H:i',
            'clock_out' => 'nullable|date_format:H:i',
            'reason' => 'required|string|min:3|max:400',
        ], [], $this->names());

        $record = $this->reachable($row);
        $employee = Access::employees($me)->findOrFail($record->employee_id);
        $date = Carbon::parse($record->date)->toDateString();

        Audit::$nextNote = $data['reason'];

        try {
            AttendanceService::for($this->companyId())->saveManual($record, $employee, $date, $data['clock_in'], $data['clock_out'] ?? null);
        } catch (DomainException $e) {
            Audit::$nextNote = null;

            return $this->failed($e->getMessage());
        }

        return $this->done('تم تعديل السجل وتوثيق السبب.');
    }

    /**
     * Changes a day without a clock-in (usually an absence) to attendance, leave,
     * excused, day off or absent, or puts it back to what the rules say.
     */
    public function setDay(Request $request)
    {
        $me = $this->me();
        Access::need($me, 'edit attendance');
        $company = $this->companyId();
        $service = AttendanceService::for($company);

        $data = $request->validate([
            'employee_id' => 'required|integer',
            'date' => 'required|date_format:Y-m-d',
            'state' => ['required', Rule::in(['present', 'leave', 'excused', 'day_off', 'absent', 'auto'])],
            'clock_in' => 'nullable|required_if:state,present|date_format:H:i',
            'clock_out' => 'nullable|date_format:H:i',
            'leave_type_id' => ['nullable', 'required_if:state,leave', 'integer', Rule::exists('leave_types', 'id')->where('created_by', $company)],
            'reason' => 'required|string|min:3|max:400',
        ], [], $this->names() + ['state' => 'الحالة الجديدة', 'leave_type_id' => 'نوع الإجازة']);

        $employee = Access::employees($me)->findOrFail($data['employee_id']);
        $date = $data['date'];
        $state = $data['state'];
        if ($date > Carbon::now($service->rules()['zone'])->toDateString()) {
            return $this->failed('لا يمكن تعديل يوم لم يأتِ بعد.');
        }
        $worked = AttendanceEmployee::where('employee_id', $employee->id)->whereDate('date', $date)
            ->whereNotNull('clock_in')->where('clock_in', '!=', '00:00:00')->exists();
        if ($worked) {
            return $this->failed('هذا اليوم فيه تسجيل حضور. عدّل وقته أو احذف السجل أولاً.');
        }

        $old = $service->report(collect([$employee]), $date, $date)->first()['status'];

        try {
            DB::transaction(function () use ($data, $date, $state, $employee, $company, $service, $me) {
                $key = ['employee_id' => $employee->id, 'date' => $date];
                $current = DB::table('edata_day_statuses')->where($key)->lockForUpdate()->first();

                // The leave this screen made for the day goes first, so its days return to the balance.
                if ($current && $current->leave_id) {
                    Leave::where('created_by', $company)->where('employee_id', $employee->id)->whereKey($current->leave_id)->delete();
                }
                DB::table('edata_day_statuses')->where($key)->delete();

                if ($state !== 'auto') {
                    $covering = Leave::where('employee_id', $employee->id)->whereIn('status', ['Approved', 'Pending'])
                        ->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->first();
                    if ($covering) {
                        throw new DomainException($covering->status === 'Pending'
                            ? 'يوجد طلب إجازة ينتظر الرد يشمل هذا اليوم. ردّ عليه من صفحة الطلبات أولاً.'
                            : 'هذا اليوم ضمن إجازة تمت الموافقة عليها. احذفها من صفحة الطلبات أولاً.');
                    }
                }

                if ($state === 'present') {
                    Audit::$nextNote = $data['reason'];
                    $service->saveManual(null, $employee, $date, $data['clock_in'], $data['clock_out'] ?? null);
                } elseif ($state !== 'auto') {
                    $leaveId = $state !== 'leave' ? null : Leave::create([
                        'employee_id' => $employee->id,
                        'leave_type_id' => $data['leave_type_id'],
                        'applied_on' => Carbon::now($service->rules()['zone'])->toDateString(),
                        'start_date' => $date,
                        'end_date' => $date,
                        'total_leave_days' => '1',
                        'leave_reason' => $data['reason'],
                        'remark' => '',
                        'status' => 'Approved',
                        'created_by' => $company,
                    ])->id;
                    DB::table('edata_day_statuses')->insert($key + [
                        'company_id' => $company, 'status' => $state, 'leave_id' => $leaveId, 'reason' => $data['reason'],
                        'set_by' => $me->id, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            });
        } catch (DomainException $e) {
            Audit::$nextNote = null;

            return $this->failed($e->getMessage());
        }

        $new = $service->report(collect([$employee]), $date, $date)->first()['status'];
        $label = Labels::attendance($new)[0];
        Audit::record('updated', 'attendance', null, $employee->name . ' - ' . $date, ['الحالة' => Labels::attendance($old)[0]], ['الحالة' => $label], $data['reason']);
        if ($employee->user_id && $new !== $old) {
            Notifier::send((int) $employee->user_id, 'attendance_changed', 'تم تعديل يوم ' . $date . ' إلى «' . $label . '»', $data['reason'], route('edata.me.attendance', [], false));
        }

        return $this->done('تم تعديل اليوم إلى «' . $label . '» وتوثيق السبب.');
    }

    public function destroy(Request $request, int $row)
    {
        Access::need($this->me(), 'delete attendance');

        $data = $request->validate(['reason' => 'required|string|min:3|max:400'], [], $this->names());
        $record = $this->reachable($row);

        Audit::$nextNote = $data['reason'];
        $record->delete();

        return $this->done('تم حذف السجل وتوثيق السبب.');
    }

    /** Old delete route. Kept for old screens, now with the company check it never had. */
    public function destroyLegacy($attendanceemployee)
    {
        Access::need($this->me(), 'delete attendance');
        $record = $this->reachable((int) $attendanceemployee);

        Audit::$nextNote = 'حذف من الشاشة القديمة';
        $record->delete();

        return redirect()->route('edata.attendance.index')->with('success', 'تم حذف السجل.');
    }

    public function saveSettings(Request $request)
    {
        $me = $this->me();
        if (!Access::isOwner($me) && !$me->can('manage attendance settings')) {
            abort(403);
        }

        $data = $request->validate([
            'company_start_time' => 'required|date_format:H:i',
            'company_end_time' => 'required|date_format:H:i|after:company_start_time',
            'edata_grace_minutes' => 'required|integer|min:0|max:180',
            'work_days' => 'required|array|min:1',
            'work_days.*' => 'integer|between:0,6',
        ], [], [
            'company_start_time' => 'بداية الدوام',
            'company_end_time' => 'نهاية الدوام',
            'edata_grace_minutes' => 'فترة السماح',
            'work_days' => 'أيام العمل',
        ]);

        $company = $this->companyId();
        $old = Fmt::settings($company);
        $values = [
            'company_start_time' => $data['company_start_time'],
            'company_end_time' => $data['company_end_time'],
            'edata_grace_minutes' => (string) $data['edata_grace_minutes'],
            'edata_work_days' => implode(',', array_map('intval', $data['work_days'])),
        ];

        foreach ($values as $name => $value) {
            DB::table('settings')->updateOrInsert(
                ['name' => $name, 'created_by' => $company],
                ['value' => $value, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        Audit::record(
            'updated',
            'settings',
            null,
            'قواعد الحضور',
            array_intersect_key($old, $values),
            $values
        );

        Fmt::flush();

        return $this->done('تم حفظ قواعد الحضور.');
    }

    private function reachable(int $id): AttendanceEmployee
    {
        return AttendanceEmployee::whereIn('employee_id', Access::employees($this->me())->select('employees.id'))->findOrFail($id);
    }

    private function names(): array
    {
        return [
            'employee_id' => 'الموظف',
            'date' => 'التاريخ',
            'clock_in' => 'وقت الدخول',
            'clock_out' => 'وقت الخروج',
            'reason' => 'سبب التعديل',
        ];
    }
}
