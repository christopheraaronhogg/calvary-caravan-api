<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetreatWaypoint extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'retreat_id',
        'name',
        'description',
        'latitude',
        'longitude',
        'waypoint_order',
        'eta',
        'created_at',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'eta' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function retreat(): BelongsTo
    {
        return $this->belongsTo(Retreat::class);
    }
}

