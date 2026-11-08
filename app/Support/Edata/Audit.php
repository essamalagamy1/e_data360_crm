<?php

namespace App\Support\Edata;

use App\Models\EdataAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Writes the central audit trail. Secrets and payroll details never reach it:
 * a password change is recorded as the fact that it changed, with no value.
 */
class Audit
{
    /** Fields that are bookkeeping noise or must stay out of the log. */
    private const HIDDEN = [
        'password', 'remember_token', 'updated_at', 'created_at', 'last_login_at', 'storage_limit',
        'order', 'priority_color', 'copylinksetting', 'open_key', 'account_number', 'bank_name',
        'bank_identifier_code', 'account_holder_name', 'branch_location', 'tax_payer_id', 'salary',
        'salary_type', 'balance', 'credit_balance', 'clock_in_at', 'clock_out_at', 'late', 'early_leaving',
        'overtime', 'total_rest', 'source', 'avatar', 'project_image', 'referral_code',
        'used_referral_code', 'commission_amount', 'paid_amount', 'email_verified_at', 'id', 'created_by',
        'mode', 'dark_mode', 'messenger_color', 'lang', 'is_favourite', 'public_token', 'public_token_rotated_at',
    ];

    /** Note attached to the next write, for example the reason for an attendance correction. */
    public static ?string $nextNote = null;

    public static function record(string $action, string $type, ?int $id, ?string $label, ?array $old = null, ?array $new = null, ?string $note = null): void
    {
        $user = Auth::user();

        EdataAuditLog::create([
            'company_id' => $user ? $user->creatorId() : 0,
            'user_id' => $user?->id,
            'user_name' => $user?->name ?? 'النظام',
            'action' => $action,
            'entity_type' => $type,
            'entity_id' => $id,
            'entity_label' => $label ? mb_substr($label, 0, 255) : null,
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'note' => $note ?? self::$nextNote,
            'ip' => Request::ip(),
        ]);

        self::$nextNote = null;
    }

    public static function forModel(string $action, Model $model, string $type, ?string $label, ?array $old = null, ?array $new = null): void
    {
        self::record($action, $type, (int) $model->getKey(), $label, $old, $new);
    }

    public static function clean(array $values): array
    {
        return array_diff_key($values, array_flip(self::HIDDEN));
    }
}
