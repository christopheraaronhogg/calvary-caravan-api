<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateLocationRequest;
use App\Models\ParticipantLocation;
use App\Services\LocationPlaceLabelService;
use App\Services\SpacetimeLocationMirror;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class RetreatLocationController extends Controller
{
    public function update(UpdateLocationRequest $request, SpacetimeLocationMirror $spacetimeMirror): JsonResponse
    {
        $participant = $request->attributes->get('participant');
        $retreat = $request->attributes->get('retreat');

        if (! ((bool) ($participant->location_sharing_enabled ?? true))) {
            return response()->json([
                'error' => 'Location sharing is currently turned off in your profile',
            ], 409);
        }

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

    public function all(Request $request, LocationPlaceLabelService $placeLabelService): JsonResponse
    {
        $retreat = $request->attributes->get('retreat');
        $currentParticipant = $request->attributes->get('participant');

        $participantModels = $retreat->participants()
            ->whereNotNull('device_token')
            ->with('latestLocation')
            ->get();

        $participantModels = $this->collapseLegacyChrisHoggDuplicates(
            $participantModels,
            (int) $currentParticipant->id
        );

        $participants = $participantModels->map(function ($participant) use ($currentParticipant, $placeLabelService) {
                $location = $participant->latestLocation;
                $locationSharingEnabled = (bool) ($participant->location_sharing_enabled ?? true);

                $locationPayload = null;

                if ($locationSharingEnabled && $location) {
                    $lat = (float) $location->latitude;
                    $lng = (float) $location->longitude;
                    $accuracy = $location->accuracy ? (float) $location->accuracy : null;

                    $locationPayload = [
                        'lat' => $lat,
                        'lng' => $lng,
                        'accuracy' => $accuracy,
                        'speed' => $location->speed ? (float) $location->speed : null,
                        'heading' => $location->heading ? (float) $location->heading : null,
                        'recorded_at' => $location->recorded_at->toIso8601String(),
                        'place' => $placeLabelService->resolve($lat, $lng, $accuracy),
                    ];
                }

                return [
                    'participant_id' => $participant->id,
                    'name' => $participant->name,
                    'gender' => $participant->gender ?? null,
                    'avatar_url' => $participant->avatar_url,
                    'vehicle_color' => $participant->vehicle_color,
                    'vehicle_description' => $participant->vehicle_description,
                    'is_leader' => (bool) $participant->is_leader,
                    'is_current_user' => $participant->id === $currentParticipant->id,
                    'location_sharing_enabled' => $locationSharingEnabled,
                    'location' => $locationPayload,
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

    private function collapseLegacyChrisHoggDuplicates(Collection $participants, int $currentParticipantId): Collection
    {
        $result = collect();

        foreach ($participants->groupBy(fn ($participant) => $this->normalizeName($participant->name)) as $normalizedName => $group) {
            if ($normalizedName !== 'chris hogg' || $group->count() <= 1) {
                $result = $result->concat($group->values());
                continue;
            }

            $current = $group->first(fn ($participant) => (int) $participant->id === $currentParticipantId);
            $phoneLinked = $group->first(fn ($participant) => trim((string) ($participant->phone_e164 ?? '')) !== '');
            $mostRecent = $group->reduce(function ($carry, $participant) {
                if (! $carry) {
                    return $participant;
                }

                $carryTs = $carry->last_seen_at ? $carry->last_seen_at->getTimestamp() : 0;
                $participantTs = $participant->last_seen_at ? $participant->last_seen_at->getTimestamp() : 0;

                if ($participantTs > $carryTs) {
                    return $participant;
                }

                if ($participantTs === $carryTs && (int) $participant->id > (int) $carry->id) {
                    return $participant;
                }

                return $carry;
            });

            $canonical = $current ?? $phoneLinked ?? $mostRecent ?? $group->first();
            $result->push($canonical);
        }

        return $result->values();
    }

    private function normalizeName(?string $name): string
    {
        return mb_strtolower(trim((string) $name));
    }
}
