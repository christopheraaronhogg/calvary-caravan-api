<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetreatLeaderPhoneAllowlist extends Model
{
    protected $fillable = [
        'retreat_id',
        'phone_e164',
    ];

    public function retreat(): BelongsTo
    {
        return $this->belongsTo(Retreat::class);
    }
}
