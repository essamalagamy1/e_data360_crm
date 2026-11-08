<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Adds the permissions the new screens need, gives them to company owners, and
 * creates the starting roles. Existing users keep the exact roles they have.
 */
return new class extends Migration
{
    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $new = ['view audit log', 'view finance summary', 'manage attendance settings'];
        foreach ($new as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // Every permission named in the matrix must exist before it can be assigned.
        foreach (config('edata.modules') as $module) {
            foreach (['view', 'create', 'edit', 'delete'] as $action) {
                foreach ($module[$action] ?? [] as $permission) {
                    Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
                }
            }
        }

        $companyRole = Role::where('name', 'company')->first();
        if ($companyRole) {
            $companyRole->givePermissionTo(array_merge($new, $this->matrixPermissions()));
        }

        $companies = DB::table('users')->where('type', 'company')->pluck('id');

        foreach ($companies as $companyId) {
            // Scopes for roles the company already has.
            $existing = Role::where('created_by', $companyId)->get();
            foreach ($existing as $role) {
                $employeeLike = preg_match('/موظف|employee/iu', $role->name) === 1;
                $scopes = config('edata.existing_role_scopes.' . ($employeeLike ? 'employee_like' : 'other'));
                $this->storeScopes($role->id, $scopes);
            }

            foreach (config('edata.default_roles') as $name => $definition) {
                if (Role::where('name', $name)->where('created_by', $companyId)->exists()) {
                    continue;
                }

                $role = new Role();
                $role->name = $name;
                $role->guard_name = 'web';
                $role->created_by = $companyId;

                try {
                    $role->save();
                } catch (\Illuminate\Database\QueryException $e) {
                    // The name is unique across the platform; another company owns it.
                    continue;
                }

                $permissions = ['show hrm dashboard'];
                foreach ($definition['actions'] as $module => $actions) {
                    foreach ($actions as $action) {
                        $permissions = array_merge($permissions, config("edata.modules.$module.$action", []));
                    }
                }
                $role->syncPermissions(array_values(array_unique($permissions)));
                $this->storeScopes($role->id, $definition['scopes']);
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    private function matrixPermissions(): array
    {
        $all = [];
        foreach (config('edata.modules') as $module) {
            foreach (['view', 'create', 'edit', 'delete'] as $action) {
                $all = array_merge($all, $module[$action] ?? []);
            }
        }

        return array_values(array_unique($all));
    }

    private function storeScopes(int $roleId, array $scopes): void
    {
        foreach ($scopes as $module => $scope) {
            DB::table('edata_role_scopes')->updateOrInsert(
                ['role_id' => $roleId, 'module' => $module],
                ['scope' => $scope, 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function down(): void
    {
    }
};
