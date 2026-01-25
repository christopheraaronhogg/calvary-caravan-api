<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\JoinRetreatRequest;
use App\Models\Retreat;
use App\Models\RetreatParticipant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RetreatController extends Controller
{
    public function join(JoinRetreatRequest $request): JsonResponse
    {
        $code = strtoupper($request->validated('code'));

        $retreat = Retreat::where('code', $code)
            ->joinable()
            ->first();

        if (!$retreat) {
            return response()->json([
                'error' => 'Invalid retreat code or retreat is not active',
            ], 422);
        }

        $participant = RetreatParticipant::create([
            'retreat_id' => $retreat->id,
            'name' => $request->validated('name'),
            'device_token' => Str::uuid()->toString(),
            'expo_push_token' => $request->validated('expo_push_token'),
            'vehicle_color' => $request->validated('vehicle_color'),
            'vehicle_description' => $request->validated('vehicle_description'),
            'joined_at' => now(),
            'last_seen_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'participant_id' => $participant->id,
                'device_token' => $participant->device_token,
                'retreat' => [
                    'id' => $retreat->id,
                    'name' => $retreat->name,
                    'destination' => $retreat->destination_name ? [
                        'name' => $retreat->destination_name,
                        'lat' => (float) $retreat->destination_lat,
                        'lng' => (float) $retreat->destination_lng,
                    ] : null,
                    'starts_at' => $retreat->starts_at->toIso8601String(),
                    'ends_at' => $retreat->ends_at->toIso8601String(),
                ],
            ],
        ]);
    }

    public function leave(Request $request): JsonResponse
    {
        $participant = $request->attributes->get('participant');
        $participant->update(['device_token' => null]);

        return response()->json(['data' => ['left' => true]]);
    }

    public function status(Request $request): JsonResponse
    {
        $participant = $request->attributes->get('participant');
        $retreat = $request->attributes->get('retreat');

        $activeParticipantCount = $retreat->participants()
            ->whereNotNull('device_token')
            ->count();

        return response()->json([
            'data' => [
                'participant' => [
                    'id' => $participant->id,
                    'name' => $participant->name,
                    'is_leader' => (bool) $participant->is_leader,
                ],
                'retreat' => [
                    'id' => $retreat->id,
                    'name' => $retreat->name,
                    'destination' => $retreat->destination_name ? [
                        'name' => $retreat->destination_name,
                        'lat' => (float) $retreat->destination_lat,
                        'lng' => (float) $retreat->destination_lng,
                    ] : null,
                    'starts_at' => $retreat->starts_at->toIso8601String(),
                    'ends_at' => $retreat->ends_at->toIso8601String(),
                    'participant_count' => $activeParticipantCount,
                ],
            ],
        ]);
    }

    public function waypoints(Request $request): JsonResponse
    {
        $retreat = $request->attributes->get('retreat');

        $waypoints = $retreat->waypoints->map(function ($waypoint) {
            return [
                'id' => $waypoint->id,
                'name' => $waypoint->name,
                'description' => $waypoint->description,
                'lat' => (float) $waypoint->latitude,
                'lng' => (float) $waypoint->longitude,
                'order' => $waypoint->waypoint_order,
                'eta' => $waypoint->eta?->toIso8601String(),
            ];
        });

        return response()->json(['data' => $waypoints]);
    }
}

