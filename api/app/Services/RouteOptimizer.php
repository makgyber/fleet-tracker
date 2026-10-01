<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Computes an efficient visiting order and per-leg ETAs for a set of stops.
 *
 * Primary path: Mapbox Optimization API v1 (solves the Traveling Salesperson
 * Problem and returns real driving distances/durations + route geometry).
 *
 * Fallback path (no MAPBOX_TOKEN configured): a local nearest-neighbor tour
 * with haversine distances and an assumed average speed. This keeps the whole
 * system runnable and testable without a paid key.
 */
class RouteOptimizer
{
    /** Assumed average driving speed for the local fallback (m/s ≈ 40 km/h). */
    private const FALLBACK_SPEED_MPS = 11.0;

    /**
     * @param  array{lat: float, lng: float}  $origin
     * @param  list<array{id: int, lat: float, lng: float}>  $stops
     * @return array{
     *     provider: string,
     *     total_distance_m: float,
     *     total_duration_s: float,
     *     geometry: mixed,
     *     order: list<array{id: int, sequence: int, leg_distance_m: float, leg_duration_s: float, cumulative_duration_s: float}>
     * }
     */
    public function optimize(array $origin, array $stops): array
    {
        if (count($stops) === 0) {
            return [
                'provider' => 'none',
                'total_distance_m' => 0.0,
                'total_duration_s' => 0.0,
                'geometry' => null,
                'order' => [],
            ];
        }

        $token = config('services.mapbox.token');

        if (! empty($token)) {
            try {
                return $this->optimizeWithMapbox($origin, $stops, $token);
            } catch (\Throwable $e) {
                // Never let a routing outage break trip planning; degrade to local.
                Log::warning('Mapbox optimization failed, using local fallback', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->optimizeLocally($origin, $stops);
    }

    /**
     * Mapbox Optimization API v1. Source fixed to the first coordinate (origin),
     * open route (no roundtrip), returning full geometry and per-leg annotations.
     */
    private function optimizeWithMapbox(array $origin, array $stops, string $token): array
    {
        $profile = config('services.mapbox.profile', 'mapbox/driving');
        $base = rtrim(config('services.mapbox.base_url'), '/');

        // Coordinate order: origin first, then the stops (as given).
        $coords = array_merge(
            [[$origin['lng'], $origin['lat']]],
            array_map(fn ($s) => [$s['lng'], $s['lat']], $stops),
        );
        $coordStr = implode(';', array_map(fn ($c) => "{$c[0]},{$c[1]}", $coords));

        $url = "{$base}/optimized-trips/v1/{$profile}/{$coordStr}";

        // For an open (non-roundtrip) route, the Optimization API requires BOTH
        // source and destination to be fixed. Omitting destination with
        // roundtrip=false returns code "NotImplemented".
        $response = Http::acceptJson()
            ->timeout(15)
            ->get($url, [
                'access_token' => $token,
                'source' => 'first',          // start at the vehicle's origin
                'destination' => 'last',      // end at the last supplied stop
                'roundtrip' => 'false',       // don't force return to origin
                'geometries' => 'geojson',
                'overview' => 'full',
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException("Mapbox HTTP {$response->status()}: {$response->body()}");
        }

        $body = $response->json();
        if (($body['code'] ?? null) !== 'Ok' || empty($body['trips'])) {
            throw new \RuntimeException('Mapbox returned code: ' . ($body['code'] ?? 'unknown'));
        }

        $trip = $body['trips'][0];
        $waypoints = $body['waypoints'] ?? [];
        $legs = $trip['legs'] ?? [];

        // waypoints[i] corresponds to input coordinate i. Its waypoint_index is
        // the position of that coordinate within the optimized tour. We skip the
        // origin (input index 0) and order the actual stops by their tour index.
        $stopWaypoints = [];
        foreach ($stops as $i => $stop) {
            $wp = $waypoints[$i + 1] ?? null; // +1 because origin is index 0
            $stopWaypoints[] = [
                'id' => $stop['id'],
                'tour_index' => $wp['waypoint_index'] ?? ($i + 1),
            ];
        }
        usort($stopWaypoints, fn ($a, $b) => $a['tour_index'] <=> $b['tour_index']);

        // legs[k] is the leg arriving at tour position k+1. Ordered stops line up
        // with legs[0..n-1] once sorted by tour index.
        $order = [];
        $cumulative = 0.0;
        foreach (array_values($stopWaypoints) as $seq => $sw) {
            $leg = $legs[$seq] ?? null;
            $legDuration = (float) ($leg['duration'] ?? 0);
            $legDistance = (float) ($leg['distance'] ?? 0);
            $cumulative += $legDuration;

            $order[] = [
                'id' => $sw['id'],
                'sequence' => $seq + 1,
                'leg_distance_m' => $legDistance,
                'leg_duration_s' => $legDuration,
                'cumulative_duration_s' => $cumulative,
            ];
        }

        return [
            'provider' => 'mapbox',
            'total_distance_m' => (float) ($trip['distance'] ?? 0),
            'total_duration_s' => (float) ($trip['duration'] ?? 0),
            'geometry' => $trip['geometry'] ?? null,
            'order' => $order,
        ];
    }

    /**
     * Local nearest-neighbor heuristic with haversine distances. Not optimal,
     * but a reasonable, dependency-free approximation for development.
     */
    private function optimizeLocally(array $origin, array $stops): array
    {
        $remaining = $stops;
        $current = ['lat' => $origin['lat'], 'lng' => $origin['lng']];
        $order = [];
        $geometry = [[$origin['lng'], $origin['lat']]];
        $totalDistance = 0.0;
        $totalDuration = 0.0;
        $cumulative = 0.0;
        $seq = 0;

        while (count($remaining) > 0) {
            // Pick the nearest remaining stop.
            $bestIdx = 0;
            $bestDist = INF;
            foreach ($remaining as $idx => $stop) {
                $d = $this->haversine($current['lat'], $current['lng'], $stop['lat'], $stop['lng']);
                if ($d < $bestDist) {
                    $bestDist = $d;
                    $bestIdx = $idx;
                }
            }

            $stop = $remaining[$bestIdx];
            $legDuration = $bestDist / self::FALLBACK_SPEED_MPS;
            $totalDistance += $bestDist;
            $totalDuration += $legDuration;
            $cumulative += $legDuration;
            $seq++;

            $order[] = [
                'id' => $stop['id'],
                'sequence' => $seq,
                'leg_distance_m' => round($bestDist, 1),
                'leg_duration_s' => round($legDuration, 1),
                'cumulative_duration_s' => round($cumulative, 1),
            ];
            $geometry[] = [$stop['lng'], $stop['lat']];

            $current = ['lat' => $stop['lat'], 'lng' => $stop['lng']];
            array_splice($remaining, $bestIdx, 1);
        }

        return [
            'provider' => 'local',
            'total_distance_m' => round($totalDistance, 1),
            'total_duration_s' => round($totalDuration, 1),
            'geometry' => ['type' => 'LineString', 'coordinates' => $geometry],
            'order' => $order,
        ];
    }

    /** Great-circle distance in meters. */
    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6_371_000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
