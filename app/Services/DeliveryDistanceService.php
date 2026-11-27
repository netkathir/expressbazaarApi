<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Distance between two UK postcodes, used to price delivery per kilometre.
 *
 * Postcodes are turned into coordinates with the free postcodes.io service
 * (no API key) and the straight-line (great-circle) distance is calculated.
 * Successful lookups are cached, so each postcode is only looked up once.
 */
class DeliveryDistanceService
{
    private const EARTH_RADIUS_KM = 6371.0088;

    /** Straight-line distance in km, or null when either postcode cannot be located. */
    public function distanceKm(?string $fromPostcode, ?string $toPostcode): ?float
    {
        $from = $this->coordinates($fromPostcode);
        $to = $this->coordinates($toPostcode);

        if (! $from || ! $to) {
            return null;
        }

        return $this->haversine($from, $to);
    }

    /**
     * Whole kilometres to charge for: rounded up, never below 1.
     * The distance is rounded to 2 decimals first so 3.0000001 km is not billed as 4.
     */
    public function billableKm(float $distanceKm): int
    {
        return max(1, (int) ceil(round($distanceKm, 2)));
    }

    /** @return array{0: float, 1: float}|null  [latitude, longitude] */
    public function coordinates(?string $postcode): ?array
    {
        $postcode = mb_strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $postcode));

        if ($postcode === '') {
            return null;
        }

        $cacheKey = 'postcode-coordinates:'.$postcode;

        if (is_array($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        // A district only (for example SO14) is looked up as an outcode and uses the centre
        // of that district. Anything else is looked up as a full postcode.
        $isOutcode = (bool) preg_match('/^[A-Z]{1,2}\d[A-Z\d]?$/', $postcode);
        $url = 'https://api.postcodes.io/'.($isOutcode ? 'outcodes/' : 'postcodes/').$postcode;

        try {
            $response = Http::timeout(5)->acceptJson()->get($url);
        } catch (Throwable $exception) {
            Log::warning('Postcode lookup failed.', ['postcode' => $postcode, 'error' => $exception->getMessage()]);

            return null;
        }

        $latitude = $response->json('result.latitude');
        $longitude = $response->json('result.longitude');

        if (! $response->successful() || ! is_numeric($latitude) || ! is_numeric($longitude)) {
            return null;
        }

        $coordinates = [(float) $latitude, (float) $longitude];
        Cache::forever($cacheKey, $coordinates);

        return $coordinates;
    }

    /** @param array{0: float, 1: float} $a @param array{0: float, 1: float} $b */
    private function haversine(array $a, array $b): float
    {
        [$lat1, $lon1] = array_map('deg2rad', $a);
        [$lat2, $lon2] = array_map('deg2rad', $b);

        $h = sin(($lat2 - $lat1) / 2) ** 2
            + cos($lat1) * cos($lat2) * sin(($lon2 - $lon1) / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($h)));
    }
}
