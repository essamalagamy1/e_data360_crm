<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only: two nullable columns on invoices and one new table.
 * No stored value changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'public_token')) {
                // The short random part of the link a client opens without logging in.
                $table->string('public_token', 24)->nullable()->after('cancelled_at');
                // Set once a link has been replaced, which also retires the old encrypted links.
                $table->timestamp('public_token_rotated_at')->nullable()->after('public_token');
                $table->unique('public_token', 'invoices_public_token_unique');
            }
        });

        if (!Schema::hasTable('edata_kudos')) {
            Schema::create('edata_kudos', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('from_user_id');
                $table->unsignedBigInteger('to_user_id');
                $table->string('message', 240);
                $table->timestamp('created_at')->useCurrent();

                $table->index(['company_id', 'created_at'], 'edata_kudos_company_idx');
                $table->index('to_user_id', 'edata_kudos_to_idx');
                $table->index(['from_user_id', 'created_at'], 'edata_kudos_from_idx');
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: rolling back must never drop stored values.
    }
};
