<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Suggestion box and monthly review. An anonymous suggestion stores no user at
 * all, so nobody (owner included) can trace it back.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('edata_suggestions')) {
            Schema::create('edata_suggestions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('user_id')->nullable()->index(); // null = anonymous
                $table->string('category', 30)->default('other');
                $table->text('body');
                $table->string('status', 20)->default('new'); // new, seen, done
                $table->text('reply')->nullable();
                $table->unsignedBigInteger('replied_by')->nullable();
                $table->dateTime('replied_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('edata_reviews')) {
            Schema::create('edata_reviews', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->char('month', 7); // YYYY-MM
                $table->unsignedTinyInteger('rating');
                $table->text('strengths')->nullable();
                $table->text('improve')->nullable();
                $table->unsignedBigInteger('reviewer_id');
                $table->timestamps();
                $table->unique(['employee_id', 'month'], 'edata_reviews_employee_month');
            });
        }

        $self = ['send suggestion', 'view my review'];
        $managers = ['manage suggestion', 'manage review'];
        foreach (array_merge($self, $managers) as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Role::with('permissions')->get() as $role) {
            if (in_array($role->name, ['super admin', 'client'], true)) {
                continue;
            }
            $give = $role->name === 'company' ? array_merge($self, $managers) : $self;
            $role->givePermissionTo(array_values(array_diff($give, $role->permissions->pluck('name')->all())));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Suggestions and reviews are real records; they are never dropped automatically.
    }
};
