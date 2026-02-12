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

        DB::table('retreat_participants')
            ->where('retreat_id', (int) $retreatId)
            ->whereRaw('LOWER(TRIM(name)) = ?', ['map probe'])
            ->delete();
    }

    public function down(): void
    {
        // Intentional no-op. Removed test participant should not be restored.
    }
};
