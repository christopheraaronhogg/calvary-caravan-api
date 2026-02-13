<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Retreat extends Model
{
    protected $fillable = [
        'name',
        'code',
        'destination_name',
        'destination_lat',
        'destination_lng',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    protected $casts = [
        'destination_lat' => 'decimal:8',
        'destination_lng' => 'decimal:8',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function participants(): HasMany
    {
        return $this->hasMany(RetreatParticipant::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(RetreatMessage::class);
    }

    public function leaderPhoneAllowlist(): HasMany
    {
        return $this->hasMany(RetreatLeaderPhoneAllowlist::class);
    }

    public function waypoints(): HasMany
    {
        return $this->hasMany(RetreatWaypoint::class)->orderBy('waypoint_order');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where('ends_at', '>=', now());
    }

    /**
     * Keep join logic simple for V1: if the retreat is active, it is joinable.
     * (Avoids blocking early testing before the retreat start date.)
     */
    public function scopeJoinable($query)
    {
        return $query->active();
    }
}
