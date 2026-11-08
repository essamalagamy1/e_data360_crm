<?php

namespace App\Http\Controllers\Edata;

use App\Models\ActivityLog;
use App\Models\EdataAuditLog;
use App\Models\ProjectTask;
use App\Models\ProjectUser;
use App\Models\TaskComment;
use App\Models\User;
use App\Support\Edata\Access;
use App\Support\Edata\Labels;
use App\Support\Edata\TaskRules;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TaskController extends BaseController
{
    public function index(Request $request)
    {
        $me = $this->me();
        $company = $this->companyId();
        $view = $request->query('view') === 'board' ? 'board' : 'table';
        $stages = TaskRules::stages($company);
        $done = TaskRules::doneStageIds($company);

        $base = $this->filtered($request, $done);

        if ($view === 'board') {
            $columns = [];
            foreach ($stages as $stage) {
                $column = (clone $base)->where('stage_id', $stage->id)->whereNull('cancelled_at');
                $count = (clone $column)->count();
                $isDone = in_array((int) $stage->id, $done, true);
                $columns[] = [
                    'stage' => $stage,
                    'name' => TaskRules::stageName($stage->name),
                    'count' => $count,
                    'isDone' => $isDone,
                    'tasks' => $column->with('project:id,project_name')
                        ->when($isDone, fn ($q) => $q->orderByDesc('updated_at'), fn ($q) => $q->orderByRaw('end_date IS NULL, end_date ASC'))
                        ->limit(40)
                        ->get(),
                ];
            }
            $paginator = null;
        } else {
            $columns = [];
            $paginator = (clone $base)->with('project:id,project_name')
                ->orderByRaw('cancelled_at IS NOT NULL, is_complete ASC, end_date IS NULL, end_date ASC')
                ->orderByDesc('id')
                ->paginate($this->perPage())
                ->withQueryString();
        }

        $scopeQuery = Access::tasks($me)->whereNull('cancelled_at');
        $open = (clone $scopeQuery)->where('is_complete', 0)->whereNotIn('stage_id', $done);

        $userNames = Access::staff($me)->get(['id', 'name', 'avatar'])->keyBy('id');

        return view('edata.tasks.index', [
            'view' => $view,
            'paginator' => $paginator,
            'columns' => $columns,
            'stages' => $stages,
            'company' => $company,
            'users' => $userNames,
            'projects' => Access::projects($me)->whereIn('status', Labels::PROJECT_OPEN)->orderBy('project_name')->get(['id', 'project_name']),
            'stats' => [
                'open' => (clone $open)->count(),
                'overdue' => (clone $open)->whereNotNull('end_date')->whereDate('end_date', '<', Carbon::today())->count(),
                'stale' => (clone $open)->where('updated_at', '<', now()->subDays((int) config('edata.tasks.stale_after_days')))->count(),
                'mine' => (clone $open)->whereRaw('FIND_IN_SET(?, project_tasks.assign_to)', [$me->id])->count(),
            ],
            'canCreate' => $me->can('create project task'),
            'canEdit' => $me->can('edit project task'),
            'me' => $me,
        ]);
    }

    private function filtered(Request $request, array $done)
    {
        $me = $this->me();
        $query = Access::tasks($me);

        if ($request->filled('q')) {
            $query->where('project_tasks.name', 'like', '%' . $request->query('q') . '%');
        }
        if ($request->filled('project')) {
            $query->where('project_tasks.project_id', (int) $request->query('project'));
        }
        if ($request->filled('assignee')) {
            $query->whereRaw('FIND_IN_SET(?, project_tasks.assign_to)', [(int) $request->query('assignee')]);
        }
        if ($request->filled('priority')) {
            $query->where('project_tasks.priority', $request->query('priority'));
        }
        if ($request->filled('stage')) {
            $query->where('project_tasks.stage_id', (int) $request->query('stage'));
        }

        $open = fn ($q) => $q->whereNull('cancelled_at')->where('is_complete', 0)->whereNotIn('stage_id', $done);

        switch ($request->query('state')) {
            case 'mine':
                $query->whereRaw('FIND_IN_SET(?, project_tasks.assign_to)', [$me->id]);
                $open($query);
                break;
            case 'overdue':
                $open($query);
                $query->whereNotNull('end_date')->whereDate('end_date', '<', Carbon::today());
                break;
            case 'stale':
                $open($query);
                $query->where('project_tasks.updated_at', '<', now()->subDays((int) config('edata.tasks.stale_after_days')));
                break;
            case 'attention':
                $open($query);
                $query->where(function ($w) {
                    $w->where(fn ($o) => $o->whereNotNull('end_date')->whereDate('end_date', '<', Carbon::today()))
                        ->orWhereNull('assign_to')->orWhere('assign_to', '')
                        ->orWhere(fn ($o) => $o->where('priority', 'critical')->where('progress', 0));
                });
                break;
            case 'done':
                $query->whereNull('cancelled_at')->where(fn ($w) => $w->where('is_complete', 1)->orWhereIn('stage_id', $done));
                break;
            case 'cancelled':
                $query->whereNotNull('cancelled_at');
                break;
        }

        return $query;
    }

    public function create(Request $request)
    {
        Access::need($this->me(), 'create project task');

        return view('edata.tasks.form', $this->formData(new ProjectTask([
            'priority' => 'medium',
            'project_id' => (int) $request->query('project', 0),
            'start_date' => Carbon::today()->toDateString(),
        ])));
    }

    public function store(Request $request)
    {
        $me = $this->me();
        Access::need($me, 'create project task');
        $data = $this->validated($request);
        $company = $this->companyId();

        $task = DB::transaction(function () use ($data, $company, $me) {
            $task = new ProjectTask();
            $task->stage_id = TaskRules::firstStageId($company);
            $task->created_by = $company;
            $this->fill($task, $data);
            $task->save();

            ActivityLog::create([
                'user_id' => $me->id,
                'project_id' => $task->project_id,
                'task_id' => $task->id,
                'log_type' => 'Create Task',
                'remark' => json_encode(['title' => $task->name]),
            ]);

            return $task;
        });

        return $this->done('تم إنشاء المهمة.', route('edata.tasks.show', $task->id));
    }

    public function show(int $task)
    {
        $me = $this->me();
        $company = $this->companyId();
        $record = Access::tasks($me)->with('project')->findOrFail($task);

        $comments = TaskComment::where('task_id', $record->id)->orderByDesc('id')->get();
        $commenters = User::whereIn('id', $comments->pluck('user_id')->unique())->get(['id', 'name', 'avatar'])->keyBy('id');

        $history = EdataAuditLog::where('company_id', $company)
            ->where('entity_type', 'task')->where('entity_id', $record->id)
            ->orderByDesc('id')->limit(30)->get();

        return view('edata.tasks.show', [
            'task' => $record,
            'assignees' => User::whereIn('id', TaskRules::assigneeIds($record))->get(['id', 'name', 'avatar']),
            'comments' => $comments,
            'commenters' => $commenters,
            'history' => $history,
            'stages' => TaskRules::stages($company),
            'state' => TaskRules::state($record, $company),
            'company' => $company,
            'checklist' => $record->checklist,
            'canEdit' => $me->can('edit project task'),
            'canMove' => $this->canWork($record),
            'canDelete' => $me->can('delete project task'),
        ]);
    }

    public function edit(int $task)
    {
        Access::need($this->me(), 'edit project task');

        return view('edata.tasks.form', $this->formData(Access::tasks($this->me())->findOrFail($task)));
    }

    public function update(Request $request, int $task)
    {
        Access::need($this->me(), 'edit project task');
        $record = Access::tasks($this->me())->findOrFail($task);

        $this->fill($record, $this->validated($request));
        $record->save();

        return $this->done('تم حفظ المهمة.', route('edata.tasks.show', $record->id));
    }

    /** Moves a task to another column. Assignees may always update their own work. */
    public function move(Request $request, int $task)
    {
        $record = Access::tasks($this->me())->findOrFail($task);
        abort_unless($this->canWork($record), 403);

        $company = $this->companyId();
        $data = $request->validate([
            'stage_id' => ['required', 'integer', Rule::exists('task_stages', 'id')->where('created_by', $company)],
        ]);

        $record->stage_id = (int) $data['stage_id'];
        $this->syncCompletion($record, $company);
        $record->save();

        if ($request->expectsJson()) {
            $state = TaskRules::state($record, $company);

            return response()->json(['ok' => true, 'label' => $state[0], 'tone' => $state[1], 'done' => TaskRules::isDone($record, $company)]);
        }

        return $this->done('تم نقل المهمة إلى ' . TaskRules::state($record, $company)[0] . '.')
            ->with('ed_cheer', TaskRules::isDone($record, $company) ? 1 : null);
    }

    public function progress(Request $request, int $task)
    {
        $record = Access::tasks($this->me())->findOrFail($task);
        abort_unless($this->canWork($record), 403);

        $data = $request->validate(['progress' => 'required|integer|min:0|max:100'], [], ['progress' => 'نسبة الإنجاز']);
        $record->progress = (string) $data['progress'];
        $record->save();

        return $this->done('تم تحديث نسبة الإنجاز إلى ' . $data['progress'] . '%.');
    }

    public function cancel(int $task)
    {
        Access::need($this->me(), 'edit project task');
        $record = Access::tasks($this->me())->findOrFail($task);

        $record->cancelled_at = $record->cancelled_at ? null : now();
        $record->save();

        return $this->done($record->cancelled_at ? 'تم إلغاء المهمة.' : 'تمت إعادة فتح المهمة.');
    }

    public function comment(Request $request, int $task)
    {
        $me = $this->me();
        $record = Access::tasks($me)->findOrFail($task);
        $data = $request->validate(['comment' => 'required|string|max:3000'], [], ['comment' => 'الملاحظة']);

        TaskComment::create([
            'comment' => $data['comment'],
            'task_id' => $record->id,
            'user_id' => $me->id,
            'user_type' => $me->type,
            'created_by' => $me->id,
        ]);

        // Commenting counts as activity on the task.
        $record->touch();

        return $this->done('تمت إضافة الملاحظة.');
    }

    public function destroy(int $task)
    {
        Access::need($this->me(), 'delete project task');
        $record = Access::tasks($this->me())->findOrFail($task);
        $projectId = $record->project_id;

        ProjectTask::deleteTask([$record->id]);

        return $this->done('تم حذف المهمة.', route('projects.show', [$projectId, 'tab' => 'tasks']));
    }

    private function canWork(ProjectTask $task): bool
    {
        $me = $this->me();

        return $me->can('edit project task') || in_array((int) $me->id, TaskRules::assigneeIds($task), true);
    }

    private function syncCompletion(ProjectTask $task, int $company): void
    {
        if (in_array((int) $task->stage_id, TaskRules::doneStageIds($company), true)) {
            $task->is_complete = 1;
            $task->marked_at = Carbon::today()->toDateString();
            $task->progress = '100';
        } else {
            $task->is_complete = 0;
            $task->marked_at = null;
            if ((int) $task->progress === 100) {
                $task->progress = '0';
            }
        }
    }

    private function formData(ProjectTask $task): array
    {
        $me = $this->me();
        $company = $this->companyId();

        return [
            'task' => $task,
            'projects' => Access::projects($me)
                ->where(fn ($q) => $q->whereIn('status', Labels::PROJECT_OPEN)->orWhere('id', $task->project_id))
                ->orderBy('project_name')
                ->get(['id', 'project_name']),
            'staff' => Access::staff($me)->orderBy('name')->get(['id', 'name']),
            'stages' => TaskRules::stages($company),
            'assigned' => TaskRules::assigneeIds($task),
            'priorities' => Labels::TASK_PRIORITIES,
        ];
    }

    private function validated(Request $request): array
    {
        $company = $this->companyId();
        $projectIds = Access::projects($this->me())->pluck('projects.id')->all();

        return $request->validate([
            'name' => 'required|string|max:191',
            'description' => 'nullable|string|max:5000',
            'project_id' => ['required', 'integer', Rule::in($projectIds)],
            'assign_to' => 'nullable|array',
            'assign_to.*' => 'integer',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'priority' => ['required', Rule::in(array_keys(Labels::TASK_PRIORITIES))],
            'stage_id' => ['nullable', 'integer', Rule::exists('task_stages', 'id')->where('created_by', $company)],
            'estimated_hrs' => 'nullable|integer|min:0|max:100000',
        ], [], [
            'name' => 'اسم المهمة',
            'description' => 'الوصف',
            'project_id' => 'المشروع',
            'assign_to' => 'الموظف المسؤول',
            'start_date' => 'تاريخ البداية',
            'end_date' => 'تاريخ التسليم',
            'priority' => 'الأولوية',
            'stage_id' => 'الحالة',
            'estimated_hrs' => 'الساعات المقدرة',
        ]);
    }

    private function fill(ProjectTask $task, array $data): void
    {
        $company = $this->companyId();
        $staff = Access::staff($this->me())->pluck('id')->all();
        $assignees = array_values(array_intersect(array_map('intval', $data['assign_to'] ?? []), $staff));

        $task->name = $data['name'];
        $task->description = $data['description'] ?? null;
        $task->project_id = (int) $data['project_id'];
        $task->assign_to = implode(',', $assignees);
        $task->start_date = $data['start_date'] ?? null;
        $task->end_date = $data['end_date'] ?? null;
        $task->priority = $data['priority'];
        $task->priority_color = ProjectTask::$priority_color[$data['priority']] ?? 'primary';
        $task->estimated_hrs = (int) ($data['estimated_hrs'] ?? 0);
        if (!empty($data['stage_id'])) {
            $task->stage_id = (int) $data['stage_id'];
        }
        $this->syncCompletion($task, $company);

        // Everyone assigned to a task must be able to open its project.
        if ($task->project_id) {
            foreach ($assignees as $userId) {
                ProjectUser::firstOrCreate(['project_id' => $task->project_id, 'user_id' => $userId], ['invited_by' => $this->me()->id]);
            }
        }
    }
}
