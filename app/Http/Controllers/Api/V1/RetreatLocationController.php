<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateLocationRequest;
use App\Models\ParticipantLocation;
use App\Services\SpacetimeLocationMirror;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RetreatLocationController extends Controller
{
    public function update(UpdateLocationRequest $request, SpacetimeLocationMirror $spacetimeMirror): JsonResponse
    {
        $participant = $request->attributes->get('participant');
        $retreat = $request->attributes->get('retreat');

        $validated = $request->validated();

        ParticipantLocation::create([
            'participant_id' => $participant->id,
            'latitude' => $validated['latitude'],
            'longitude' => $validated['longitude'],
            'accuracy' => $validated['accuracy'] ?? null,
            'speed' => $validated['speed'] ?? null,
            'heading' => $validated['heading'] ?? null,
            'altitude' => $validated['altitude'] ?? null,
            'recorded_at' => $validated['recorded_at'],
            'created_at' => now(),
        ]);

        $spacetimeMirror->mirrorLatestLocation(
            (int) $participant->id,
            (int) $retreat->id,
            $validated
        );

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
                    'gender' => $participant->gender ?? null,
                    'avatar_url' => $participant->avatar_url,
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
