<?php

namespace App\Http\Controllers\Edata;

use App\Models\AttendanceEmployee;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\ProjectTask;
use App\Models\User;
use App\Support\Edata\Access;
use App\Support\Edata\AttendanceService;
use App\Support\Edata\Audit;
use App\Support\Edata\EmployeeServices;
use App\Support\Edata\Notifier;
use App\Support\Edata\TaskRules;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The manager's side: approve requests, read the daily reports, post announcements.
 */
class TeamDeskController extends BaseController
{
    public function requests(Request $request)
    {
        $me = $this->me();
        abort_unless(EmployeeServices::canApprove($me), 403);
        $company = $this->companyId();
        $status = in_array($request->query('status'), ['pending', 'approved', 'rejected'], true) ? $request->query('status') : 'pending';

        $employees = Access::employees($me)->with("user:id,name,avatar")->get()->keyBy("id");

        $requests = DB::table('edata_requests')->where('company_id', $company)->where('status', $status)
            ->whereIn('employee_id', $employees->keys())->orderByDesc('id')->limit(100)->get();
        $leaveStatus = ['pending' => ['Pending'], 'approved' => ['Approved'], 'rejected' => ['Reject', 'Rejected']][$status];
        $leaves = Leave::where('created_by', $company)->whereIn('status', $leaveStatus)
            ->whereIn('employee_id', $employees->keys())->with('leaveType')->orderByDesc('id')->limit(100)->get();

        return view('edata.requests.index', [
            'requests' => $requests,
            'leaves' => $leaves,
            'employees' => $employees,
            'status' => $status,
            'pending' => EmployeeServices::pendingCount($company),
        ]);
    }

    public function decide(Request $request, string $type, int $id)
    {
        $me = $this->me();
        abort_unless(EmployeeServices::canApprove($me), 403);
        $company = $this->companyId();
        $data = $request->validate(['decision' => 'required|in:approve,reject', 'note' => 'nullable|string|max:400'], [], ['note' => 'الملاحظة']);
        $approve = $data['decision'] === 'approve';
        $scope = Access::employees($me)->pluck('employees.id');

        if ($type === 'leave') {
            $leave = Leave::where('created_by', $company)->whereIn('employee_id', $scope)->findOrFail($id);
            if ($leave->status !== 'Pending') {
                return $this->failed('تم الرد على هذا الطلب من قبل.');
            }
            $leave->status = $approve ? 'Approved' : 'Reject';
            $leave->remark = $data['note'] ?? null;
            $leave->save();
            Audit::record($approve ? 'status_changed' : 'status_changed', 'attendance', $leave->id, 'طلب إجازة', null, ['الحالة' => $approve ? 'موافقة' : 'رفض'], $data['note'] ?? null);
            $this->tellEmployee((int) $leave->employee_id, $approve ? 'تمت الموافقة على إجازتك' : 'تم رفض طلب الإجازة', $data['note'] ?? null);

            return $this->done($approve ? 'تمت الموافقة على الإجازة.' : 'تم رفض الإجازة.');
        }

        $row = DB::table('edata_requests')->where('company_id', $company)->whereIn('employee_id', $scope)->where('id', $id)->first();
        abort_unless($row, 404);
        if ($row->status !== 'pending') {
            return $this->failed('تم الرد على هذا الطلب من قبل.');
        }

        if ($approve && $row->kind === 'correction') {
            $employee = Employee::findOrFail($row->employee_id);
            $existing = AttendanceEmployee::where('employee_id', $employee->id)->whereDate('date', $row->date)->where('clock_in', '!=', '00:00:00')->orderBy('id')->first();
            Audit::$nextNote = 'طلب تصحيح من الموظف: ' . $row->reason . (!empty($data['note']) ? ' — ' . $data['note'] : '');
            try {
                AttendanceService::for($company)->saveManual($existing, $employee, $row->date, substr((string) $row->time_from, 0, 5), $row->time_to ? substr((string) $row->time_to, 0, 5) : null);
            } catch (DomainException $e) {
                Audit::$nextNote = null;

                return $this->failed($e->getMessage());
            }
        }

        DB::table('edata_requests')->where('id', $row->id)->update([
            'status' => $approve ? 'approved' : 'rejected',
            'reviewed_by' => $me->id,
            'reviewed_at' => now(),
            'review_note' => $data['note'] ?? null,
            'updated_at' => now(),
        ]);

        $what = $row->kind === 'correction' ? 'التصحيح' : 'الإذن';
        $this->tellEmployee((int) $row->employee_id, $approve ? 'تمت الموافقة على طلب ' . $what : 'تم رفض طلب ' . $what, $data['note'] ?? null);

        return $this->done($approve ? 'تمت الموافقة على الطلب.' : 'تم رفض الطلب.');
    }

