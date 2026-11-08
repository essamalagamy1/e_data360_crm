<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New tables only. No existing table is altered here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('edata_audit_logs')) {
            Schema::create('edata_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->default(0)->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('user_name', 191)->nullable();
                $table->string('action', 40)->index();
                $table->string('entity_type', 40)->index();
                $table->unsignedBigInteger('entity_id')->nullable();
                $table->string('entity_label', 255)->nullable();
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->string('note', 500)->nullable();
                $table->string('ip', 45)->nullable();
                $table->timestamp('created_at')->useCurrent()->index();

                $table->index(['entity_type', 'entity_id']);
            });
        }

        if (!Schema::hasTable('edata_notes')) {
            Schema::create('edata_notes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('notable_type', 40);
                $table->unsignedBigInteger('notable_id');
                $table->text('body');
                $table->unsignedBigInteger('user_id');
                $table->timestamps();

                $table->index(['notable_type', 'notable_id']);
            });
        }

        if (!Schema::hasTable('edata_role_scopes')) {
            Schema::create('edata_role_scopes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('role_id');
                $table->string('module', 40);
                $table->string('scope', 10)->default('own');
                $table->timestamps();

                $table->unique(['role_id', 'module']);
            });
        }
    }

    public function down(): void
    {
        // Audit history and notes are never removed by a rollback.
    }
};
