<?php

namespace App\Http\Controllers\Edata;

use App\Http\Controllers\DashboardController as OldDashboard;
use App\Models\Customer;
use App\Models\EdataAuditLog;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectUser;
use App\Support\Edata\Access;
use App\Support\Edata\AttendanceService;
use App\Support\Edata\Fmt;
use App\Support\Edata\InvoiceMath;
use App\Support\Edata\Labels;
use App\Support\Edata\ProjectStats;
use App\Support\Edata\TaskRules;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * The management overview. Every number on it comes from a live query. There is
 * no cached or sample data, and each section only appears for a user allowed to
 * see the module behind it.
 */
class DashboardController extends BaseController
{
    public function index(Request $request)
    {
        $me = $this->me();

        // The platform owner and external clients keep their own original screens.
        if (in_array($me->type, ['super admin', 'client'], true)) {
            return app(OldDashboard::class)->account_dashboard_index();
        }

        if (Access::scope($me, 'projects') !== Access::ALL && !Access::isOwner($me)) {
            return redirect()->route('edata.me');
        }

        $company = $this->companyId();
        $zone = Fmt::timezone($company);
        $now = Carbon::now($zone);

        [$from, $to, $range] = $this->period($request, $now);
        $projectFilter = $request->filled('project') ? (int) $request->query('project') : null;
        $employeeFilter = $request->filled('employee') ? (int) $request->query('employee') : null;

        $doneStages = TaskRules::doneStageIds($company);
        $canAttendance = $me->can('manage attendance');
        $canFinance = $me->can('manage invoice') || $me->can('view finance summary');

        $data = [
            'company' => $company,
            'from' => $from,
            'to' => $to,
            'range' => $range,
            'now' => $now,
            'greetingName' => $me->name,
            'projectFilter' => $projectFilter,
            'employeeFilter' => $employeeFilter,
            'canAttendance' => $canAttendance,
            'canFinance' => $canFinance,
            'allProjects' => Access::projects($me)->orderByDesc('id')->limit(400)->get(['id', 'project_name']),
            'allStaff' => Access::staff($me)->orderBy('name')->get(['id', 'name']),
        ];

        // Projects
        $projects = Access::projects($me);
        if ($projectFilter) {
            $projects->where('projects.id', $projectFilter);
        }
        if ($employeeFilter) {
            $projects->where(function ($q) use ($employeeFilter) {
                $q->where('manager_id', $employeeFilter)
                    ->orWhereIn('projects.id', ProjectUser::where('user_id', $employeeFilter)->select('project_id'));
            });
        }
        $statusCounts = (clone $projects)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $data['projectCounts'] = [
            'total' => (int) $statusCounts->sum(),
            'active' => (int) ($statusCounts['in_progress'] ?? 0),
            'complete' => (int) ($statusCounts['complete'] ?? 0),
            'open' => (int) collect(Labels::PROJECT_OPEN)->sum(fn ($s) => $statusCounts[$s] ?? 0),
            'delayed' => (clone $projects)->whereIn('status', Labels::PROJECT_OPEN)->whereNotNull('end_date')->whereDate('end_date', '<', $now->toDateString())->count(),
        ];
        $data['statusCounts'] = $statusCounts;

        $latest = (clone $projects)->with(['manager:id,name', 'customer:id,name'])
            ->whereIn('status', Labels::PROJECT_OPEN)
            ->orderByRaw('end_date IS NULL, end_date ASC')
            ->limit(6)
            ->get();
        $data['latestProjects'] = $latest;
        $data['latestStats'] = ProjectStats::forIds($latest->pluck('id'), $company);

        // Tasks
        $tasks = Access::tasks($me)->whereNull('cancelled_at');
        if ($projectFilter) {
            $tasks->where('project_id', $projectFilter);
        }
        if ($employeeFilter) {
            $tasks->whereRaw('FIND_IN_SET(?, project_tasks.assign_to)', [$employeeFilter]);
        }
        $openTasks = (clone $tasks)->where('is_complete', 0)->whereNotIn('stage_id', $doneStages);
        $data['taskCounts'] = [
            'open' => (clone $openTasks)->count(),
            'overdue' => (clone $openTasks)->whereNotNull('end_date')->whereDate('end_date', '<', $now->toDateString())->count(),
            'done_period' => (clone $tasks)->where(fn ($q) => $q->where('is_complete', 1)->orWhereIn('stage_id', $doneStages))->whereBetween('updated_at', [$from . ' 00:00:00', $to . ' 23:59:59'])->count(),
        ];

        $attention = (clone $openTasks)
            ->with('project:id,project_name')
            ->where(function ($q) use ($now) {
                $q->where(fn ($o) => $o->whereNotNull('end_date')->whereDate('end_date', '<', $now->toDateString()))
                    ->orWhereNull('assign_to')->orWhere('assign_to', '')
                    ->orWhere(fn ($o) => $o->where('priority', 'critical')->where('progress', 0))
                    ->orWhere('updated_at', '<', now()->subDays((int) config('edata.tasks.stale_after_days')));
            })
            ->orderByRaw("FIELD(priority,'critical','high','medium','low')")
            ->orderByRaw('end_date IS NULL, end_date ASC')
            ->limit(7)
            ->get();
        $data['attention'] = $attention->map(fn ($t) => ['task' => $t, 'reason' => TaskRules::needsAttention($t, $company)]);

        // Attendance
        $data['team'] = null;
        if ($canAttendance) {
            $service = AttendanceService::for($company);
            $employeesQuery = Access::employees($me);
            if ($employeeFilter) {
                $employeesQuery->where('employees.user_id', $employeeFilter);
            }
            $employees = $employeesQuery->orderBy('name')->get();
            $board = $service->todayBoard($employees);
            $days = $service->report($employees, $from, $to);
            $data['team'] = [
                'total' => $employees->count(),
                'working' => $board['working'],
                'present' => $board['present'],
                'late' => $board['late'],
                'absent' => $board['absent'],
                'not_yet' => $board['not_yet'],
                'leave' => $board['leave'],
                'rate' => $service->totals($days)['rate'],
                'perEmployee' => $days->groupBy('employee_id')->map(fn ($rows) => [
                    'name' => $rows->first()['employee_name'],
                    'user_id' => $rows->first()['user_id'],
                    'totals' => $service->totals($rows),
                ])->sortByDesc(fn ($row) => $row['totals']['seconds'])->values(),
                'trend' => $this->attendanceTrend($days),
            ];
        }

        // Clients
        $data['clientCount'] = $me->can('manage customer') ? Access::customers($me)->whereNull('archived_at')->count() : null;

        // Money
        $data['money'] = null;
        if ($canFinance) {
            $invoiceQuery = Access::invoices($me);
            if ($projectFilter) {
                $invoiceQuery->where('project_id', $projectFilter);
            }
            $summary = InvoiceMath::summary(clone $invoiceQuery, $company);

            $dueSoon = (clone $invoiceQuery)->with('customer:id,name')
                ->whereNull('cancelled_at')->whereIn('status', [1, 2, 3])
                ->orderBy('due_date')
                ->limit(40)
                ->get();
            InvoiceMath::attach($dueSoon, $company);

            $data['money'] = [
                'summary' => $summary,
                'revenue_period' => InvoiceMath::collectedBetween($company, $from, $to),
                'revenue_month' => InvoiceMath::collectedBetween($company, $now->copy()->startOfMonth(), $now),
                'revenue_year' => InvoiceMath::collectedBetween($company, $now->copy()->startOfYear(), $now),
                'due_list' => $dueSoon->filter(fn ($i) => $i->calc['due'] > 0)->take(6)->values(),
                'trend' => $this->moneyTrend($company, $now),
            ];
        }

        // Charts
        $data['taskTrend'] = $this->taskTrend($me, $company, $doneStages, $from, $to, $projectFilter, $employeeFilter);
        $data['employeeTasks'] = $this->employeeTasks($me, $doneStages, $from, $to, $projectFilter);

        // Activity
        $data['activity'] = $me->can('view audit log')
            ? EdataAuditLog::where('company_id', $company)->orderByDesc('id')->limit(9)->get()
            : collect();

        $data['alerts'] = $this->alerts($data, $company);

        return view('edata.dashboard.index', $data);
    }

