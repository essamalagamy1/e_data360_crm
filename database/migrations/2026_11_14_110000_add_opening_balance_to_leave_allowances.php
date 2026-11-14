<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leave taken before the system was used. The manager types what is left today;
 * the difference is kept here for that year only. A negative value means extra
 * days (for example carried over from last year).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edata_leave_allowances', function (Blueprint $table) {
            if (!Schema::hasColumn('edata_leave_allowances', 'opening_used')) {
                $table->decimal('opening_used', 6, 1)->default(0);
            }
            if (!Schema::hasColumn('edata_leave_allowances', 'opening_year')) {
                $table->unsignedSmallInteger('opening_year')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('edata_leave_allowances', function (Blueprint $table) {
            $table->dropColumn(['opening_used', 'opening_year']);
        });
    }
};
