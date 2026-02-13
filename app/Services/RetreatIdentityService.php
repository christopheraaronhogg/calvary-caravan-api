<?php

namespace App\Services;

use App\Models\RetreatLeaderPhoneAllowlist;
use App\Models\RetreatParticipant;

class RetreatIdentityService
{
    private array $allowlistEnabledCache = [];

    private array $allowlistPhoneCache = [];

    public function retreatUsesPhoneLeaderAllowlist(int $retreatId): bool
    {
        if (array_key_exists($retreatId, $this->allowlistEnabledCache)) {
            return $this->allowlistEnabledCache[$retreatId];
        }

        $enabled = RetreatLeaderPhoneAllowlist::query()
            ->where('retreat_id', $retreatId)
            ->exists();

        $this->allowlistEnabledCache[$retreatId] = $enabled;

        return $enabled;
    }

    public function isPhoneAllowlistedForLeader(int $retreatId, string $phoneE164): bool
    {
        $cacheKey = $retreatId.':'.$phoneE164;

        if (array_key_exists($cacheKey, $this->allowlistPhoneCache)) {
            return $this->allowlistPhoneCache[$cacheKey];
        }

        $allowlisted = RetreatLeaderPhoneAllowlist::query()
            ->where('retreat_id', $retreatId)
            ->where('phone_e164', $phoneE164)
            ->exists();

        $this->allowlistPhoneCache[$cacheKey] = $allowlisted;

        return $allowlisted;
    }

    public function resolveLeaderFlag(int $retreatId, string $phoneE164, ?RetreatParticipant $existingParticipant = null): bool
    {
        if (! $this->retreatUsesPhoneLeaderAllowlist($retreatId)) {
            return (bool) ($existingParticipant?->is_leader ?? false);
        }

        return $this->isPhoneAllowlistedForLeader($retreatId, $phoneE164);
    }

    public function syncLeaderRole(RetreatParticipant $participant): void
    {
        if (! $participant->phone_e164) {
            return;
        }

        $resolved = $this->resolveLeaderFlag(
            (int) $participant->retreat_id,
            (string) $participant->phone_e164,
            $participant
        );

        if ((bool) $participant->is_leader === $resolved) {
            return;
        }

        $participant->update([
            'is_leader' => $resolved,
        ]);
    }
}