    /**
     * The old HR page ran for over 50 seconds for an ordinary employee. Employees get
     * their own space instead; only the account owner still opens the original page.
     */
    public function hrm()
    {
        if (Access::isOwner($this->me())) {
            return app(OldDashboard::class)->hrm_dashboard_index();
        }

        return redirect()->route('edata.me');
    }

    /** @return array{0:string,1:string,2:string} */
    private function period(Request $request, Carbon $now): array
    {
        $range = (string) $request->query('range', '30');
        $to = $now->toDateString();

        if ($range === 'custom' && $request->filled('from') && $request->filled('to')) {
            return [(string) $request->query('from'), (string) $request->query('to'), 'custom'];
        }

        return match ($range) {
            '7' => [$now->copy()->subDays(6)->toDateString(), $to, '7'],
            'month' => [$now->copy()->startOfMonth()->toDateString(), $to, 'month'],
            'year' => [$now->copy()->startOfYear()->toDateString(), $to, 'year'],
            default => [$now->copy()->subDays(29)->toDateString(), $to, '30'],
        };
    }

    private function attendanceTrend($days): array
    {
        return $days->groupBy('date')
            ->map(function ($rows) {
                $expected = $rows->whereIn('status', ['present', 'late', 'overtime', 'working', 'missing_out', 'absent'])->count();
                $attended = $rows->whereIn('status', ['present', 'late', 'overtime', 'working', 'missing_out'])->count();

                return $expected > 0 ? (int) round($attended / $expected * 100) : null;
            })
            ->filter(fn ($v) => $v !== null)
            ->sortKeys()
            ->all();
    }

