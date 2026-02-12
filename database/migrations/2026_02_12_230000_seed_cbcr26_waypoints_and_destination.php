<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('retreats') || ! Schema::hasTable('retreat_waypoints')) {
            return;
        }

        $retreat = DB::table('retreats')
            ->where('code', 'CBCR26')
            ->first(['id']);

        if (! $retreat) {
            return;
        }

        $retreatId = (int) $retreat->id;
        $now = now();

        DB::table('retreats')
            ->where('id', $retreatId)
            ->update([
                'destination_name' => 'Chateau on the Lake Resort Spa & Convention Center',
                'destination_lat' => 36.61111,
                'destination_lng' => -93.3068254,
                'updated_at' => $now,
            ]);

        $waypoints = [
            [
                'name' => 'Branson Landing Meetup',
                'description' => 'Group meetup/check-in before the final leg to Chateau on the Lake.',
                'latitude' => 36.6436856,
                'longitude' => -93.2183041,
                'waypoint_order' => 1,
            ],
            [
                'name' => 'Chateau on the Lake',
                'description' => 'Retreat destination and hotel arrival.',
                'latitude' => 36.61111,
                'longitude' => -93.3068254,
                'waypoint_order' => 2,
            ],
        ];

        foreach ($waypoints as $waypoint) {
            DB::table('retreat_waypoints')->updateOrInsert(
                [
                    'retreat_id' => $retreatId,
                    'waypoint_order' => $waypoint['waypoint_order'],
                ],
                [
                    'name' => $waypoint['name'],
                    'description' => $waypoint['description'],
                    'latitude' => $waypoint['latitude'],
                    'longitude' => $waypoint['longitude'],
                    'eta' => null,
                    'created_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('retreats') || ! Schema::hasTable('retreat_waypoints')) {
            return;
        }

        $retreat = DB::table('retreats')
            ->where('code', 'CBCR26')
            ->first(['id']);

        if (! $retreat) {
            return;
        }

        $retreatId = (int) $retreat->id;

        DB::table('retreat_waypoints')
            ->where('retreat_id', $retreatId)
            ->whereIn('name', ['Branson Landing Meetup', 'Chateau on the Lake'])
            ->whereIn('waypoint_order', [1, 2])
            ->delete();

        DB::table('retreats')
            ->where('id', $retreatId)
            ->where('destination_name', 'Chateau on the Lake Resort Spa & Convention Center')
            ->update([
                'destination_name' => 'TBD',
                'destination_lat' => null,
                'destination_lng' => null,
                'updated_at' => now(),
            ]);
    }
};
