<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * New tables for the employee services: attendance requests, the daily plan and
 * report, company announcements and task timers. Leave requests use the existing
 * leaves table, so the old leave screens and payroll keep reading them.
 * No existing table or row is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('edata_requests')) {
            Schema::create('edata_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('employee_id')->index();
                $table->unsignedBigInteger('user_id');
                // correction = fix a recorded day, permission = late arrival or early leave
                $table->string('kind', 20);
                $table->date('date');
                $table->time('time_from')->nullable();
                $table->time('time_to')->nullable();
                $table->string('reason', 500);
                $table->string('status', 12)->default('pending')->index();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->string('review_note', 500)->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('edata_daily_logs')) {
            Schema::create('edata_daily_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('user_id');
                $table->date('date');
                $table->json('plan')->nullable();
                $table->text('report')->nullable();
                $table->timestamps();
                $table->unique(['employee_id', 'date'], 'edata_daily_logs_day_unique');
            });
        }

        if (!Schema::hasTable('edata_announcements')) {
            Schema::create('edata_announcements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('user_id');
                $table->string('title', 191);
                $table->text('body')->nullable();
                $table->date('show_until')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('edata_announcement_reads')) {
            Schema::create('edata_announcement_reads', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('announcement_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamp('read_at')->useCurrent();
                $table->unique(['announcement_id', 'user_id'], 'edata_announcement_reads_unique');
            });
        }

        if (!Schema::hasTable('edata_task_timers')) {
            Schema::create('edata_task_timers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('task_id')->index();
                $table->unsignedBigInteger('project_id')->nullable()->index();
                $table->unsignedBigInteger('user_id');
                $table->dateTime('started_at');
                $table->dateTime('stopped_at')->nullable();
                $table->unsignedInteger('seconds')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'stopped_at'], 'edata_task_timers_running_idx');
            });
        }

        // The permissions behind the new screens already exist in the original system;
        // they are created only if a company's database lacks them, and given to owners.
        $names = ['manage announcement', 'create announcement', 'edit announcement', 'delete announcement', 'manage leave'];
        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $companyRole = Role::where('name', 'company')->first();
        if ($companyRole) {
            $companyRole->givePermissionTo($names);
        }

        // A leave request needs a type. A company without any gets one plain type,
        // with no day limit, so requests work until real types are set.
        foreach (DB::table('users')->where('type', 'company')->pluck('id') as $companyId) {
            if (!DB::table('leave_types')->where('created_by', $companyId)->exists()) {
                DB::table('leave_types')->insert(['title' => 'إجازة', 'days' => 0, 'created_by' => $companyId, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Intentionally empty: rolling back must never drop stored values.
    }
};