    private function moneyTrend(int $company, Carbon $now): array
    {
        $start = $now->copy()->startOfMonth()->subMonths(5);
        $months = [];
        for ($i = 0; $i < 6; $i++) {
            $key = $start->copy()->addMonths($i)->format('Y-m');
            $months[$key] = ['billed' => 0.0, 'collected' => 0.0];
        }

        $invoices = Invoice::where('created_by', $company)
            ->whereNull('cancelled_at')
            ->where('status', '!=', 0)
            ->whereDate('issue_date', '>=', $start->toDateString())
            ->get();
        InvoiceMath::attach($invoices, $company);
        foreach ($invoices as $invoice) {
            $key = Carbon::parse($invoice->issue_date)->format('Y-m');
            if (isset($months[$key])) {
                $months[$key]['billed'] += $invoice->calc['total'];
            }
        }

        $payments = InvoicePayment::whereIn('invoice_id', Invoice::where('created_by', $company)->select('id'))
            ->whereDate('date', '>=', $start->toDateString())
            ->get(['date', 'amount']);
        foreach ($payments as $payment) {
            $key = Carbon::parse($payment->date)->format('Y-m');
            if (isset($months[$key])) {
                $months[$key]['collected'] += (float) $payment->amount;
            }
        }

        return $months;
    }

