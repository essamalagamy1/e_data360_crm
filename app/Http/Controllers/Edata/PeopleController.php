<?php

namespace App\Http\Controllers\Edata;

use App\Http\Controllers\UserController as OldUserController;
use App\Models\AttendanceEmployee;
use App\Models\Department;
use App\Models\Designation;
use App\Models\EdataAuditLog;
use App\Models\Employee;
use App\Models\Plan;
use App\Models\ProjectUser;
use App\Models\User;
use App\Models\Utility;
use App\Support\Edata\Access;
use App\Support\Edata\AttendanceService;
use App\Support\Edata\Audit;
use App\Support\Edata\Labels;
use App\Support\Edata\TaskRules;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class PeopleController extends BaseController
{
    private const VIEW = ['manage user', 'manage employee'];

    public function index(Request $request)
    {
        $me = $this->me();
        Access::need($me, self::VIEW);

        $query = User::query()
            ->where('created_by', $this->companyId())
            ->whereNotIn('type', ['client', 'company', 'super admin'])
            ->with('employee');

        if ($request->filled('q')) {
            $q = '%' . $request->query('q') . '%';
            $query->where(fn ($w) => $w->where('name', 'like', $q)->orWhere('email', 'like', $q));
        }
        if ($request->filled('role')) {
            $query->where('type', $request->query('role'));
        }
        if ($request->filled('department')) {
            $query->whereIn('id', Employee::where('created_by', $this->companyId())->where('department_id', (int) $request->query('department'))->select('user_id'));
        }
        if ($request->filled('access')) {
            $query->where('is_enable_login', $request->query('access') === 'on' ? 1 : 0);
        }
        // People whose service ended stay in the records but leave the list unless asked for.
        $endedIds = Employee::where('created_by', $this->companyId())->where('employment_status', 'ended')->select('user_id');
        if ($request->query('employment') === 'ended') {
            $query->whereIn('id', $endedIds);
        } else {
            $query->whereNotIn('id', $endedIds);
        }

        $people = $query->orderBy('name')->paginate($this->perPage())->withQueryString();

        $company = $this->companyId();
        $employees = $people->getCollection()->pluck('employee')->filter();
        $service = AttendanceService::for($company);
        $today = Carbon::now($service->rules()['zone'])->toDateString();
        $todayRows = $employees->isNotEmpty() ? $service->report($employees, $today, $today)->keyBy('employee_id') : collect();

        $done = TaskRules::doneStageIds($company);
        $openTasks = [];
        $history = [];
        foreach ($people as $person) {
            $history[$person->id] = $this->hasHistory($person);
            $openTasks[$person->id] = Access::tasks($me)
                ->whereRaw('FIND_IN_SET(?, project_tasks.assign_to)', [$person->id])
                ->whereNull('cancelled_at')->where('is_complete', 0)->whereNotIn('stage_id', $done)
                ->count();
        }

        return view('edata.people.index', [
            'people' => $people,
            'todayRows' => $todayRows,
            'openTasks' => $openTasks,
            'history' => $history,
            'canDelete' => $me->can('delete user'),
            'showingEnded' => $request->query('employment') === 'ended',
            'roles' => $this->rolesQuery()->pluck('name'),
            'departments' => Department::where('created_by', $company)->orderBy('name')->get(['id', 'name']),
            'departmentNames' => Department::where('created_by', $company)->pluck('name', 'id'),
            'designationNames' => Designation::where('created_by', $company)->pluck('name', 'id'),
            'canCreate' => $me->can('create user'),
            'canEdit' => $me->can('edit user'),
        ]);
    }

    public function create()
    {
        Access::need($this->me(), 'create user');

        return view('edata.people.form', $this->formData(new User(), null));
    }

    public function store(Request $request)
    {
        Access::need($this->me(), 'create user');

        $data = $this->validated($request);
        $company = $this->companyId();
        $owner = User::find($company);
        $plan = $owner ? Plan::find($owner->plan) : null;

        if ($plan && $plan->max_users != -1 && $owner->countUsers() >= $plan->max_users) {
            return $this->failed('وصلت إلى الحد الأقصى للمستخدمين في الباقة الحالية.');
        }

        $role = $this->findRole((int) $data['role']);

        $user = DB::transaction(function () use ($data, $company, $role, $request) {
            $user = new User();
            $user->name = $data['name'];
            $user->email = $data['email'];
            $user->password = !empty($data['password']) ? Hash::make($data['password']) : null;
            $user->type = $role->name;
            $user->lang = User::find($company)->lang ?? 'ar';
            $user->created_by = $company;
            $user->email_verified_at = now();
            $user->is_enable_login = !empty($data['password']) ? 1 : 0;
            $user->save();
            $user->assignRole($role);
            $user->userDefaultDataRegister($user->id);

            Utility::employeeDetails($user->id, $company);
            $employee = Employee::where('user_id', $user->id)->first();
            $this->fillEmployee($employee, $data);

            $this->storeAvatar($request, $user);

            return $user;
        });

        return $this->done('تمت إضافة ' . $user->name . '.', route('edata.people.show', $user->id));
    }

    public function show(int $user)
    {
        $me = $this->me();
        $person = $this->reachable($user);

        if ($person->id !== $me->id) {
            Access::need($me, self::VIEW);
        }

        $company = $this->companyId();
        $service = AttendanceService::for($company);
        $zone = $service->rules()['zone'];
        $employee = Employee::where('user_id', $person->id)->first();

        $from = Carbon::now($zone)->startOfMonth()->toDateString();
        $to = Carbon::now($zone)->toDateString();
        $days = $employee ? $service->report(collect([$employee]), $from, $to) : collect();

        $done = TaskRules::doneStageIds($company);
        $tasks = Access::tasks($me)->whereRaw('FIND_IN_SET(?, project_tasks.assign_to)', [$person->id])->with('project:id,project_name');
        $doneCount = (clone $tasks)->whereNull('cancelled_at')->where(fn ($q) => $q->where('is_complete', 1)->orWhereIn('stage_id', $done))->count();
        $open = (clone $tasks)->whereNull('cancelled_at')->where('is_complete', 0)->whereNotIn('stage_id', $done);
        $overdue = (clone $open)->whereNotNull('end_date')->whereDate('end_date', '<', Carbon::today())->count();
        $openList = (clone $open)->orderByRaw('end_date IS NULL, end_date ASC')->limit(10)->get();
        $openCount = (clone $open)->count();

        $projectIds = ProjectUser::where('user_id', $person->id)->pluck('project_id');
        $projects = Access::projects($me)
            ->where(fn ($q) => $q->whereIn('projects.id', $projectIds)->orWhere('manager_id', $person->id))
            ->orderByRaw("FIELD(status,'in_progress','waiting_client','planning','new','on_hold','complete','canceled')")
            ->limit(10)
            ->get();

        $activity = EdataAuditLog::where('company_id', $company)
            ->where('user_id', $person->id)
            ->orderByDesc('id')
            ->limit(12)
            ->get();

        return view('edata.people.show', [
            'person' => $person,
            'employee' => $employee,
            'department' => $employee ? Department::find($employee->department_id) : null,
            'designation' => $employee ? Designation::find($employee->designation_id) : null,
            'days' => $days->take(14),
            'totals' => $service->totals($days),
            'tasks' => $openList,
            'openCount' => $openCount,
            'doneCount' => $doneCount,
            'overdue' => $overdue,
            'projects' => $projects,
            'activity' => $activity,
            'company' => $company,
            'canEdit' => $me->can('edit user') && !Access::isOwner($person),
            'isSelf' => $person->id === $me->id,
        ]);
    }

    public function edit(int $user)
    {
        Access::need($this->me(), 'edit user');
        $person = $this->reachable($user);
        abort_if(Access::isOwner($person), 403);

        return view('edata.people.form', $this->formData($person, Employee::where('user_id', $person->id)->first()));
    }

    public function update(Request $request, int $user)
    {
        Access::need($this->me(), 'edit user');
        $person = $this->reachable($user);
        abort_if(Access::isOwner($person), 403);

        $data = $this->validated($request, $person);
        $role = $this->findRole((int) $data['role']);

        DB::transaction(function () use ($person, $data, $role, $request) {
            $person->name = $data['name'];
            $person->email = $data['email'];
            $person->type = $role->name;
            $person->save();
            $person->syncRoles([$role]);

            $employee = Employee::where('user_id', $person->id)->first();
            if (!$employee) {
                Utility::employeeDetails($person->id, $this->companyId());
                $employee = Employee::where('user_id', $person->id)->first();
            }
            $employee->name = $data['name'];
            $employee->email = $data['email'];
            $this->fillEmployee($employee, $data);
            $this->storeAvatar($request, $person);
        });

        Access::flushCache();

        return $this->done('تم حفظ بيانات ' . $person->name . '.', route('edata.people.show', $person->id));
    }

    public function password(Request $request, int $user)
    {
        Access::need($this->me(), 'edit user');
        $person = $this->reachable($user);
        abort_if(Access::isOwner($person), 403);

        $data = $request->validate(
            ['password' => ['required', 'confirmed', Password::min(8)]],
            [],
            ['password' => 'كلمة المرور']
        );

        $person->password = Hash::make($data['password']);
        $person->is_enable_login = 1;
        $person->save();

        return $this->done('تم تغيير كلمة مرور ' . $person->name . '.');
    }

    public function toggleAccess(int $user)
    {
        $me = $this->me();
        Access::need($me, 'edit user');
        $person = $this->reachable($user);
        abort_if(Access::isOwner($person) || $person->id === $me->id, 403);

        if (!$person->is_enable_login && empty($person->password)) {
            return $this->failed('عيّن كلمة مرور أولاً، ثم فعّل الدخول.');
        }

        $person->is_enable_login = $person->is_enable_login ? 0 : 1;
        $person->save();

        return $this->done($person->is_enable_login ? 'تم تفعيل دخول ' . $person->name . '.' : 'تم إيقاف دخول ' . $person->name . '. سجله كله باقٍ كما هو.');
    }

    /**
     * Whether removing this person would orphan real records: attendance, leave,
     * projects, tasks or requests. Such a person gets an end of service instead.
     */
    private function hasHistory(User $person): bool
    {
        $employee = $person->relationLoaded('employee') ? $person->employee : Employee::where('user_id', $person->id)->first();
        if ($employee && (AttendanceEmployee::where('employee_id', $employee->id)->exists()
            || DB::table('leaves')->where('employee_id', $employee->id)->exists()
            || (\Illuminate\Support\Facades\Schema::hasTable('edata_requests') && DB::table('edata_requests')->where('employee_id', $employee->id)->exists()))) {
            return true;
        }

        return ProjectUser::where('user_id', $person->id)->exists()
            || DB::table('project_tasks')->whereRaw('FIND_IN_SET(?, assign_to)', [$person->id])->exists();
    }

    /** Deletes a person with no history at all, for example an account added by mistake. */
    public function destroy(int $user)
    {
        $me = $this->me();
        Access::need($me, 'delete user');
        $person = $this->reachable($user);
        abort_if(Access::isOwner($person) || $person->id === $me->id, 403);

        if ($this->hasHistory($person)) {
            return $this->failed('لهذا الموظف سجل حضور أو إجازات أو مشاريع أو مهام، لذلك لا يُحذف حتى لا يضيع السجل. استخدم «إنهاء الخدمة» بدلاً من ذلك.');
        }

        $name = $person->name;
        DB::transaction(function () use ($person) {
            Employee::where('user_id', $person->id)->delete();
            $person->delete();
        });
        Audit::record('deleted', 'employee', $user, $name, ['الاسم' => $name, 'البريد' => $person->email], null, 'حذف موظف بلا سجل');

        return $this->done('تم حذف ' . $name . '.', route('edata.people.index'));
    }

    /**
     * Ends (or restores) a person's service. Their attendance, tasks and invoices stay,
     * their login stops, and they leave the team lists and attendance boards.
     */
    public function endService(int $user)
    {
        $me = $this->me();
        Access::need($me, 'edit user');
        $person = $this->reachable($user);
        abort_if(Access::isOwner($person) || $person->id === $me->id, 403);

        $employee = Employee::where('user_id', $person->id)->first();
        abort_unless($employee, 404);

        $ending = $employee->employment_status !== 'ended';
        $old = $employee->employment_status;
        $employee->employment_status = $ending ? 'ended' : 'active';
        $employee->save();
        if ($ending) {
            $person->is_enable_login = 0;
            $person->save();
        }
        Audit::record('status_changed', 'employee', $employee->id, $person->name, ['الحالة الوظيفية' => Labels::employment($old)[0]], ['الحالة الوظيفية' => Labels::employment($employee->employment_status)[0]]);

        return $this->done($ending
            ? 'انتهت خدمة ' . $person->name . ' وتوقف دخوله. سجله كله باقٍ، وتجده في «انتهت خدمتهم».'
            : 'عاد ' . $person->name . ' إلى الخدمة. فعّل دخوله من صفحته عند الحاجة.');
    }

    /** Old delete route: refuses when the person has history, which would otherwise be orphaned. */
    public function destroyLegacy(int $user)
    {
        Access::need($this->me(), 'delete user');
        $person = $this->reachable($user);
        abort_if(Access::isOwner($person), 403);

        $hasHistory = $this->hasHistory($person);

        if ($hasHistory) {
            return $this->failed('لهذا الموظف سجل حضور أو مشاريع أو مهام. أوقف دخوله بدلاً من حذفه حتى لا يضيع السجل.');
        }

        return app(OldUserController::class)->destroy($person->id);
    }

    private function reachable(int $id): User
    {
        $company = $this->companyId();

        return User::where(fn ($q) => $q->where('created_by', $company)->orWhere('id', $company))
            ->whereNotIn('type', ['client', 'super admin'])
            ->findOrFail($id);
    }

    private function rolesQuery()
    {
        return Role::where('created_by', $this->companyId())
            ->whereNotIn('name', ['company', 'client', 'super admin'])
            ->orderBy('name');
    }

    private function findRole(int $id): Role
    {
        return $this->rolesQuery()->whereKey($id)->firstOrFail();
    }

    private function formData(User $person, ?Employee $employee): array
    {
        $company = $this->companyId();

        return [
            'person' => $person,
            'employee' => $employee,
            'roles' => $this->rolesQuery()->get(['id', 'name']),
            'currentRole' => $person->exists ? $person->roles->first()?->id : null,
            'departments' => Department::where('created_by', $company)->orderBy('name')->get(['id', 'name']),
            'designations' => Designation::where('created_by', $company)->orderBy('name')->get(['id', 'name', 'department_id']),
            'statuses' => Labels::EMPLOYMENT_STATUSES,
        ];
    }

    private function validated(Request $request, ?User $person = null): array
    {
        $company = $this->companyId();

        return $request->validate([
            'name' => 'required|string|max:120',
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')->ignore($person?->id)],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9\s\-\+\(\)]*$/'],
            'role' => ['required', 'integer'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('created_by', $company)],
            'designation_id' => ['nullable', 'integer', Rule::exists('designations', 'id')->where('created_by', $company)],
            'company_doj' => 'nullable|date',
            'employment_status' => ['required', Rule::in(array_keys(Labels::EMPLOYMENT_STATUSES))],
            'work_type' => ['nullable', Rule::in(['full', 'part'])],
            'work_days' => ['nullable', 'array', 'required_if:work_type,part'],
            'work_days.*' => 'integer|between:0,6',
            'password' => $person ? 'nullable' : ['nullable', 'confirmed', Password::min(8)],
            'avatar' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ], [], [
            'name' => 'الاسم الكامل',
            'email' => 'البريد الإلكتروني',
            'phone' => 'رقم الهاتف',
            'role' => 'الدور',
            'department_id' => 'القسم',
            'designation_id' => 'المسمى الوظيفي',
            'company_doj' => 'تاريخ التعيين',
            'employment_status' => 'الحالة الوظيفية',
            'work_type' => 'نوع الدوام',
            'work_days' => 'أيام الدوام الجزئي',
            'password' => 'كلمة المرور',
            'avatar' => 'الصورة الشخصية',
        ]);
    }

    private function fillEmployee(Employee $employee, array $data): void
    {
        $employee->phone = $data['phone'] ?? null;
        $employee->department_id = (int) ($data['department_id'] ?? 0);
        $employee->designation_id = (int) ($data['designation_id'] ?? 0);
        $employee->company_doj = $data['company_doj'] ?? null;
        $employee->employment_status = $data['employment_status'];
        if (array_key_exists('work_type', $data)) {
            $days = array_values(array_unique(array_map('intval', $data['work_days'] ?? [])));
            sort($days);
            $employee->edata_work_days = ($data['work_type'] ?? 'full') === 'part' && $days ? implode(',', $days) : null;
        }
        if (!empty($data['department_id'])) {
            $employee->branch_id = (int) (Department::find($data['department_id'])->branch_id ?? $employee->branch_id);
        }
        $employee->save();
    }

    private function storeAvatar(Request $request, User $user): void
    {
        if (!$request->hasFile('avatar')) {
            return;
        }

        $name = $user->id . '_' . time() . '.' . $request->file('avatar')->extension();
        $request->file('avatar')->storeAs('uploads/avatar', $name);
        $user->avatar = $name;
        $user->save();
    }
}
