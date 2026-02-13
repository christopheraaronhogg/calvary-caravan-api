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

        $retreatId = DB::table('retreats')
            ->where('code', 'CBCR26')
            ->value('id');

        if (! $retreatId) {
            return;
        }

        $participants = DB::table('retreat_participants')
            ->where('retreat_id', (int) $retreatId)
            ->whereRaw('LOWER(TRIM(name)) = ?', ['chris hogg'])
            ->orderByDesc('id')
            ->get(['id', 'phone_e164', 'is_leader']);

        if ($participants->count() <= 1) {
            return;
        }

        $keep = $participants->first(function ($row) {
            return trim((string) ($row->phone_e164 ?? '')) !== '';
        }) ?? $participants->first();

        if (! $keep) {
            return;
        }

        $keepId = (int) $keep->id;
        $dropIds = $participants
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->reject(fn ($id) => $id === $keepId)
            ->values();

        if ($dropIds->isEmpty()) {
            return;
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

    public function down(): void
    {
        // Irreversible cleanup migration.
    }
};
