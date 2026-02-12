<?php

namespace Tests\Feature;

use App\Models\Retreat;
use App\Services\SpacetimeLocationMirror;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Mockery\MockInterface;
use Tests\TestCase;

class SpacetimeLocationMirrorTest extends TestCase
{
    use RefreshDatabase;

    private function createActiveRetreat(array $overrides = []): Retreat
    {
        return Retreat::create(array_merge([
            'name' => 'Mirror Test Retreat',
            'code' => 'MRROR1',
            'destination_name' => 'Test Destination',
            'destination_lat' => 36.611158,
            'destination_lng' => -93.306554,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
        ], $overrides));
    }

    public function test_location_endpoint_calls_spacetime_mirror_service(): void
    {
        $this->createActiveRetreat();

        $join = $this->postJson('/api/v1/retreat/join', [
            'code' => 'MRROR1',
            'name' => 'Tester',
        ])->assertOk()->json('data');

        $payload = [
            'latitude' => 36.611158,
            'longitude' => -93.306554,
            'accuracy' => 8,
            'speed' => 2,
            'heading' => 45,
            'altitude' => 302,
            'recorded_at' => now()->toIso8601String(),
        ];

        $this->mock(SpacetimeLocationMirror::class, function (MockInterface $mock) use ($join, $payload): void {
            $mock->shouldReceive('mirrorLatestLocation')
                ->once()
                ->withArgs(function (int $participantId, int $retreatId, array $mirrorPayload) use ($join, $payload): bool {
                    return $participantId === (int) $join['participant_id']
                        && $retreatId === (int) $join['retreat']['id']
                        && (float) $mirrorPayload['latitude'] === (float) $payload['latitude']
                        && (float) $mirrorPayload['longitude'] === (float) $payload['longitude']
                        && (string) $mirrorPayload['recorded_at'] === (string) $payload['recorded_at'];
                });
        });

        $this->postJson('/api/v1/retreat/location', $payload, [
            'X-Device-Token' => $join['device_token'],
        ])->assertOk()
            ->assertJsonPath('data.recorded', true);
    }

    public function test_spacetime_mirror_service_is_noop_when_feature_flag_is_disabled(): void
    {
        Process::fake();

        config()->set('spacetime.location_mirror_enabled', false);

        app(SpacetimeLocationMirror::class)->mirrorLatestLocation(1, 2, [
            'latitude' => 35.1,
            'longitude' => -90.2,
            'recorded_at' => now()->toIso8601String(),
        ]);

        Process::assertNothingRan();
    }

    public function test_spacetime_mirror_service_executes_upsert_when_enabled(): void
    {
        Process::fake();

        config()->set('spacetime.location_mirror_enabled', true);
        config()->set('spacetime.cli_path', 'spacetime');
        config()->set('spacetime.server', 'local');
        config()->set('spacetime.database', 'caravan-smoke-db');
        config()->set('spacetime.anonymous', true);

        app(SpacetimeLocationMirror::class)->mirrorLatestLocation(15, 9, [
            'latitude' => 35.1,
            'longitude' => -90.2,
            'accuracy' => 5,
            'speed' => 0,
            'heading' => 180,
            'altitude' => 100,
            'recorded_at' => now()->toIso8601String(),
        ]);

        Process::assertRan(function ($process): bool {
            if (!is_array($process->command)) {
                return false;
            }

            return in_array('caravan-smoke-db', $process->command, true)
                && in_array('upsert_location', $process->command, true)
                && in_array('--anonymous', $process->command, true)
                && in_array('15', $process->command, true)
                && in_array('9', $process->command, true);
        });
    }
}
