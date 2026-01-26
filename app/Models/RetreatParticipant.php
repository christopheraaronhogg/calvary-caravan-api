<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RetreatParticipant extends Model
{
    protected $fillable = [
        'retreat_id',
        'name',
        'gender',
        'device_token',
        'expo_push_token',
        'vehicle_color',
        'vehicle_description',
        'is_leader',
        'joined_at',
        'last_seen_at',
    ];

    protected $casts = [
        'is_leader' => 'boolean',
        'joined_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    protected $hidden = ['device_token', 'expo_push_token'];

    public function retreat(): BelongsTo
    {
        return $this->belongsTo(Retreat::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(ParticipantLocation::class, 'participant_id');
    }

    public function latestLocation(): HasOne
    {
        return $this->hasOne(ParticipantLocation::class, 'participant_id')
            ->latestOfMany('recorded_at');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(RetreatMessage::class, 'participant_id');
    }
}
