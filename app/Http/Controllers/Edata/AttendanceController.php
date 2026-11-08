<?php

namespace App\Http\Controllers\Edata;

use App\Exports\EdataAttendanceExport;
use App\Models\AttendanceEmployee;
use App\Support\Edata\Access;
use App\Support\Edata\Audit;
use App\Support\Edata\AttendanceService;
use App\Support\Edata\Fmt;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
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
