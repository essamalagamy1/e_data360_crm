<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A manager's decision about one employee's day that has no clock-in: excused,
 * day off, absent, or leave (the leave itself lives in the leaves table and is
 * linked here so it can be undone).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('edata_day_statuses')) {
            Schema::create('edata_day_statuses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('employee_id');
                $table->date('date');
                $table->string('status', 20); // excused, day_off, absent, leave
                $table->unsignedBigInteger('leave_id')->nullable();
                $table->string('reason', 400)->nullable();
                $table->unsignedBigInteger('set_by');
                $table->timestamps();
                $table->unique(['employee_id', 'date'], 'edata_day_statuses_unique');
            });
        }
    }

    public function down(): void
    {
        // Real decisions about real days; never dropped automatically.
    }
};
