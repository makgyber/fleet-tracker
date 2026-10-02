<?php

namespace App\Services;

use App\Models\Trip;
use App\Models\TripStop;
use Illuminate\Support\Carbon;

/**
 * Detects stop arrivals from GPS fixes. When a position lands within the
 * configured radius of a trip's pending stop, that stop is marked arrived.
 *
 * This runs in the position-ingest path (no extra routing-API cost) so the
 * dashboard and driver app reflect real progress even when the driver visits
 * destinations out of the recommended order.
 */
class ArrivalDetector
{
    /**
     * Check a position against a trip's not-yet-arrived stops and mark any
     * within range as arrived.
     *
     * @param  array{lat: float, lng: float}  $position
     * @return list<TripStop>  the stops whose status changed to arrived
     */
    public function detect(Trip $trip, array $position): array
    {
        $radius = (float) config('services.fleet.arrival_radius_m', 75);

        $trip->loadMissing('stops.destination');

        $changed = [];

        foreach ($trip->stops as $stop) {
            // Only consider stops not already arrived/skipped.
            if (in_array($stop->status, ['arrived', 'skipped'], true)) {
                continue;
            }
            if (! $stop->destination) {
                continue;
            }

            $distance = $this->haversine(
                $position['lat'],
                $position['lng'],
                (float) $stop->destination->latitude,
                (float) $stop->destination->longitude,
            );

            if ($distance <= $radius) {
                $stop->update([
                    'status' => 'arrived',
                    'arrived_at' => Carbon::now(),
                ]);
                $changed[] = $stop;
            }
        }

        return $changed;
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