    private function tellEmployee(int $employeeId, string $title, ?string $note): void
    {
        $userId = (int) Employee::whereKey($employeeId)->value('user_id');
        if ($userId) {
            Notifier::send($userId, 'request_decided', $title, $note, route('edata.me', [], false) . '#requests');
        }
    }

    /** Everyone's day on one page: times, the planned tasks and what each person wrote. */
    public function daily(Request $request)
    {
        $me = $this->me();
        Access::need($me, 'manage attendance');
        $company = $this->companyId();
        $service = AttendanceService::for($company);
        $zone = $service->rules()['zone'];
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('date')) ? $request->query('date') : Carbon::now($zone)->toDateString();

        $employees = Access::employees($me)->where('employees.employment_status', '!=', 'ended')->orderBy('name')->get();
        $days = $service->report($employees, $date, $date)->keyBy('employee_id');
        $logs = DB::table('edata_daily_logs')->where('company_id', $company)->where('date', $date)->get()->keyBy('employee_id');

        $taskIds = $logs->flatMap(fn ($log) => EmployeeServices::planIds($log))->unique()->all();
        $tasks = ProjectTask::whereIn('id', $taskIds)->get(['id', 'name', 'is_complete', 'stage_id'])->keyBy('id');

        return view('edata.daily.index', [
            'date' => $date,
            'employees' => $employees,
            'days' => $days,
            'logs' => $logs,
            'tasks' => $tasks,
            'company' => $company,
        ]);
    }

    public function announcements()
    {
        $me = $this->me();
        Access::need($me, ['manage announcement', 'create announcement']);
        $company = $this->companyId();

        $staffCount = Employee::where('created_by', $company)->whereNotNull('user_id')->where('employment_status', '!=', 'ended')->count();
        $rows = DB::table('edata_announcements')->where('company_id', $company)->orderByDesc('id')->limit(50)->get();
        $reads = DB::table('edata_announcement_reads')->whereIn('announcement_id', $rows->pluck('id'))->get()->groupBy('announcement_id');
        $names = User::whereIn('id', $reads->flatten()->pluck('user_id')->unique())->pluck('name', 'id');

        return view('edata.announcements.index', [
            'rows' => $rows,
            'reads' => $reads,
            'names' => $names,
            'staffCount' => $staffCount,
            'canCreate' => Access::isOwner($me) || $me->can('create announcement'),
        ]);
    }

    public function storeAnnouncement(Request $request)
    {
        $me = $this->me();
        abort_unless(Access::isOwner($me) || $me->can('create announcement'), 403);
        $company = $this->companyId();
        $data = $request->validate([
            'title' => 'required|string|max:191',
            'body' => 'nullable|string|max:3000',
            'show_until' => 'nullable|date|after_or_equal:today',
        ], [], ['title' => 'العنوان', 'body' => 'التفاصيل', 'show_until' => 'يظهر حتى']);

        DB::table('edata_announcements')->insert([
            'company_id' => $company, 'user_id' => $me->id, 'title' => $data['title'], 'body' => $data['body'] ?? null,
            'show_until' => $data['show_until'] ?? null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $staff = Employee::where('created_by', $company)->whereNotNull('user_id')->where('employment_status', '!=', 'ended')->pluck('user_id')->all();
        Notifier::send($staff, 'announcement', 'إعلان جديد: ' . $data['title'], null, route('edata.me', [], false));

        return $this->done('تم نشر الإعلان، ووصل إشعار لكل الفريق.');
    }

    public function archiveAnnouncement(int $announcement)
    {
        $me = $this->me();
        abort_unless(Access::isOwner($me) || $me->can('delete announcement') || $me->can('edit announcement'), 403);
        DB::table('edata_announcements')->where('company_id', $this->companyId())->where('id', $announcement)->update(['archived_at' => now(), 'updated_at' => now()]);

        return $this->done('تم إخفاء الإعلان. يبقى في السجل.');
    }
}
