<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetreatMessage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'retreat_id',
        'participant_id',
        'message_type',
        'content',
        'latitude',
        'longitude',
        'created_at',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'created_at' => 'datetime',
    ];

    public function retreat(): BelongsTo
    {
        return $this->belongsTo(Retreat::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(RetreatParticipant::class, 'participant_id');
    }
}

