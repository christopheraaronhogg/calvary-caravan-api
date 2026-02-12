<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\DeleteAccountRequest;
use App\Http\Requests\Api\JoinRetreatRequest;
use App\Http\Requests\Api\StoreWaypointRequest;
use App\Http\Requests\Api\UpdateProfilePhotoRequest;
use App\Models\Retreat;
use App\Models\RetreatParticipant;
use App\Models\RetreatWaypoint;
use App\Services\RetreatIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function join(JoinRetreatRequest $request, RetreatIdentityService $identityService): JsonResponse
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

        $phoneE164 = $request->validated('phone_number');

        $participant = DB::transaction(function () use ($request, $retreat, $phoneE164, $identityService) {
            $existingParticipant = RetreatParticipant::query()
                ->where('retreat_id', $retreat->id)
                ->where('phone_e164', $phoneE164)
                ->lockForUpdate()
                ->first();

            $resolvedLeader = $identityService->resolveLeaderFlag(
                (int) $retreat->id,
                $phoneE164,
                $existingParticipant
            );

            $payload = [
                'name' => $request->validated('name'),
                'phone_e164' => $phoneE164,
                'gender' => Schema::hasColumn('retreat_participants', 'gender')
                    ? $request->validated('gender')
                    : null,
                'device_token' => Str::uuid()->toString(),
                'expo_push_token' => $request->validated('expo_push_token'),
                'vehicle_color' => $request->validated('vehicle_color'),
                'vehicle_description' => $request->validated('vehicle_description'),
                'is_leader' => $resolvedLeader,
                'last_seen_at' => now(),
            ];

            if ($existingParticipant) {
                $existingParticipant->fill($payload);
                $existingParticipant->save();

                return $existingParticipant;
            }

            return RetreatParticipant::create([
                'retreat_id' => $retreat->id,
                ...$payload,
                'joined_at' => now(),
            ]);
        });

        return response()->json([
            'data' => [
                'participant_id' => $participant->id,
                'device_token' => $participant->device_token,
                'identity' => [
                    'phone_display' => $participant->phone_display,
                    'continuity_mode' => 'phone_no_otp',
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

    public function deleteAccount(DeleteAccountRequest $request): JsonResponse
    {
        /** @var RetreatParticipant $participant */
        $participant = $request->attributes->get('participant');

        if ($participant->avatar_path) {
            Storage::disk('public')->delete($participant->avatar_path);
        }

        $participantId = $participant->id;
        $retreatId = $participant->retreat_id;

        $participant->delete();

        return response()->json([
            'data' => [
                'deleted' => true,
                'participant_id' => $participantId,
                'retreat_id' => $retreatId,
            ],
        ]);
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
                    'phone_display' => $participant->phone_display,
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

    public function storeWaypoint(StoreWaypointRequest $request): JsonResponse
    {
        /** @var RetreatParticipant $participant */
        $participant = $request->attributes->get('participant');

        if (! $participant->is_leader) {
            return response()->json(['error' => 'Only leaders can manage waypoints'], 403);
        }

        /** @var Retreat $retreat */
        $retreat = $request->attributes->get('retreat');
        $payload = $request->validated();

        $nextOrder = (int) (($retreat->waypoints()->max('waypoint_order') ?? 0) + 1);
        $waypointOrder = (int) ($payload['waypoint_order'] ?? $nextOrder);

        $waypoint = RetreatWaypoint::create([
            'retreat_id' => $retreat->id,
            'name' => $payload['name'],
            'description' => $payload['description'] ?? null,
            'latitude' => $payload['latitude'],
            'longitude' => $payload['longitude'],
            'waypoint_order' => $waypointOrder,
            'eta' => $payload['eta'] ?? null,
            'created_at' => now(),
        ]);

        if (($payload['set_as_destination'] ?? false) === true) {
            $retreat->update([
                'destination_name' => $waypoint->name,
                'destination_lat' => $waypoint->latitude,
                'destination_lng' => $waypoint->longitude,
            ]);
            $retreat->refresh();
        }

        return response()->json([
            'data' => [
                'id' => $waypoint->id,
                'name' => $waypoint->name,
                'description' => $waypoint->description,
                'lat' => (float) $waypoint->latitude,
                'lng' => (float) $waypoint->longitude,
                'order' => $waypoint->waypoint_order,
                'eta' => $waypoint->eta?->toIso8601String(),
            ],
            'meta' => [
                'destination' => $retreat->destination_name ? [
                    'name' => $retreat->destination_name,
                    'lat' => (float) $retreat->destination_lat,
                    'lng' => (float) $retreat->destination_lng,
                ] : null,
            ],
        ], 201);
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
