<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

class SpacetimeLocationMirror
{
    public function mirrorLatestLocation(int $participantId, int $retreatId, array $locationPayload): void
    {
        if (!config('spacetime.location_mirror_enabled', false)) {
            return;
        }

        $database = trim((string) config('spacetime.database', ''));
        if ($database === '') {
            Log::warning('Spacetime mirror enabled but no SPACETIME_DATABASE configured.');
            return;
        }

        $recordedAtMs = $this->toMilliseconds($locationPayload['recorded_at'] ?? now()->toIso8601String());

        $command = [
            (string) config('spacetime.cli_path', 'spacetime'),
            'call',
            '--server',
            (string) config('spacetime.server', 'local'),
        ];

        if ((bool) config('spacetime.anonymous', false)) {
            $command[] = '--anonymous';
        }

        $command[] = '-y';
        $command[] = $database;
        $command[] = 'upsert_location';
        $command[] = '--';
        $command[] = (string) $participantId;
        $command[] = (string) $retreatId;
        $command[] = $this->toFloatString($locationPayload['latitude'] ?? 0);
        $command[] = $this->toFloatString($locationPayload['longitude'] ?? 0);
        $command[] = $this->toFloatString($locationPayload['accuracy'] ?? 0);
        $command[] = $this->toFloatString($locationPayload['speed'] ?? 0);
        $command[] = $this->toFloatString($locationPayload['heading'] ?? 0);
        $command[] = $this->toFloatString($locationPayload['altitude'] ?? 0);
        $command[] = (string) $recordedAtMs;

        try {
            $result = Process::timeout(max(1, (int) config('spacetime.timeout_seconds', 4)))->run($command);

            if ($result->failed()) {
                Log::warning('Spacetime location mirror call failed.', [
                    'database' => $database,
                    'server' => config('spacetime.server'),
                    'participant_id' => $participantId,
                    'retreat_id' => $retreatId,
                    'exit_code' => $result->exitCode(),
                    'error' => trim($result->errorOutput()),
                    'output' => trim($result->output()),
                ]);
            }
        } catch (Throwable $e) {
            Log::error('Spacetime location mirror threw exception.', [
                'database' => $database,
                'server' => config('spacetime.server'),
                'participant_id' => $participantId,
                'retreat_id' => $retreatId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function toMilliseconds(mixed $value): int
    {
        try {
            return (int) Carbon::parse((string) $value)->valueOf();
        } catch (Throwable) {
            return (int) now()->valueOf();
        }
    }

    private function toFloatString(mixed $value): string
    {
        return (string) ((float) ($value ?? 0));
    }
}
