<?php

namespace App\Console\Commands;

use App\Models\Retreat;
use App\Models\RetreatLeaderPhoneAllowlist;
use App\Models\RetreatParticipant;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;

class ManageRetreatLeaderPhone extends Command
{
    protected $signature = 'retreat:leader-phone
                            {retreat : Retreat ID or invite code}
                            {phone? : Phone number to add/remove (E.164 or US format)}
                            {--remove : Remove this phone from leader allowlist}
                            {--list : List all allowlisted leader phones for the retreat}';

    protected $description = 'Manage phone-based retreat leader allowlist entries';

    public function handle(): int
    {
        $retreatInput = (string) $this->argument('retreat');

        $retreat = $this->resolveRetreat($retreatInput);

        if (! $retreat) {
            $this->error("Retreat not found: {$retreatInput}");

            return self::FAILURE;
        }

        if ($this->option('list')) {
            return $this->listAllowlist($retreat);
        }

        $rawPhone = $this->argument('phone');

        if (! is_string($rawPhone) || trim($rawPhone) === '') {
            $this->error('A phone number is required unless using --list.');

            return self::FAILURE;
        }

        $phone = PhoneNumber::normalize($rawPhone);

        if (! $phone) {
            $this->error('Invalid phone number. Provide E.164 (e.g. +15012315761) or a valid US 10-digit number.');

            return self::FAILURE;
        }

        if ($this->option('remove')) {
            $deleted = RetreatLeaderPhoneAllowlist::query()
                ->where('retreat_id', $retreat->id)
                ->where('phone_e164', $phone)
                ->delete();

            $demoted = RetreatParticipant::query()
                ->where('retreat_id', $retreat->id)
                ->where('phone_e164', $phone)
                ->where('is_leader', true)
                ->update(['is_leader' => false]);

            if ($deleted > 0) {
                $this->info("Removed {$phone} from leader allowlist for retreat {$retreat->code}.");
            } else {
                $this->warn("{$phone} was not present in leader allowlist for retreat {$retreat->code}.");
            }

            if ($demoted > 0) {
                $this->line("Demoted {$demoted} participant record(s) tied to {$phone}.");
            }

            return self::SUCCESS;
        }

        RetreatLeaderPhoneAllowlist::query()->updateOrCreate(
            [
                'retreat_id' => $retreat->id,
                'phone_e164' => $phone,
            ],
            []
        );

        $promoted = RetreatParticipant::query()
            ->where('retreat_id', $retreat->id)
            ->where('phone_e164', $phone)
            ->where('is_leader', false)
            ->update(['is_leader' => true]);

        $this->info("Allowlisted {$phone} as a leader identity for retreat {$retreat->code}.");

        if ($promoted > 0) {
            $this->line("Promoted {$promoted} participant record(s) tied to {$phone}.");
        }

        return self::SUCCESS;
    }

    private function resolveRetreat(string $value): ?Retreat
    {
        if (ctype_digit($value)) {
            $found = Retreat::query()->find((int) $value);
            if ($found) {
                return $found;
            }
        }

        return Retreat::query()->where('code', strtoupper($value))->first();
    }

    private function listAllowlist(Retreat $retreat): int
    {
        $rows = RetreatLeaderPhoneAllowlist::query()
            ->where('retreat_id', $retreat->id)
            ->orderBy('phone_e164')
            ->get(['phone_e164', 'created_at'])
            ->map(function ($row) {
                return [
                    'Phone (E.164)' => $row->phone_e164,
                    'Masked' => PhoneNumber::mask($row->phone_e164),
                    'Added At' => $row->created_at?->toDateTimeString(),
                ];
            })
            ->all();

        if (empty($rows)) {
            $this->warn("No phone-based leader allowlist entries for retreat {$retreat->code}.");

            return self::SUCCESS;
        }

        $this->table(['Phone (E.164)', 'Masked', 'Added At'], $rows);

        return self::SUCCESS;
    }
}
