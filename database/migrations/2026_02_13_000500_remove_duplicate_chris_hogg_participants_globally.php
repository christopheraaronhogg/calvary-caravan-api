<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('retreat_participants')) {
            return;
        }

        $retreatIds = DB::table('retreat_participants')
            ->whereRaw('LOWER(TRIM(name)) = ?', ['chris hogg'])
            ->groupBy('retreat_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('retreat_id');

        foreach ($retreatIds as $retreatId) {
            $participants = DB::table('retreat_participants')
                ->where('retreat_id', (int) $retreatId)
                ->whereRaw('LOWER(TRIM(name)) = ?', ['chris hogg'])
                ->orderByDesc('id')
                ->get(['id', 'phone_e164', 'is_leader', 'last_seen_at']);

            if ($participants->count() <= 1) {
                continue;
            }

            $keep = $participants->first(function ($row) {
                return trim((string) ($row->phone_e164 ?? '')) !== '';
            })
                ?? $participants->first(function ($row) {
                    return (bool) ($row->is_leader ?? false);
                })
                ?? $participants->reduce(function ($carry, $row) {
                    if (! $carry) {
                        return $row;
                    }

                    $carryTs = $carry->last_seen_at ? strtotime((string) $carry->last_seen_at) : 0;
                    $rowTs = $row->last_seen_at ? strtotime((string) $row->last_seen_at) : 0;

                    if ($rowTs > $carryTs) {
                        return $row;
                    }

                    if ($rowTs === $carryTs && (int) $row->id > (int) $carry->id) {
                        return $row;
                    }

                    return $carry;
                });

            if (! $keep) {
                continue;
            }

            $keepId = (int) $keep->id;
            $dropIds = $participants
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->reject(fn ($id) => $id === $keepId)
                ->values();

            if ($dropIds->isEmpty()) {
                continue;
            }

            DB::transaction(function () use ($participants, $keep, $keepId, $dropIds): void {
                $now = now();

                $leaderPresent = $participants->contains(function ($row) {
                    return (bool) ($row->is_leader ?? false);
                });

                if ($leaderPresent && ! (bool) ($keep->is_leader ?? false)) {
                    DB::table('retreat_participants')
                        ->where('id', $keepId)
                        ->update([
                            'is_leader' => true,
                            'updated_at' => $now,
                        ]);
                }

                if (Schema::hasTable('participant_locations')) {
                    DB::table('participant_locations')
                        ->whereIn('participant_id', $dropIds->all())
                        ->update([
                            'participant_id' => $keepId,
                        ]);
                }

                if (Schema::hasTable('retreat_messages')) {
                    DB::table('retreat_messages')
                        ->whereIn('participant_id', $dropIds->all())
                        ->update([
                            'participant_id' => $keepId,
                        ]);
                }

                DB::table('retreat_participants')
                    ->whereIn('id', $dropIds->all())
                    ->delete();
            });
        }
    }

    public function down(): void
    {
        // Intentionally irreversible cleanup.
    }
};
