<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each employee's own yearly leave allowance per leave type. Without a row the
 * employee follows the leave type's default days.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('edata_leave_allowances')) {
            Schema::create('edata_leave_allowances', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('leave_type_id');
                $table->decimal('days', 5, 1);
                $table->timestamps();
                $table->unique(['employee_id', 'leave_type_id'], 'edata_leave_allowances_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('edata_leave_allowances');
    }
};
