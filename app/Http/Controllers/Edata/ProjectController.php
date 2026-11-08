<?php

namespace App\Http\Controllers\Edata;

use App\Models\Customer;
use App\Models\EdataAuditLog;
use App\Models\EdataNote;
use App\Models\Invoice;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectUser;
use App\Models\User;
use App\Support\Edata\Access;
use App\Support\Edata\Audit;
use App\Support\Edata\InvoiceMath;
use App\Support\Edata\Labels;
use App\Support\Edata\ProjectStats;
use App\Support\Edata\TaskRules;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProjectController extends BaseController
{
    public function index(Request $request)
    {
        $me = $this->me();
        $company = $this->companyId();

        $view = in_array($request->query('view'), ['cards', 'table', 'board', 'timeline'], true) ? $request->query('view') : 'cards';
        $query = $this->filtered($request);

        if ($view === 'board' || $view === 'timeline') {
            // These views are for work in motion: finished history would bury it.
            if (!$request->filled('status')) {
                $query->whereIn('projects.status', Labels::PROJECT_OPEN);
            }
            $projects = $query->with(['manager:id,name', 'customer:id,name', 'users' => fn ($q) => $q->select('users.id', 'users.name', 'users.avatar')])
                ->orderByRaw('end_date IS NULL, end_date ASC')
                ->limit($view === 'board' ? 120 : 50)
                ->get();
            $paginator = null;
            $list = $projects;
        } else {
            $paginator = $query->with(['manager:id,name', 'customer:id,name', 'users' => fn ($q) => $q->select('users.id', 'users.name', 'users.avatar')])
                ->orderByRaw("FIELD(projects.status,'in_progress','waiting_client','planning','new','on_hold','complete','canceled')")
                ->orderByDesc('projects.id')
                ->paginate($view === 'cards' ? 18 : $this->perPage())
                ->withQueryString();
            $list = $paginator->getCollection();
        }

        $stats = ProjectStats::forIds($list->pluck('id'), $company);

        $counts = Access::projects($me)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $delayed = Access::projects($me)->whereIn('status', Labels::PROJECT_OPEN)->whereNotNull('end_date')->whereDate('end_date', '<', Carbon::today())->count();

        return view('edata.projects.index', [
            'view' => $view,
            'paginator' => $paginator,
            'projects' => $list,
            'stats' => $stats,
            'counts' => $counts,
            'delayed' => $delayed,
            'total' => (int) $counts->sum(),
            'customers' => Access::customers($me)->whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
            'staff' => Access::staff($me)->orderBy('name')->get(['id', 'name']),
            'canCreate' => $me->can('create project'),
            'canEdit' => $me->can('edit project'),
            'company' => $company,
        ]);
    }

    private function filtered(Request $request)
    {
        $query = Access::projects($this->me());

        if ($request->filled('q')) {
            $query->where('projects.project_name', 'like', '%' . $request->query('q') . '%');
        }
        if ($request->filled('status')) {
            $query->where('projects.status', $request->query('status'));
        }
        if ($request->filled('customer')) {
            $query->where('projects.customer_id', (int) $request->query('customer'));
        }
        if ($request->filled('manager')) {
            $query->where('projects.manager_id', (int) $request->query('manager'));
        }
        if ($request->filled('member')) {
            $query->whereIn('projects.id', ProjectUser::where('user_id', (int) $request->query('member'))->select('project_id'));
        }
        if ($request->query('delayed') === '1') {
            $query->whereIn('projects.status', Labels::PROJECT_OPEN)->whereNotNull('projects.end_date')->whereDate('projects.end_date', '<', Carbon::today());
        }

        return $query;
    }

    public function create(Request $request)
    {
        Access::need($this->me(), 'create project');

        return view('edata.projects.form', $this->formData(new Project([
            'status' => 'new',
            'start_date' => Carbon::today()->toDateString(),
        ]), (int) $request->query('customer', 0)));
    }

    public function store(Request $request)
    {
        $me = $this->me();
        Access::need($me, 'create project');
        $data = $this->validated($request);
        $company = $this->companyId();

        $project = DB::transaction(function () use ($data, $company, $me) {
            $project = new Project();
            $this->fill($project, $data);
            $project->client_id = 0;
            $project->created_by = $company;
            $project->copylinksetting = '{"member":"on","milestone":"off","basic_details":"on","activity":"off","attachment":"on","bug_report":"on","task":"off","tracker_details":"off","timesheet":"off","password_protected":"off"}';
            $project->save();

            $members = array_merge([$company, $me->id], $data['team'] ?? []);
            if (!empty($data['manager_id'])) {
                $members[] = (int) $data['manager_id'];
            }
            foreach (array_unique(array_map('intval', $members)) as $userId) {
                ProjectUser::firstOrCreate(['project_id' => $project->id, 'user_id' => $userId], ['invited_by' => $me->id]);
            }

            return $project;
        });

        return $this->done('تم إنشاء مشروع ' . $project->project_name . '.', route('projects.show', $project->id));
    }

    public function show(int $project)
    {
        $me = $this->me();
        $company = $this->companyId();
        $record = Access::projects($me)->with(['manager', 'customer'])->findOrFail($project);

        $stats = ProjectStats::forIds(collect([$record->id]), $company)[$record->id];
        $progress = ProjectStats::progress($record, $stats);

        $tasks = Access::tasks($me)->where('project_id', $record->id)
            ->orderByRaw('cancelled_at IS NOT NULL, is_complete ASC, end_date IS NULL, end_date ASC')
            ->get();

        $team = User::whereIn('id', ProjectUser::where('project_id', $record->id)->select('user_id'))
            ->whereNotIn('type', ['client', 'super admin'])
            ->orderBy('name')
            ->get(['id', 'name', 'avatar', 'type']);

        $doneStages = TaskRules::doneStageIds($company);
        $workload = [];
        foreach ($tasks as $task) {
            foreach (TaskRules::assigneeIds($task) as $userId) {
                $workload[$userId]['total'] = ($workload[$userId]['total'] ?? 0) + 1;
                if ($task->is_complete || in_array((int) $task->stage_id, $doneStages, true)) {
                    $workload[$userId]['done'] = ($workload[$userId]['done'] ?? 0) + 1;
                }
            }
        }

        $canSeeInvoices = $me->can('manage invoice');
        $invoices = $canSeeInvoices ? Access::invoices($me)->where('project_id', $record->id)->with('customer:id,name')->orderByDesc('issue_date')->get() : collect();
        InvoiceMath::attach($invoices, $company);

        $notes = EdataNote::where('company_id', $company)->where('notable_type', 'project')->where('notable_id', $record->id)
            ->with('author:id,name')->orderByDesc('id')->get();

        $activity = EdataAuditLog::where('company_id', $company)
            ->where(function ($q) use ($record, $tasks) {
                $q->where(fn ($w) => $w->where('entity_type', 'project')->where('entity_id', $record->id))
                    ->orWhere(fn ($w) => $w->where('entity_type', 'task')->whereIn('entity_id', $tasks->pluck('id')));
            })
            ->orderByDesc('id')
            ->limit(40)
            ->get();

        $today = Carbon::today();
        $deadlines = $tasks
            ->filter(fn ($t) => $t->end_date && !$t->cancelled_at && !$t->is_complete && !in_array((int) $t->stage_id, $doneStages, true))
            ->sortBy('end_date')
            ->take(15)
            ->values();
        $milestones = Milestone::where('project_id', $record->id)->orderBy('due_date')->get();

        $editable = $me->can('edit project') && (Access::scope($me, 'projects') === Access::ALL
            || (int) $record->manager_id === (int) $me->id
            || $team->contains('id', $me->id));

        return view('edata.projects.show', [
            'project' => $record,
            'stats' => $stats,
            'progress' => $progress,
            'tasks' => $tasks,
            'team' => $team,
            'workload' => $workload,
            'invoices' => $invoices,
            'canSeeInvoices' => $canSeeInvoices,
            'invoiceMoney' => [
                'total' => $invoices->filter(fn ($i) => !$i->cancelled_at && (int) $i->status !== 0)->sum(fn ($i) => $i->calc['total']),
                'paid' => $invoices->filter(fn ($i) => !$i->cancelled_at)->sum(fn ($i) => $i->calc['paid']),
            ],
            'notes' => $notes,
            'activity' => $activity,
            'deadlines' => $deadlines,
            'milestones' => $milestones,
            'today' => $today,
            'company' => $company,
            'staff' => Access::staff($me)->orderBy('name')->get(['id', 'name']),
            'canEdit' => $editable,
            'canDelete' => $me->can('delete project'),
            'canAddTask' => $me->can('create project task'),
            'canInvoice' => $me->can('create invoice'),
            'tab' => request('tab', 'overview'),
        ]);
    }

    public function edit(int $project)
    {
        Access::need($this->me(), 'edit project');
        $record = Access::projects($this->me())->findOrFail($project);

        return view('edata.projects.form', $this->formData($record));
    }

    public function update(Request $request, int $project)
    {
        Access::need($this->me(), 'edit project');
        $record = Access::projects($this->me())->findOrFail($project);
        $data = $this->validated($request);

        DB::transaction(function () use ($record, $data) {
            $this->fill($record, $data);
            $record->save();

            foreach (array_filter(array_merge($data['team'] ?? [], [$data['manager_id'] ?? null])) as $userId) {
                ProjectUser::firstOrCreate(['project_id' => $record->id, 'user_id' => (int) $userId], ['invited_by' => $this->me()->id]);
            }
        });

        return $this->done('تم حفظ المشروع.', route('projects.show', $record->id));
    }

    public function status(Request $request, int $project)
    {
        Access::need($this->me(), 'edit project');
        $record = Access::projects($this->me())->findOrFail($project);

        $data = $request->validate(['status' => ['required', Rule::in(array_keys(Labels::PROJECT_STATUSES))]]);

        $record->status = $data['status'];
        $record->save();

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'label' => Labels::project($record->status)[0], 'tone' => Labels::project($record->status)[1]]);
        }

        return $this->done('تم تحديث حالة المشروع إلى ' . Labels::project($record->status)[0] . '.');
    }

    public function addMember(Request $request, int $project)
    {
        Access::need($this->me(), 'edit project');
        $record = Access::projects($this->me())->findOrFail($project);

        $data = $request->validate(['user_id' => 'required|integer'], [], ['user_id' => 'الموظف']);
        $member = Access::staff($this->me())->findOrFail($data['user_id']);

        ProjectUser::firstOrCreate(['project_id' => $record->id, 'user_id' => $member->id], ['invited_by' => $this->me()->id]);
        Audit::record('updated', 'project', $record->id, $record->project_name, null, ['الفريق' => 'إضافة ' . $member->name]);

        return $this->done('تمت إضافة ' . $member->name . ' إلى الفريق.', route('projects.show', [$record->id, 'tab' => 'team']));
    }

    public function removeMember(int $project, int $user)
    {
        Access::need($this->me(), 'edit project');
        $record = Access::projects($this->me())->findOrFail($project);

        if ($user === $this->companyId()) {
            return $this->failed('لا يمكن إزالة صاحب الحساب من المشروع.');
        }

        $member = User::find($user);
        ProjectUser::where('project_id', $record->id)->where('user_id', $user)->delete();
        Audit::record('updated', 'project', $record->id, $record->project_name, ['الفريق' => 'إزالة ' . ($member->name ?? $user)], null);

        return $this->done('تمت الإزالة من الفريق. مهامه السابقة باقية كما هي.', route('projects.show', [$record->id, 'tab' => 'team']));
    }

    public function destroy(int $project)
    {
        Access::need($this->me(), 'delete project');
        $record = Access::projects($this->me())->findOrFail($project);

        $hasHistory = ProjectTask::where('project_id', $record->id)->exists()
            || Invoice::where('project_id', $record->id)->exists();

        if ($hasHistory) {
            return $this->failed('هذا المشروع له مهام أو فواتير. غيّر حالته إلى ملغي بدلاً من حذفه حتى لا يضيع السجل.');
        }

        DB::transaction(function () use ($record) {
            ProjectUser::where('project_id', $record->id)->delete();
            $record->delete();
        });

        return $this->done('تم حذف المشروع.', route('projects.index'));
    }

    private function formData(Project $project, int $customerId = 0): array
    {
        $me = $this->me();

        return [
            'project' => $project,
            'customers' => Access::customers($me)->where(fn ($q) => $q->whereNull('archived_at')->orWhere('id', $project->customer_id))->orderBy('name')->get(['id', 'name']),
            'staff' => Access::staff($me)->orderBy('name')->get(['id', 'name']),
            'team' => $project->exists ? ProjectUser::where('project_id', $project->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all() : [],
            'statuses' => Labels::PROJECT_STATUSES,
            'selectedCustomer' => $customerId ?: $project->customer_id,
        ];
    }

    private function validated(Request $request): array
    {
        $company = $this->companyId();

        return $request->validate([
            'project_name' => 'required|string|max:191',
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('created_by', $company)],
            'description' => 'nullable|string|max:5000',
            'manager_id' => ['nullable', 'integer'],
            'team' => 'nullable|array',
            'team.*' => 'integer',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'budget' => 'nullable|integer|min:0',
            'status' => ['required', Rule::in(array_keys(Labels::PROJECT_STATUSES))],
            'manual_progress' => 'nullable|integer|min:0|max:100',
        ], [], [
            'project_name' => 'اسم المشروع',
            'customer_id' => 'العميل',
            'description' => 'وصف المشروع',
            'manager_id' => 'مدير المشروع',
            'team' => 'أعضاء الفريق',
            'start_date' => 'تاريخ البداية',
            'end_date' => 'تاريخ التسليم المتوقع',
            'budget' => 'قيمة المشروع',
            'status' => 'حالة المشروع',
            'manual_progress' => 'نسبة الإنجاز',
        ]);
    }

    private function fill(Project $project, array $data): void
    {
        $staffIds = Access::staff($this->me())->pluck('id')->all();

        $project->project_name = $data['project_name'];
        $project->customer_id = $data['customer_id'] ?? null;
        $project->description = $data['description'] ?? null;
        $project->manager_id = in_array((int) ($data['manager_id'] ?? 0), $staffIds, true) ? (int) $data['manager_id'] : null;
        $project->start_date = $data['start_date'] ?? null;
        $project->end_date = $data['end_date'] ?? null;
        $project->budget = (int) ($data['budget'] ?? 0);
        $project->status = $data['status'];
        $project->manual_progress = $data['manual_progress'] ?? null;
    }
}
