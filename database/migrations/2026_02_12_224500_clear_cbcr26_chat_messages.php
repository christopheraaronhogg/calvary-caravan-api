<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('retreats') || ! Schema::hasTable('retreat_messages')) {
            return;
        }

        $retreatId = DB::table('retreats')
            ->where('code', 'CBCR26')
            ->value('id');

        if (! $retreatId) {
            return;
        }

        DB::table('retreat_messages')
            ->where('retreat_id', $retreatId)
            ->delete();
    }

    public function down(): void
    {
        // Destructive clear migration is intentionally irreversible.
    }
};
