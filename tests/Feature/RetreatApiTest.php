<?php

namespace Tests\Feature;

use App\Models\Retreat;
use App\Models\RetreatParticipant;
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
            'phone_number' => '5012315761',
        ])->assertStatus(422)
            ->assertJsonStructure(['error']);
    }

    public function test_full_happy_path_join_status_location_messages_leave(): void
    {
        $this->createActiveRetreat(['code' => 'TEST26']);

        $join = $this->postJson('/api/v1/retreat/join', [
            'code' => 'TEST26',
            'name' => 'Tester',
            'phone_number' => '(501) 231-5761',
            'vehicle_color' => 'Blue',
            'vehicle_description' => 'Minivan',
        ])->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'participant_id',
                    'device_token',
                    'identity' => ['phone_display', 'continuity_mode'],
                    'retreat' => ['id', 'name', 'destination', 'starts_at', 'ends_at'],
                ],
            ])
            ->assertJsonPath('data.identity.continuity_mode', 'phone_no_otp')
            ->json('data');

        $token = $join['device_token'];

        $this->getJson('/api/v1/retreat/status', [
            'X-Device-Token' => $token,
        ])->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'participant' => ['id', 'name', 'is_leader', 'avatar_url'],
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
                    ['participant_id', 'name', 'avatar_url', 'vehicle_color', 'vehicle_description', 'is_leader', 'is_current_user', 'location', 'last_seen_seconds_ago'],
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

    public function test_leader_can_add_waypoint_and_update_destination(): void
    {
        $retreat = $this->createActiveRetreat(['code' => 'TEST26']);

        $join = $this->postJson('/api/v1/retreat/join', [
            'code' => 'TEST26',
            'name' => 'Leader Tester',
            'phone_number' => '+15012315761',
        ])->assertOk()->json('data');

        $token = $join['device_token'];

        RetreatParticipant::query()
            ->where('id', $join['participant_id'])
            ->update(['is_leader' => true]);

        $this->postJson('/api/v1/retreat/waypoints', [
            'name' => 'Branson Landing Meetup',
            'description' => 'Group meetup before convoy handoff.',
            'latitude' => 36.6436856,
            'longitude' => -93.2183041,
            'set_as_destination' => true,
        ], [
            'X-Device-Token' => $token,
        ])->assertStatus(201)
            ->assertJsonPath('data.name', 'Branson Landing Meetup')
            ->assertJsonPath('data.order', 1)
            ->assertJsonPath('meta.destination.name', 'Branson Landing Meetup');

        $this->assertDatabaseHas('retreat_waypoints', [
            'retreat_id' => $retreat->id,
            'name' => 'Branson Landing Meetup',
            'waypoint_order' => 1,
        ]);
    }

    public function test_non_leader_cannot_add_waypoint(): void
    {
        $this->createActiveRetreat(['code' => 'TEST26']);

        $join = $this->postJson('/api/v1/retreat/join', [
            'code' => 'TEST26',
            'name' => 'Non Leader',
            'phone_number' => '+15012315761',
        ])->assertOk()->json('data');

        $this->postJson('/api/v1/retreat/waypoints', [
            'name' => 'Branson Landing Meetup',
            'latitude' => 36.6436856,
            'longitude' => -93.2183041,
        ], [
            'X-Device-Token' => $join['device_token'],
        ])->assertStatus(403)
            ->assertJsonPath('error', 'Only leaders can manage waypoints');
    }

    public function test_profile_photo_can_be_set_and_cleared(): void
    {
        $this->createActiveRetreat(['code' => 'TEST26']);

        $join = $this->postJson('/api/v1/retreat/join', [
            'code' => 'TEST26',
            'name' => 'Tester',
            'phone_number' => '+15012315761',
        ])->assertOk()->json('data');

        $token = $join['device_token'];
        $tinyPng = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/w8AAgMBgG8M3r4AAAAASUVORK5CYII=';

        $photoUpdate = $this->postJson('/api/v1/retreat/profile-photo', [
            'avatar_base64' => $tinyPng,
        ], [
            'X-Device-Token' => $token,
        ])->assertOk()->assertJsonStructure(['data' => ['avatar_url']]);

        $this->assertStringContainsString('/storage/retreat-avatars/', $photoUpdate->json('data.avatar_url'));

        $this->deleteJson('/api/v1/retreat/profile-photo', [], [
            'X-Device-Token' => $token,
        ])->assertOk()->assertJsonPath('data.avatar_url', null);
    }
}

