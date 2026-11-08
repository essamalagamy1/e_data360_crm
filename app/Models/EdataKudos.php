<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A thank-you note from one colleague to another. */
class EdataKudos extends Model
{
    protected $table = 'edata_kudos';

    const UPDATED_AT = null;

    protected $fillable = ['company_id', 'from_user_id', 'to_user_id', 'message'];

    protected $casts = ['created_at' => 'datetime'];

    public function sender()
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }
}
