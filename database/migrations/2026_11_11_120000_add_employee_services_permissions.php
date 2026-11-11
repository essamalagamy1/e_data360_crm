<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Gives each of the employee services its own permission so it can be turned on
 * or off per role from the roles screen. Existing roles keep what they could do
 * before: the self services go to every role, and the manager pages go to the
 * roles that already reached them through the attendance permissions.
 */
return new class extends Migration
{
    private array $selfServices = ['create self request', 'use day plan', 'use task timer', 'view month summary'];

    public function up(): void
    {
        foreach (array_merge($this->selfServices, ['view daily report', 'manage leave']) as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Role::with('permissions')->get() as $role) {
            if (in_array($role->name, ['super admin', 'client'], true)) {
                continue;
            }
            $has = $role->permissions->pluck('name');
            $give = $this->selfServices;
            if ($has->contains('manage attendance')) {
                $give[] = 'view daily report';
            }
            if ($has->contains('edit attendance')) {
                $give[] = 'manage leave';
            }
            $role->givePermissionTo(array_values(array_diff($give, $has->all())));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Permissions stay: removing them would silently change what roles can do.
    }
};
