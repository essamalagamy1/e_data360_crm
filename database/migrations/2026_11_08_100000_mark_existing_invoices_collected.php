<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The company only issues an invoice once the money has arrived, so every issued
 * invoice up to now was in fact collected. Their payments were never entered.
 *
 * Rather than inventing payment rows (which would also move bank balances and
 * ledger lines), an invoice now carries the date it was collected. Invoices
 * marked this way count as fully collected on that date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'collected_on')) {
                $table->date('collected_on')->nullable()->after('public_token_rotated_at');
                $table->index('collected_on', 'invoices_collected_on_idx');
            }
        });

        // Only issued invoices that are still open. Drafts, cancelled and
        // already-paid invoices are left exactly as they are.
        $ids = DB::table('invoices')
            ->whereNull('cancelled_at')
            ->whereNull('collected_on')
            ->whereIn('status', [1, 2, 3])
            ->pluck('id');

        foreach ($ids->chunk(500) as $chunk) {
            DB::table('invoices')
                ->whereIn('id', $chunk->all())
                ->update([
                    'collected_on' => DB::raw('issue_date'),
                    'status' => 4,
                ]);
        }

        if ($ids->isNotEmpty() && Schema::hasTable('edata_audit_logs')) {
            DB::table('edata_audit_logs')->insert([
                'company_id' => 0,
                'user_name' => 'النظام',
                'action' => 'status_changed',
                'entity_type' => 'invoice',
                'entity_label' => 'تحديد ' . $ids->count() . ' فاتورة سابقة كمتحصلة',
                'note' => 'كل الفواتير المرسلة قبل ' . now()->toDateString() . ' اعتُبرت متحصلة بتاريخ إصدارها.',
                // Kept so the change can be traced, and undone invoice by invoice if ever needed.
                'new_values' => json_encode(['invoice_ids' => $ids->values()->all()]),
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally empty: the database backup taken before each deployment is the way back.
    }
};
