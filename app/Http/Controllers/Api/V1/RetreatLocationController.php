<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateLocationRequest;
use App\Models\ParticipantLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RetreatLocationController extends Controller
{
    public function update(UpdateLocationRequest $request): JsonResponse
    {
        $participant = $request->attributes->get('participant');

        ParticipantLocation::create([
            'participant_id' => $participant->id,
            'latitude' => $request->validated('latitude'),
            'longitude' => $request->validated('longitude'),
            'accuracy' => $request->validated('accuracy'),
            'speed' => $request->validated('speed'),
            'heading' => $request->validated('heading'),
            'altitude' => $request->validated('altitude'),
            'recorded_at' => $request->validated('recorded_at'),
            'created_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'recorded' => true,
                'next_update_in' => 30,
            ],
        ]);
    }

    public function all(Request $request): JsonResponse
    {
        $retreat = $request->attributes->get('retreat');
        $currentParticipant = $request->attributes->get('participant');

        $participants = $retreat->participants()
            ->whereNotNull('device_token')
            ->with('latestLocation')
            ->get()
            ->map(function ($participant) use ($currentParticipant) {
                $location = $participant->latestLocation;

                return [
                    'participant_id' => $participant->id,
                    'name' => $participant->name,
                    'vehicle_color' => $participant->vehicle_color,
                    'vehicle_description' => $participant->vehicle_description,
                    'is_leader' => (bool) $participant->is_leader,
                    'is_current_user' => $participant->id === $currentParticipant->id,
                    'location' => $location ? [
                        'lat' => (float) $location->latitude,
                        'lng' => (float) $location->longitude,
                        'accuracy' => $location->accuracy ? (float) $location->accuracy : null,
                        'speed' => $location->speed ? (float) $location->speed : null,
                        'heading' => $location->heading ? (float) $location->heading : null,
                        'recorded_at' => $location->recorded_at->toIso8601String(),
                    ] : null,
                    'last_seen_seconds_ago' => $participant->last_seen_at
                        ? (int) abs(now()->diffInSeconds($participant->last_seen_at, false))
                        : null,
                ];
            });

        $onlineCount = $participants->filter(function ($participant) {
            return $participant['last_seen_seconds_ago'] !== null
                && $participant['last_seen_seconds_ago'] < 300;
        })->count();

        return response()->json([
            'data' => $participants,
            'meta' => [
                'total_participants' => $participants->count(),
                'online_count' => $onlineCount,
                'server_time' => now()->toIso8601String(),
            ],
        ]);
    }
}