    private function taskTrend($me, int $company, array $done, string $from, string $to, ?int $project, ?int $employee): array
    {
        $base = Access::tasks($me)->whereNull('cancelled_at');
        if ($project) {
            $base->where('project_id', $project);
        }
        if ($employee) {
            $base->whereRaw('FIND_IN_SET(?, project_tasks.assign_to)', [$employee]);
        }

        $days = Carbon::parse($from)->diffInDays(Carbon::parse($to), true);
        $format = $days > 62 ? '%Y-%m' : '%Y-%m-%d';

        $created = (clone $base)->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->selectRaw("DATE_FORMAT(created_at, '$format') as bucket, COUNT(*) as total")
            ->groupBy('bucket')->pluck('total', 'bucket');

        $completed = (clone $base)->where(fn ($q) => $q->where('is_complete', 1)->orWhereIn('stage_id', $done))
            ->whereBetween('updated_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->selectRaw("DATE_FORMAT(updated_at, '$format') as bucket, COUNT(*) as total")
            ->groupBy('bucket')->pluck('total', 'bucket');

        $buckets = [];
        $cursor = Carbon::parse($from);
        $end = Carbon::parse($to);
        while ($cursor->lte($end)) {
            $key = $days > 62 ? $cursor->format('Y-m') : $cursor->format('Y-m-d');
            $buckets[$key] = ['created' => (int) ($created[$key] ?? 0), 'completed' => (int) ($completed[$key] ?? 0)];
            $days > 62 ? $cursor->addMonth() : $cursor->addDay();
        }

        return $buckets;
    }

    private function employeeTasks($me, array $done, string $from, string $to, ?int $project): array
    {
        $staff = Access::staff($me)->whereNotIn('type', ['company'])->pluck('name', 'id');

        // One query for everyone. assign_to is a comma list, so it is counted in PHP.
        $tasks = Access::tasks($me)
            ->whereNull('cancelled_at')
            ->where(function ($q) use ($done, $from, $to) {
                $q->where(fn ($open) => $open->where('is_complete', 0)->whereNotIn('stage_id', $done))
                    ->orWhereBetween('updated_at', [$from . ' 00:00:00', $to . ' 23:59:59']);
            })
            ->when($project, fn ($q) => $q->where('project_id', $project))
            ->get(['assign_to', 'is_complete', 'stage_id']);

        $counts = [];
        foreach ($tasks as $task) {
            $isDone = (int) $task->is_complete === 1 || in_array((int) $task->stage_id, $done, true);
            foreach (explode(',', (string) $task->assign_to) as $userId) {
                if (isset($staff[(int) $userId])) {
                    $counts[(int) $userId][$isDone ? 'completed' : 'open'] = ($counts[(int) $userId][$isDone ? 'completed' : 'open'] ?? 0) + 1;
                }
            }
        }

        $result = [];
        foreach ($counts as $userId => $count) {
            $result[] = ['id' => $userId, 'name' => $staff[$userId], 'completed' => $count['completed'] ?? 0, 'open' => $count['open'] ?? 0];
        }

        usort($result, fn ($a, $b) => $b['completed'] <=> $a['completed']);

        return array_slice($result, 0, 10);
    }

    private function alerts(array $data, int $company): array
    {
        $alerts = [];

        if ($data['taskCounts']['overdue'] > 0) {
            $alerts[] = ['tone' => 'bad', 'text' => __(':n مهمة تأخرت عن موعدها', ['n' => $data['taskCounts']['overdue']]), 'url' => route('edata.tasks.index', ['state' => 'overdue'])];
        }
        if ($data['projectCounts']['delayed'] > 0) {
            $alerts[] = ['tone' => 'bad', 'text' => __(':n مشروع تجاوز تاريخ التسليم', ['n' => $data['projectCounts']['delayed']]), 'url' => route('projects.index', ['delayed' => 1, 'view' => 'table'])];
        }
        if ($data['money'] && ($data['money']['summary']['states']['overdue'] ?? 0) > 0) {
            $alerts[] = ['tone' => 'warn', 'text' => __(':n فاتورة متأخرة بقيمة :amount', ['n' => $data['money']['summary']['states']['overdue'], 'amount' => Fmt::money($data['money']['summary']['overdue_amount'], $company)]), 'url' => route('edata.invoices.index', ['state' => 'overdue'])];
        }
        if ($data['team'] && $data['team']['absent']->count() > 0) {
            $alerts[] = ['tone' => 'warn', 'text' => __(':n موظف غائب اليوم', ['n' => $data['team']['absent']->count()]), 'url' => route('edata.attendance.index', ['status' => 'absent', 'from' => $data['now']->toDateString(), 'to' => $data['now']->toDateString()])];
        }
        if ($data['team'] && count($data['team']['late']) > 0) {
            $alerts[] = ['tone' => 'warn', 'text' => __(':n موظف تأخر اليوم', ['n' => count($data['team']['late'])]), 'url' => route('edata.attendance.index', ['status' => 'late', 'from' => $data['now']->toDateString(), 'to' => $data['now']->toDateString()])];
        }

        return $alerts;
    }
}
