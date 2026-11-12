<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Part-time schedules. Empty means full time: the employee follows the company
 * work days. A list such as "0,2,3" means part time on Sunday, Tuesday and
 * Wednesday only. Existing employees stay full time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('employees', 'edata_work_days')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->string('edata_work_days', 20)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('employees', 'edata_work_days')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropColumn('edata_work_days');
            });
        }
    }
};
