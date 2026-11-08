<?php

namespace App\Http\Controllers\Edata;

use App\Models\User;
use App\Support\Edata\Access;
use App\Support\Edata\Audit;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends BaseController
{
    private const ACTIONS = ['view', 'create', 'edit', 'delete'];

    public function index()
    {
        $me = $this->me();
        Access::need($me, 'manage role');
        $company = $this->companyId();

        $roles = $this->rolesQuery()->withCount('permissions')->get();
        $counts = User::where('created_by', $company)
            ->whereIn('type', $roles->pluck('name'))
            ->selectRaw('type, COUNT(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        return view('edata.roles.index', [
            'roles' => $roles,
            'counts' => $counts,
            'canCreate' => $me->can('create role'),
            'canEdit' => $me->can('edit role'),
            'canDelete' => $me->can('delete role'),
        ]);
    }

    public function create()
    {
        Access::need($this->me(), 'create role');

        return view('edata.roles.form', $this->formData(new Role()));
    }

    public function store(Request $request)
    {
        Access::need($this->me(), 'create role');
        $company = $this->companyId();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'name')->where('created_by', $company)],
            'actions' => 'nullable|array',
            'scopes' => 'nullable|array',
        ], [], ['name' => 'اسم الدور']);

        try {
            $role = DB::transaction(function () use ($data, $company) {
                $role = new Role();
                $role->name = $data['name'];
                $role->guard_name = 'web';
                $role->created_by = $company;
                $role->save();

                $this->applyMatrix($role, $data['actions'] ?? [], $data['scopes'] ?? []);

                return $role;
            });
        } catch (QueryException $e) {
            return $this->failed('هذا الاسم مستخدم لدور آخر، اختر اسماً مختلفاً.');
        }

        return $this->done('تم إنشاء دور ' . $role->name . '.', route('roles.index'));
    }

    public function edit(int $role)
    {
        Access::need($this->me(), 'edit role');

        return view('edata.roles.form', $this->formData($this->reachable($role)));
    }

    public function update(Request $request, int $role)
    {
        Access::need($this->me(), 'edit role');
        $record = $this->reachable($role);
        $company = $this->companyId();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'name')->where('created_by', $company)->ignore($record->id)],
            'actions' => 'nullable|array',
            'scopes' => 'nullable|array',
        ], [], ['name' => 'اسم الدور']);

        $oldName = $record->name;

        try {
            DB::transaction(function () use ($record, $data, $oldName, $company) {
                $record->name = $data['name'];
                $record->save();

                // users.type stores the role name, so a rename has to follow through.
                if ($oldName !== $data['name']) {
                    User::where('created_by', $company)->where('type', $oldName)->update(['type' => $data['name']]);
                }

                $this->applyMatrix($record, $data['actions'] ?? [], $data['scopes'] ?? []);
            });
        } catch (QueryException $e) {
            return $this->failed('هذا الاسم مستخدم لدور آخر، اختر اسماً مختلفاً.');
        }

        Access::flushCache();

        return $this->done('تم حفظ دور ' . $record->name . '. التغيير يطبق فوراً على كل من يحمله.', route('roles.index'));
    }

    public function destroy(int $role)
    {
        Access::need($this->me(), 'delete role');
        $record = $this->reachable($role);

        if (User::where('created_by', $this->companyId())->where('type', $record->name)->exists()) {
            return $this->failed('لا يمكن حذف دور مسند لموظفين. انقلهم لدور آخر أولاً.');
        }

        DB::table('edata_role_scopes')->where('role_id', $record->id)->delete();
        $record->delete();

        return $this->done('تم حذف الدور.', route('roles.index'));
    }

    private function rolesQuery()
    {
        return Role::where('created_by', $this->companyId())
            ->whereNotIn('name', ['company', 'client', 'super admin'])
            ->orderBy('name');
    }

    private function reachable(int $id): Role
    {
        return $this->rolesQuery()->whereKey($id)->firstOrFail();
    }

    private function formData(Role $role): array
    {
        $granted = $role->exists ? $role->permissions->pluck('name')->flip() : collect();
        $checked = [];

        foreach (config('edata.modules') as $key => $module) {
            foreach (self::ACTIONS as $action) {
                $names = $module[$action] ?? [];
                $checked[$key][$action] = !empty($names) && collect($names)->contains(fn ($n) => $granted->has($n));
            }
        }

        $scopes = $role->exists
            ? DB::table('edata_role_scopes')->where('role_id', $role->id)->pluck('scope', 'module')
            : collect();

        return [
            'role' => $role,
            'modules' => config('edata.modules'),
            'checked' => $checked,
            'scopes' => $scopes,
            'actions' => ['view' => 'عرض', 'create' => 'إضافة', 'edit' => 'تعديل', 'delete' => 'حذف'],
            'scopeOptions' => ['all' => 'كل الشركة', 'team' => 'مشاريعه وفريقه', 'own' => 'ما يخصه فقط'],
        ];
    }

    private function applyMatrix(Role $role, array $actions, array $scopes): void
    {
        $me = $this->me();
        $matrix = config('edata.modules');
        $matrixNames = [];
        foreach ($matrix as $module) {
            foreach (self::ACTIONS as $action) {
                $matrixNames = array_merge($matrixNames, $module[$action] ?? []);
            }
        }

        // Permissions outside the matrix (payroll, accounting and so on) are left as they were.
        $kept = $role->exists ? $role->permissions->pluck('name')->diff($matrixNames)->values()->all() : [];
        $selected = [];

        foreach ($matrix as $key => $module) {
            foreach (self::ACTIONS as $action) {
                if (!empty($actions[$key][$action])) {
                    foreach ($module[$action] ?? [] as $permission) {
                        // Nobody can hand out a permission they do not hold.
                        if (Access::isOwner($me) || $me->can($permission)) {
                            $selected[] = $permission;
                        }
                    }
                }
            }
        }

        $before = $role->permissions->pluck('name')->all();
        $after = array_values(array_unique(array_merge($kept, $selected)));
        $role->syncPermissions($after);

        foreach ($matrix as $key => $module) {
            if (empty($module['scoped'])) {
                continue;
            }
            $scope = in_array($scopes[$key] ?? null, ['all', 'team', 'own'], true) ? $scopes[$key] : 'own';
            DB::table('edata_role_scopes')->updateOrInsert(
                ['role_id' => $role->id, 'module' => $key],
                ['scope' => $scope, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        $removed = array_values(array_diff($before, $after));
        $added = array_values(array_diff($after, $before));
        if ($removed || $added) {
            Audit::record('updated', 'role', $role->id, $role->name, ['صلاحيات أزيلت' => $removed], ['صلاحيات أضيفت' => $added]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
