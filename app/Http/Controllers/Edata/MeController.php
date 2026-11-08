<?php

namespace App\Http\Controllers\Edata;

use App\Models\Employee;
use App\Models\Project;
use App\Support\Edata\Access;
use App\Support\Edata\AttendanceService;
use App\Support\Edata\Labels;
use App\Support\Edata\Moments;
use App\Support\Edata\TaskRules;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\Request;

/**
 * The employee's own space: clock, tasks, projects and history.
 */
class MeController extends BaseController
{
    public function index()
    {
        $me = $this->me();
        $company = $this->companyId();
        $employee = Employee::where('user_id', $me->id)->first();
        $service = AttendanceService::for($company);
        $state = $employee ? $service->stateFor($employee) : null;

        $monthStart = Carbon::now($service->rules()['zone'])->startOfMonth()->toDateString();
        $today = Carbon::now($service->rules()['zone'])->toDateString();
        $monthDays = $employee ? $service->report(collect([$employee]), $monthStart, $today) : collect();
        $monthTotals = $service->totals($monthDays);

        [$base, $open, $done] = $this->taskQueries();
        $overdueCount = (clone $open)->whereNotNull('end_date')->whereDate('end_date', '<', Carbon::today())->count();
        $doneCount = (clone $base)->whereNull('cancelled_at')->where(function ($q) use ($done) {
            $q->where('is_complete', 1)->orWhereIn('stage_id', $done);
        })->count();

        $tasks = (clone $open)
            ->with('project:id,project_name')
            ->orderByRaw('end_date IS NULL, end_date ASC')
            ->limit(8)
            ->get();

        $projects = Access::projects($me)
            ->whereIn('status', Labels::PROJECT_OPEN)
            ->orderByDesc('id')
            ->limit(6)
            ->get();

        return view('edata.me.index', [
            'employee' => $employee,
            'state' => $state,
            'monthTotals' => $monthTotals,
            'tasks' => $tasks,
            'openCount' => (clone $open)->count(),
            'overdueCount' => $overdueCount,
            'doneCount' => $doneCount,
            'projects' => $projects,
            'company' => $company,
            'monthDays' => $monthDays,
            'zone' => $service->rules()['zone'],
            'rules' => $service->rules(),
            'doneStage' => $done[0] ?? null,
            'journey' => Moments::journey($me, $employee, $doneCount, $company),
            'streak' => $employee ? Moments::onTimeStreak($employee, $company) : 0,
            'kudos' => Moments::recentKudos($company),
            'teammates' => Moments::teammates($company, $me->id),
        ]);
    }

    /** The current user's tasks: everything assigned, the open ones, and the done stage. */
    private function taskQueries(): array
    {
        $done = TaskRules::doneStageIds($this->companyId());
        $base = Access::tasks($this->me())->whereRaw('FIND_IN_SET(?, project_tasks.assign_to)', [$this->me()->id]);
        $open = (clone $base)->whereNull('cancelled_at')->where('is_complete', 0)->whereNotIn('stage_id', $done);

        return [$base, $open, $done];
    }

    public function attendance(Request $request)
    {
        $me = $this->me();
        $employee = Employee::where('user_id', $me->id)->first();
        if (!$employee) {
            return redirect()->route('dashboard')->with('error', 'حسابك غير مرتبط بسجل موظف، لذلك لا يمكنه تسجيل الحضور.');
        }

        $service = AttendanceService::for($this->companyId());
        $zone = $service->rules()['zone'];
        $month = $request->query('month');
        $start = $month && preg_match('/^\d{4}-\d{2}$/', $month)
            ? Carbon::parse($month . '-01', $zone)
            : Carbon::now($zone)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $todayLocal = Carbon::now($zone);
        if ($end->greaterThan($todayLocal)) {
            $end = $todayLocal;
        }

        $days = $service->report(collect([$employee]), $start->toDateString(), $end->toDateString());

        return view('edata.me.attendance', [
            'employee' => $employee,
            'state' => $service->stateFor($employee),
            'days' => $days,
            'totals' => $service->totals($days),
            'month' => $start,
            'isCurrentMonth' => $start->isSameMonth($todayLocal),
            'zone' => $zone,
        ]);
    }

    public function clockIn(Request $request)
    {
        $employee = Employee::where('user_id', $this->me()->id)->first();
        if (!$employee) {
            return back()->with('error', __('حسابك غير مرتبط بسجل موظف، لذلك لا يمكنه تسجيل الحضور.'));
        }

        $company = $this->companyId();
        $service = AttendanceService::for($company);

        try {
            $row = $service->clockIn($employee, $request->ip());
        } catch (DomainException $e) {
            return back()->with('error', __($e->getMessage()));
        }

        [, $open] = $this->taskQueries();
        $nearest = (clone $open)->orderByRaw('end_date IS NULL, end_date ASC')->value('name');

        return back()->with('ed_moment', Moments::clockIn($employee, $row, $service->stateFor($employee), (clone $open)->count(), $nearest, $company));
    }

    public function clockOut(Request $request)
    {
        $employee = Employee::where('user_id', $this->me()->id)->first();
        if (!$employee) {
            return back()->with('error', __('حسابك غير مرتبط بسجل موظف، لذلك لا يمكنه تسجيل الانصراف.'));
        }

        $company = $this->companyId();
        $service = AttendanceService::for($company);

        try {
            $service->clockOut($employee, $request->ip());
        } catch (DomainException $e) {
            return back()->with('error', __($e->getMessage()));
        }

        [$base] = $this->taskQueries();
        $doneToday = (clone $base)->whereNull('cancelled_at')->where('is_complete', 1)->whereDate('marked_at', Carbon::today($service->rules()['zone']))->count();

        return back()->with('ed_moment', Moments::clockOut($employee, $service->stateFor($employee), $doneToday, $company));
    }
}
