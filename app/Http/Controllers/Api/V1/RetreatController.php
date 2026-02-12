<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\JoinRetreatRequest;
use App\Http\Requests\Api\UpdateProfilePhotoRequest;
use App\Models\Retreat;
use App\Models\RetreatParticipant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RetreatController extends Controller
{
    /**
     * Temporary compatibility aliases so both mnemonic and numeric launch codes
     * can resolve to the same active retreat during rollout.
     */
    private const RETREAT_CODE_ALIASES = [
        '262026' => 'CBCR26',
    ];

    public function join(JoinRetreatRequest $request): JsonResponse
    {
        $inputCode = strtoupper($request->validated('code'));
        $code = self::RETREAT_CODE_ALIASES[$inputCode] ?? $inputCode;

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
            'gender' => Schema::hasColumn('retreat_participants', 'gender')
                ? $request->validated('gender')
                : null,
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
                    'avatar_url' => $participant->avatar_url,
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

    public function updateProfilePhoto(UpdateProfilePhotoRequest $request): JsonResponse
    {
        $participant = $request->attributes->get('participant');
        $payload = $request->validated('avatar_base64');

        if (!preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,([A-Za-z0-9+\/=\r\n]+)$/', $payload, $matches)) {
            return response()->json(['error' => 'Invalid image format'], 422);
        }

        $raw = base64_decode(str_replace(["\r", "\n", ' '], '', $matches[2]), true);
        if ($raw === false) {
            return response()->json(['error' => 'Invalid image encoding'], 422);
        }

        if (strlen($raw) > 5 * 1024 * 1024) {
            return response()->json(['error' => 'Image too large (max 5MB)'], 422);
        }

        $ext = strtolower($matches[1]);
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }

        $path = sprintf(
            'retreat-avatars/%d/participant-%d-%d.%s',
            $participant->retreat_id,
            $participant->id,
            time(),
            $ext
        );

        Storage::disk('public')->put($path, $raw);

        if ($participant->avatar_path) {
            Storage::disk('public')->delete($participant->avatar_path);
        }

        $participant->update(['avatar_path' => $path]);

        return response()->json([
            'data' => [
                'avatar_url' => $participant->fresh()->avatar_url,
            ],
        ]);
    }

    public function removeProfilePhoto(Request $request): JsonResponse
    {
        $participant = $request->attributes->get('participant');

        if ($participant->avatar_path) {
            Storage::disk('public')->delete($participant->avatar_path);
            $participant->update(['avatar_path' => null]);
        }

        return response()->json([
            'data' => [
                'avatar_url' => null,
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
