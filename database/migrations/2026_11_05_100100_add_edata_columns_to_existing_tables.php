<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds nullable columns next to the existing ones. Old columns keep working,
 * so every screen and report that has not been rebuilt continues to read them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_employees', function (Blueprint $table) {
            if (!Schema::hasColumn('attendance_employees', 'clock_in_at')) {
                // Exact moments in UTC. The elapsed timer is calculated from these on the server.
                $table->dateTime('clock_in_at')->nullable()->after('clock_out');
                $table->dateTime('clock_out_at')->nullable()->after('clock_in_at');
                $table->unsignedInteger('worked_seconds')->nullable()->after('clock_out_at');
                // Equals employee_id while the session is open. The unique index makes a
                // second open session impossible even if two requests arrive together.
                $table->unsignedBigInteger('open_key')->nullable()->after('worked_seconds');
                $table->string('source', 20)->default('legacy')->after('open_key');
                $table->unique('open_key', 'attendance_open_key_unique');
                $table->index(['employee_id', 'date'], 'attendance_employee_date_idx');
                $table->index('date', 'attendance_date_idx');
            }
        });

        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'crm_status')) {
                $table->string('contact_person', 191)->nullable()->after('name');
                $table->string('industry', 120)->nullable()->after('contact');
                $table->string('source', 120)->nullable()->after('industry');
                $table->string('crm_status', 30)->default('active')->after('source');
                $table->text('notes')->nullable()->after('crm_status');
                $table->timestamp('archived_at')->nullable()->after('notes');
                $table->index(['created_by', 'crm_status'], 'customers_company_status_idx');
            }
        });

        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'customer_id')) {
                $table->unsignedBigInteger('customer_id')->nullable()->after('client_id');
                $table->unsignedBigInteger('manager_id')->nullable()->after('customer_id');
                $table->unsignedTinyInteger('manual_progress')->nullable()->after('manager_id');
                $table->index(['created_by', 'status'], 'projects_company_status_idx');
                $table->index('customer_id', 'projects_customer_idx');
                $table->index('manager_id', 'projects_manager_idx');
            }
        });

        Schema::table('project_tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('project_tasks', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('marked_at');
                $table->index(['project_id', 'stage_id'], 'tasks_project_stage_idx');
                $table->index(['created_by', 'is_complete'], 'tasks_company_complete_idx');
                $table->index('end_date', 'tasks_end_date_idx');
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'project_id')) {
                $table->unsignedBigInteger('project_id')->nullable()->after('customer_id');
                $table->text('notes')->nullable()->after('ref_number');
                $table->timestamp('cancelled_at')->nullable()->after('notes');
                $table->index(['created_by', 'status'], 'invoices_company_status_idx');
                $table->index('customer_id', 'invoices_customer_idx');
                $table->index('project_id', 'invoices_project_idx');
                $table->index('due_date', 'invoices_due_idx');
            }
        });

        Schema::table('invoice_payments', function (Blueprint $table) {
            if (!Schema::hasIndex('invoice_payments', 'invoice_payments_invoice_idx')) {
                $table->index('invoice_id', 'invoice_payments_invoice_idx');
                $table->index('date', 'invoice_payments_date_idx');
            }
        });

        Schema::table('invoice_products', function (Blueprint $table) {
            if (!Schema::hasIndex('invoice_products', 'invoice_products_invoice_idx')) {
                $table->index('invoice_id', 'invoice_products_invoice_idx');
            }
        });

        Schema::table('product_services', function (Blueprint $table) {
            if (!Schema::hasColumn('product_services', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('description');
            }
        });

        Schema::table('employees', function (Blueprint $table) {
            if (!Schema::hasColumn('employees', 'employment_status')) {
                $table->string('employment_status', 30)->default('active')->after('is_active');
            }
        });

        Schema::table('notifications', function (Blueprint $table) {
            if (!Schema::hasColumn('notifications', 'dedupe_key')) {
                $table->string('dedupe_key', 120)->nullable()->after('is_read');
                $table->index(['user_id', 'is_read'], 'notifications_user_read_idx');
                $table->index('dedupe_key', 'notifications_dedupe_idx');
            }
        });
    }

    public function down(): void
    {
        // Intentionally empty: rolling back must never drop stored values.
    }
};
