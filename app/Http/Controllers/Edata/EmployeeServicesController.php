<?php

namespace App\Http\Controllers\Edata;

use App\Models\Employee;
use App\Models\Leave;
use App\Models\ProjectTask;
use App\Support\Edata\Access;
use App\Support\Edata\AttendanceService;
use App\Support\Edata\EmployeeServices;
use App\Support\Edata\Notifier;
use App\Support\Edata\TaskRules;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * What an employee does for themself: ask for leave, a permission or a correction,
 * plan the day, time a task and mark an announcement as read.
 */
class EmployeeServicesController extends BaseController
{
    private function myEmployee(): Employee
    {
        $employee = Employee::where('user_id', $this->me()->id)->first();
        abort_unless($employee, 403, 'حسابك غير مرتبط بسجل موظف.');

        return $employee;
    }

    private function today(): Carbon
    {
        return Carbon::now(AttendanceService::for($this->companyId())->rules()['zone']);
    }

    public function leave(Request $request)
    {
        abort_unless(EmployeeServices::allows($this->me(), 'requests'), 403);
        $employee = $this->myEmployee();
        $company = $this->companyId();

        $data = $request->validate([
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')->where('created_by', $company)],
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'required|string|min:3|max:250',
        ], [], ['leave_type_id' => 'نوع الإجازة', 'start_date' => 'من', 'end_date' => 'إلى', 'reason' => 'السبب']);

        $days = Carbon::parse($data['start_date'])->diffInDays(Carbon::parse($data['end_date'])) + 1;

        Leave::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $data['leave_type_id'],
            'applied_on' => $this->today()->toDateString(),
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'total_leave_days' => (string) $days,
            'leave_reason' => $data['reason'],
            'status' => 'Pending',
            'created_by' => $company,
        ]);

        Notifier::send(EmployeeServices::approverIds($company), 'request_new', 'طلب إجازة من ' . $employee->name, $days . ' يوم من ' . $data['start_date'], route('edata.requests.index', [], false));

        return $this->done('تم إرسال طلب الإجازة، وسيصلك إشعار بالرد.');
    }

    public function permission(Request $request)
    {
        abort_unless(EmployeeServices::allows($this->me(), 'requests'), 403);

        return $this->storeRequest($request, 'permission');
    }

    public function correction(Request $request)
    {
        abort_unless(EmployeeServices::allows($this->me(), 'requests'), 403);

        return $this->storeRequest($request, 'correction');
    }

    private function storeRequest(Request $request, string $kind)
    {
        $employee = $this->myEmployee();
        $company = $this->companyId();
        $today = $this->today()->toDateString();

        $rules = [
            'date' => ['required', 'date_format:Y-m-d', $kind === 'correction' ? 'before_or_equal:' . $today : 'after_or_equal:' . Carbon::parse($today)->subDays(7)->toDateString()],
            'time_from' => ($kind === 'correction' ? 'required' : 'nullable') . '|date_format:H:i',
            'time_to' => 'nullable|date_format:H:i',
            'reason' => 'required|string|min:3|max:400',
        ];
        if ($kind === 'permission') {
            $rules['time_to'] = 'required|date_format:H:i';
            $rules['time_from'] = 'required|date_format:H:i';
        }
        $data = $request->validate($rules, [], ['date' => 'التاريخ', 'time_from' => $kind === 'correction' ? 'وقت الدخول' : 'من', 'time_to' => $kind === 'correction' ? 'وقت الخروج' : 'إلى', 'reason' => 'السبب']);

        if (!empty($data['time_to']) && !empty($data['time_from']) && $kind === 'permission' && $data['time_to'] <= $data['time_from']) {
            return $this->failed('يجب أن يكون وقت نهاية الإذن بعد بدايته.');
        }

        DB::table('edata_requests')->insert([
            'company_id' => $company,
            'employee_id' => $employee->id,
            'user_id' => $this->me()->id,
            'kind' => $kind,
            'date' => $data['date'],
            'time_from' => $data['time_from'] ?? null,
            'time_to' => $data['time_to'] ?? null,
            'reason' => $data['reason'],
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $title = ($kind === 'correction' ? 'طلب تصحيح حضور من ' : 'طلب إذن من ') . $employee->name;
        Notifier::send(EmployeeServices::approverIds($company), 'request_new', $title, $data['date'], route('edata.requests.index', [], false));

        return $this->done($kind === 'correction' ? 'تم إرسال طلب التصحيح، وسيصلك إشعار بالرد.' : 'تم إرسال طلب الإذن، وسيصلك إشعار بالرد.');
    }

    /** Up to three of the person's own open tasks to focus on today. */
    public function plan(Request $request)
    {
        abort_unless(EmployeeServices::allows($this->me(), 'plan'), 403);
        $employee = $this->myEmployee();
        $me = $this->me();
        $company = $this->companyId();

        $mine = Access::tasks($me)->whereRaw('FIND_IN_SET(?, project_tasks.assign_to)', [$me->id])->pluck('project_tasks.id')->all();
        $data = $request->validate([
            'tasks' => 'nullable|array|max:3',
            'tasks.*' => ['integer', Rule::in($mine)],
        ], ['tasks.max' => 'اختر 3 مهام على الأكثر.'], ['tasks' => 'مهام اليوم']);

        DB::table('edata_daily_logs')->updateOrInsert(
            ['employee_id' => $employee->id, 'date' => $this->today()->toDateString()],
            ['company_id' => $company, 'user_id' => $me->id, 'plan' => json_encode(array_values(array_map('intval', $data['tasks'] ?? []))), 'updated_at' => now(), 'created_at' => now()]
        );

        return $this->done('تم حفظ خطة اليوم. بالتوفيق.');
    }

    /** One running timer per person: starting a task stops whatever was running. */
    public function startTimer(int $task)
    {
        $me = $this->me();
        abort_unless(EmployeeServices::allows($me, 'timer'), 403);
        $record = Access::tasks($me)->findOrFail($task);
        abort_unless($me->can('edit project task') || in_array((int) $me->id, TaskRules::assigneeIds($record), true), 403);

        DB::transaction(function () use ($me, $record) {
            if ($running = EmployeeServices::runningTimer($me->id)) {
                EmployeeServices::stopTimer($running);
            }
            DB::table('edata_task_timers')->insert([
                'company_id' => $this->companyId(),
                'task_id' => $record->id,
                'project_id' => $record->project_id,
                'user_id' => $me->id,
                'started_at' => Carbon::now('UTC')->format('Y-m-d H:i:s'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return $this->done('بدأ المؤقت على «' . $record->name . '».');
    }

    public function stopTimer()
    {
        $running = EmployeeServices::runningTimer($this->me()->id);
        if (!$running) {
            return $this->failed('لا يوجد مؤقت يعمل الآن.');
        }
        EmployeeServices::stopTimer($running);

        return $this->done('تم إيقاف المؤقت وحفظ الوقت.');
    }

    public function readAnnouncement(int $announcement)
    {
        $row = DB::table('edata_announcements')->where('company_id', $this->companyId())->where('id', $announcement)->first();
        abort_unless($row, 404);
        DB::table('edata_announcement_reads')->insertOrIgnore(['announcement_id' => $row->id, 'user_id' => $this->me()->id, 'read_at' => now()]);

        return request()->expectsJson() ? response()->json(['ok' => true]) : back();
    }
}
