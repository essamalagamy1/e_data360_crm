<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append only. The model refuses updates and deletes, and no route exposes them.
 */
class EdataAuditLog extends Model
{
    protected $table = 'edata_audit_logs';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $log) {
            // Stored in UTC whatever timezone the request is running in.
            $log->created_at = now('UTC')->format('Y-m-d H:i:s');
        });

        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
