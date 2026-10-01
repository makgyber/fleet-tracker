<?php

namespace App\Console\Commands;

use App\Models\Trip;
use App\Models\Vehicle;
use App\Services\FirebaseService;
use Illuminate\Console\Command;

/**
 * Drives a vehicle along its trip's optimized route, emitting GPS fixes at a
 * fixed interval. Each fix is recorded to SQLite history and mirrored to the
 * Firebase Realtime Database (when configured), exactly like a real driver app.
 *
 * Usage:
 *   php artisan fleet:simulate {vehicle_id} --trip=ID --interval=3 --step=0.15
 */
class SimulateVehicle extends Command
{
    protected $signature = 'fleet:simulate
        {vehicle : Vehicle ID to drive}
        {--trip= : Trip ID whose route to follow; defaults to the active trip}
        {--interval=3 : Seconds between GPS fixes}
        {--step=0.2 : Fraction of a segment to advance per fix, 0-1}';

    protected $description = 'Simulate a vehicle moving along its optimized route, emitting GPS fixes.';

    public function handle(FirebaseService $firebase): int
    {
        $vehicle = Vehicle::find($this->argument('vehicle'));
        if (! $vehicle) {
            $this->error('Vehicle not found.');

            return self::FAILURE;
        }

        $trip = $this->option('trip')
            ? Trip::with('stops.destination')->find($this->option('trip'))
            : $vehicle->trips()->whereIn('status', ['optimized', 'in_progress'])->with('stops.destination')->latest()->first();

        if (! $trip) {
            $this->error('No trip/route found for this vehicle. Optimize a trip first.');

            return self::FAILURE;
        }

        // Build the ordered list of waypoints from the route geometry if present,
        // otherwise from the stops in optimized order.
        $waypoints = $this->waypoints($trip);
        if (count($waypoints) < 2) {
            $this->error('Route needs at least 2 points to simulate.');

            return self::FAILURE;
        }

        $interval = max(1, (int) $this->option('interval'));
        $step = min(1.0, max(0.01, (float) $this->option('step')));

        $this->info("Simulating vehicle #{$vehicle->id} along trip #{$trip->id} ({$waypoints[0]['label']} → ...). Ctrl+C to stop.");
        $this->line('Firebase: ' . ($firebase->isConfigured() ? 'publishing live' : 'disabled (history only)'));

        // Walk each segment, interpolating between consecutive waypoints.
        for ($i = 0; $i < count($waypoints) - 1; $i++) {
            $from = $waypoints[$i];
            $to = $waypoints[$i + 1];

            for ($t = 0.0; $t < 1.0; $t += $step) {
                $lat = $from['lat'] + ($to['lat'] - $from['lat']) * $t;
                $lng = $from['lng'] + ($to['lng'] - $from['lng']) * $t;
                $heading = $this->bearing($from['lat'], $from['lng'], $to['lat'], $to['lng']);

                $fix = [
                    'lat' => round($lat, 7),
                    'lng' => round($lng, 7),
                    'speed' => 11.0,
                    'heading' => round($heading, 1),
                    'accuracy' => 5.0,
                    'trip_id' => $trip->id,
                    'ts' => now()->valueOf(),
                ];

                $firebase->recordPosition($vehicle, $fix);
                $firebase->publishVehiclePosition($vehicle, $fix);

                $this->line(sprintf('  fix: %.5f, %.5f  hdg %d°', $lat, $lng, (int) $heading));
                sleep($interval);
            }
        }

        $this->info('Reached final destination.');

        return self::SUCCESS;
    }

    /** @return list<array{lat: float, lng: float, label: string}> */
    private function waypoints(Trip $trip): array
    {
        // Prefer the decoded route geometry (denser, smoother path).
        if ($trip->route_geometry) {
            $geo = json_decode($trip->route_geometry, true);
            if (isset($geo['coordinates']) && is_array($geo['coordinates'])) {
                return array_map(fn ($c) => [
                    'lat' => (float) $c[1],
                    'lng' => (float) $c[0],
                    'label' => 'route',
                ], $geo['coordinates']);
            }
        }

        // Fall back to stop centroids in optimized order.
        $points = [];
        if ($trip->origin_latitude !== null) {
            $points[] = ['lat' => (float) $trip->origin_latitude, 'lng' => (float) $trip->origin_longitude, 'label' => 'origin'];
        }
        foreach ($trip->stops as $stop) {
            $points[] = [
                'lat' => (float) $stop->destination->latitude,
                'lng' => (float) $stop->destination->longitude,
                'label' => $stop->destination->name,
            ];
        }

        return $points;
    }

    private function bearing(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLng = deg2rad($lng2 - $lng1);
        $y = sin($dLng) * cos(deg2rad($lat2));
        $x = cos(deg2rad($lat1)) * sin(deg2rad($lat2))
            - sin(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos($dLng);

        return fmod(rad2deg(atan2($y, $x)) + 360, 360);
    }
}
