<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('retreats')) {
            return;
        }

        $now = now();

        DB::table('retreats')->updateOrInsert(
            ['code' => 'CBCR26'],
            [
                'name' => 'Calvary Baptist Couples Retreat 2026',
                'destination_name' => 'TBD',
                'destination_lat' => null,
                'destination_lng' => null,
                'starts_at' => $now->copy()->subDay(),
                'ends_at' => $now->copy()->addYear(),
                'is_active' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('retreats')) {
            return;
        }

        DB::table('retreats')
            ->where('code', 'CBCR26')
            ->delete();
    }
};
