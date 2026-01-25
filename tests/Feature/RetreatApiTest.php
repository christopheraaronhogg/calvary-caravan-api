<?php

namespace Tests\Feature;

use App\Models\Retreat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetreatApiTest extends TestCase
{
    use RefreshDatabase;

    private function createActiveRetreat(array $overrides = []): Retreat
    {
        return Retreat::create(array_merge([
            'name' => 'Test Retreat',
            'code' => 'TEST26',
            'destination_name' => 'Test Destination',
            'destination_lat' => 36.611158,
            'destination_lng' => -93.306554,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ], $overrides));
    }

    public function test_join_rejects_invalid_code(): void
    {
        $this->createActiveRetreat(['code' => 'GOOD1']);

        $this->postJson('/api/v1/retreat/join', [
            'code' => 'BAD1',
            'name' => 'Tester',
        ])->assertStatus(422)
            ->assertJsonStructure(['error']);
    }

    public function test_full_happy_path_join_status_location_messages_leave(): void
    {
        $this->createActiveRetreat(['code' => 'TEST26']);

        $join = $this->postJson('/api/v1/retreat/join', [
            'code' => 'TEST26',
            'name' => 'Tester',
            'vehicle_color' => 'Blue',
            'vehicle_description' => 'Minivan',
        ])->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'participant_id',
                    'device_token',
                    'retreat' => ['id', 'name', 'destination', 'starts_at', 'ends_at'],
                ],
            ])
            ->json('data');

        $token = $join['device_token'];

        $this->getJson('/api/v1/retreat/status', [
            'X-Device-Token' => $token,
        ])->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'participant' => ['id', 'name', 'is_leader'],
                    'retreat' => ['id', 'name', 'destination', 'starts_at', 'ends_at', 'participant_count'],
                ],
            ]);

        $this->postJson('/api/v1/retreat/location', [
            'latitude' => 36.611158,
            'longitude' => -93.306554,
            'accuracy' => 10,
            'speed' => 0,
            'heading' => 0,
            'altitude' => 300,
            'recorded_at' => now()->toIso8601String(),
        ], [
            'X-Device-Token' => $token,
        ])->assertOk()
            ->assertJsonPath('data.recorded', true)
            ->assertJsonStructure(['data' => ['recorded', 'next_update_in']]);

        $this->getJson('/api/v1/retreat/locations', [
            'X-Device-Token' => $token,
        ])->assertOk()
            ->assertJsonStructure([
                'data' => [
                    ['participant_id', 'name', 'vehicle_color', 'vehicle_description', 'is_leader', 'is_current_user', 'location', 'last_seen_seconds_ago'],
                ],
                'meta' => ['total_participants', 'online_count', 'server_time'],
            ]);

        $this->postJson('/api/v1/retreat/messages', [
            'content' => 'Hello world',
            'message_type' => 'chat',
            'latitude' => 36.611158,
            'longitude' => -93.306554,
        ], [
            'X-Device-Token' => $token,
        ])->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'message_type', 'content', 'sender', 'created_at']]);

        $this->getJson('/api/v1/retreat/messages?limit=10', [
            'X-Device-Token' => $token,
        ])->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['latest_id', 'count']]);

        // Non-leader cannot send alert messages
        $this->postJson('/api/v1/retreat/messages', [
            'content' => 'Attention',
            'message_type' => 'alert',
        ], [
            'X-Device-Token' => $token,
        ])->assertStatus(403);

        $this->postJson('/api/v1/retreat/leave', [], [
            'X-Device-Token' => $token,
        ])->assertOk()
            ->assertJsonPath('data.left', true);

        // Token should no longer work after leaving
        $this->getJson('/api/v1/retreat/status', [
            'X-Device-Token' => $token,
        ])->assertStatus(401);
    }
}

