<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('retreats') || ! Schema::hasTable('retreat_participants')) {
            return;
        }

        $retreat = DB::table('retreats')
            ->where('code', 'CBCR26')
            ->first(['id', 'destination_name', 'destination_lat', 'destination_lng']);

        if (! $retreat) {
            return;
        }

        $retreatId = (int) $retreat->id;
        $now = now();

        // Normalize the two requested names to Terran.
        DB::table('retreat_participants')
            ->where('retreat_id', $retreatId)
            ->where(function ($query) {
                $query->whereRaw('LOWER(TRIM(name)) = ?', ['chris super gay'])
                    ->orWhereRaw('LOWER(TRIM(name)) = ?', ['chris gay']);
            })
            ->update([
                'name' => 'Terran',
                'updated_at' => $now,
            ]);

        $participants = DB::table('retreat_participants')
            ->where('retreat_id', $retreatId)
            ->orderByDesc('id')
            ->get(['id', 'name', 'is_leader']);

        if ($participants->isNotEmpty()) {
            $terran = $participants->first(function ($participant) {
                return strtolower(trim((string) $participant->name)) === 'terran';
            });

            $leader = $participants->first(function ($participant) use ($terran) {
                if (! (bool) $participant->is_leader) {
                    return false;
                }

                return ! $terran || (int) $participant->id !== (int) $terran->id;
            });

            $fallbackChris = $participants->first(function ($participant) use ($terran) {
                $name = strtolower(trim((string) $participant->name));
                if ($terran && (int) $participant->id === (int) $terran->id) {
                    return false;
                }

                return str_contains($name, 'chris');
            });

            $fallbackAny = $participants->first(function ($participant) use ($terran) {
                return ! $terran || (int) $participant->id !== (int) $terran->id;
            });

            $keepIds = collect([
                $leader?->id,
                $fallbackChris?->id,
                $terran?->id,
            ])->filter()->unique()->values();

            if ($keepIds->isEmpty() && $fallbackAny) {
                $keepIds->push((int) $fallbackAny->id);
            }

            // Cap active list to two participants: "me" + Terran.
            if ($keepIds->count() > 2) {
                $keepIds = $keepIds->slice(0, 2)->values();
            }

            if ($keepIds->count() === 1 && $fallbackAny && ! $keepIds->contains((int) $fallbackAny->id)) {
                $keepIds->push((int) $fallbackAny->id);
            }

            $dropIds = $participants
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->reject(fn ($id) => $keepIds->contains($id))
                ->values();

            if ($dropIds->isNotEmpty()) {
                DB::table('retreat_participants')
                    ->whereIn('id', $dropIds->all())
                    ->update([
                        'device_token' => null,
                        'expo_push_token' => null,
                        'last_seen_at' => $now,
                        'updated_at' => $now,
                    ]);

                if (Schema::hasTable('participant_locations')) {
                    DB::table('participant_locations')
                        ->whereIn('participant_id', $dropIds->all())
                        ->delete();
                }
            }
        }

        if (! Schema::hasTable('retreat_waypoints')) {
            return;
        }

        $chateauWaypoint = DB::table('retreat_waypoints')
            ->where('retreat_id', $retreatId)
            ->where(function ($query) {
                $query->whereRaw('LOWER(name) LIKE ?', ['%chateau%'])
                    ->orWhereRaw('LOWER(COALESCE(description, "")) LIKE ?', ['%chateau%']);
            })
            ->orderBy('waypoint_order')
            ->orderBy('id')
            ->first(['id']);

        $chateauLat = $retreat->destination_lat ?? 36.61111;
        $chateauLng = $retreat->destination_lng ?? -93.3068254;
        $chateauName = $retreat->destination_name ?: 'Chateau on the Lake';

        $chateauWaypointId = null;

        if ($chateauWaypoint) {
            $chateauWaypointId = (int) $chateauWaypoint->id;

            DB::table('retreat_waypoints')
                ->where('id', $chateauWaypointId)
                ->update([
                    'name' => $chateauName,
                    'description' => 'Retreat destination and hotel arrival.',
                    'latitude' => $chateauLat,
                    'longitude' => $chateauLng,
                    'waypoint_order' => 1,
                    'eta' => null,
                ]);
        } else {
            $chateauWaypointId = (int) DB::table('retreat_waypoints')->insertGetId([
                'retreat_id' => $retreatId,
                'name' => $chateauName,
                'description' => 'Retreat destination and hotel arrival.',
                'latitude' => $chateauLat,
                'longitude' => $chateauLng,
                'waypoint_order' => 1,
                'eta' => null,
                'created_at' => $now,
            ]);
        }

        DB::table('retreat_waypoints')
            ->where('retreat_id', $retreatId)
            ->where('id', '!=', $chateauWaypointId)
            ->delete();
    }

    public function down(): void
    {
        // Intentionally irreversible data cleanup for production launch.
    }
};
