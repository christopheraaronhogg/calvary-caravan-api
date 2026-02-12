<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class LocationPlaceLabelService
{
    public function resolve(float $latitude, float $longitude, ?float $accuracyMeters = null): ?array
    {
        if ($this->shouldSkipLookup()) {
            return null;
        }

        $cacheKey = sprintf(
            'retreat:place-label:%0.4f:%0.4f',
            round($latitude, 4),
            round($longitude, 4)
        );

        $ttlMinutes = max(5, (int) config('services.nominatim.cache_minutes', 90));

        $cached = Cache::remember($cacheKey, now()->addMinutes($ttlMinutes), function () use ($latitude, $longitude) {
            $lookup = $this->lookupPlace($latitude, $longitude);

            return $lookup ?? ['status' => 'none'];
        });

        if (! is_array($cached) || ($cached['status'] ?? null) !== 'ok') {
            return null;
        }

        return $this->qualifyLookup($cached, $latitude, $longitude, $accuracyMeters);
    }

    private function shouldSkipLookup(): bool
    {
        if (app()->environment('testing') && ! config('services.nominatim.allow_during_tests', false)) {
            return true;
        }

        return ! config('services.nominatim.enabled', true);
    }

    private function lookupPlace(float $latitude, float $longitude): ?array
    {
        $baseUrl = (string) config('services.nominatim.base_url', 'https://nominatim.openstreetmap.org/reverse');

        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'User-Agent' => (string) config('services.nominatim.user_agent', 'CalvaryCaravan/1.0 (support@calvarycaravan.on-forge.com)'),
                    'Accept-Language' => (string) config('services.nominatim.accept_language', 'en'),
                ])
                ->timeout((float) config('services.nominatim.timeout_seconds', 2.5))
                ->get($baseUrl, [
                    'format' => 'jsonv2',
                    'lat' => $latitude,
                    'lon' => $longitude,
                    'zoom' => 18,
                    'addressdetails' => 1,
                    'namedetails' => 1,
                ]);

            if (! $response->ok()) {
                return null;
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                return null;
            }

            $name = $this->extractName($payload);
            $targetLat = is_numeric($payload['lat'] ?? null) ? (float) $payload['lat'] : null;
            $targetLng = is_numeric($payload['lon'] ?? null) ? (float) $payload['lon'] : null;

            return [
                'status' => 'ok',
                'name' => $name,
                'class' => isset($payload['class']) ? (string) $payload['class'] : null,
                'type' => isset($payload['type']) ? (string) $payload['type'] : null,
                'target_lat' => $targetLat,
                'target_lng' => $targetLng,
                'display_name' => isset($payload['display_name']) ? (string) $payload['display_name'] : null,
                'address' => is_array($payload['address'] ?? null) ? $payload['address'] : [],
            ];
        } catch (Throwable $e) {
            Log::warning('Nominatim place lookup failed', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function qualifyLookup(array $lookup, float $sourceLat, float $sourceLng, ?float $accuracyMeters): ?array
    {
        $name = trim((string) ($lookup['name'] ?? ''));
        $targetLat = is_numeric($lookup['target_lat'] ?? null) ? (float) $lookup['target_lat'] : null;
        $targetLng = is_numeric($lookup['target_lng'] ?? null) ? (float) $lookup['target_lng'] : null;

        if ($name === '' || $targetLat === null || $targetLng === null) {
            return null;
        }

        $distanceMeters = $this->distanceMeters($sourceLat, $sourceLng, $targetLat, $targetLng);

        $accuracy = $accuracyMeters !== null && $accuracyMeters > 0
            ? min(max($accuracyMeters, 8), 250)
            : 30;

        $atThreshold = max(28, min(80, $accuracy * 1.4));
        $nearThreshold = max(55, min(180, $accuracy * 2.4));

        $class = strtolower((string) ($lookup['class'] ?? ''));
        $type = strtolower((string) ($lookup['type'] ?? ''));
        $isBuildingLike = in_array($class, [
            'building',
            'amenity',
            'shop',
            'tourism',
            'leisure',
            'office',
            'healthcare',
            'historic',
            'emergency',
            'public_transport',
            'railway',
        ], true) || in_array($type, ['building', 'hospital', 'school', 'church', 'civic'], true);

        if ($isBuildingLike && $distanceMeters <= $atThreshold) {
            return [
                'label' => 'At '.$name,
                'name' => $name,
                'relation' => 'at',
                'distance_m' => (int) round($distanceMeters),
                'confidence' => $distanceMeters <= ($atThreshold * 0.65) ? 'high' : 'medium',
                'source' => 'nominatim',
            ];
        }

        if ($distanceMeters <= $nearThreshold) {
            return [
                'label' => 'Near '.$name,
                'name' => $name,
                'relation' => 'near',
                'distance_m' => (int) round($distanceMeters),
                'confidence' => 'medium',
                'source' => 'nominatim',
            ];
        }

        return null;
    }

    private function extractName(array $payload): ?string
    {
        $direct = trim((string) ($payload['name'] ?? ''));
        if ($direct !== '') {
            return $direct;
        }

        $namedDetails = is_array($payload['namedetails'] ?? null) ? $payload['namedetails'] : [];
        foreach (['name', 'name:en', 'official_name', 'short_name', 'operator'] as $key) {
            $value = trim((string) ($namedDetails[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $address = is_array($payload['address'] ?? null) ? $payload['address'] : [];
        foreach (['amenity', 'building', 'shop', 'tourism', 'leisure', 'office', 'hospital', 'healthcare', 'attraction'] as $key) {
            $value = trim((string) ($address[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $display = trim((string) ($payload['display_name'] ?? ''));
        if ($display === '') {
            return null;
        }

        $first = trim(strtok($display, ','));

        return $first !== '' ? $first : null;
    }

    private function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
