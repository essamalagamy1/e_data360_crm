<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EdataNote extends Model
{
    protected $table = 'edata_notes';

    protected $guarded = ['id'];

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
