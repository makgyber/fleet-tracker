<?php

namespace App\Services;

use App\Models\PositionHistory;
use App\Models\Trip;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;
use Kreait\Firebase\Contract\Database as FirebaseDatabase;
use Throwable;

/**
 * Thin wrapper over the Firebase Admin SDK that:
 *  - reports whether Firebase is configured (so the app runs without it),
 *  - verifies driver ID tokens for GPS ingest,
 *  - publishes computed routes/ETAs to the Realtime Database,
 *  - mirrors live positions into SQLite (position_histories).
 *
 * Realtime Database layout this service assumes:
 *   /vehicles/{vehicleId}/position   -> { lat, lng, speed, heading, accuracy, ts }
 *   /trips/{tripId}/route            -> { geometry, total_distance_m, total_duration_s, stops:[...] }
 */
class FirebaseService
{
    public function __construct(
        private readonly ?FirebaseDatabase $database = null,
        private readonly ?FirebaseAuth $auth = null,
    ) {
    }

    /**
     * Firebase is only usable when both a credentials file and a database URL
     * are configured. Everything else in the app works without it.
     */
    public function isConfigured(): bool
    {
        return ! empty(config('firebase.projects.app.credentials'))
            && ! empty(config('firebase.projects.app.database.url'));
    }

    /**
     * Verify a Firebase Auth ID token and return its UID, or null if invalid
     * or Firebase is not configured.
     */
    public function verifyIdToken(string $idToken): ?string
    {
        if (! $this->isConfigured() || ! $this->auth) {
            return null;
        }

        try {
            $verified = $this->auth->verifyIdToken($idToken);

            return $verified->claims()->get('sub');
        } catch (Throwable $e) {
            Log::info('Firebase ID token verification failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Publish a trip's optimized route + per-stop ETAs to the Realtime Database
     * so the driver app and dashboard receive it live.
     */
    public function publishTripRoute(Trip $trip): void
    {
        if (! $this->isConfigured() || ! $this->database) {
            return;
        }

        $trip->loadMissing('stops.destination');

        $payload = [
            'status' => $trip->status,
            'total_distance_m' => $trip->total_distance_m,
            'total_duration_s' => $trip->total_duration_s,
            'geometry' => $trip->route_geometry ? json_decode($trip->route_geometry, true) : null,
            'computed_at' => optional($trip->route_computed_at)->toIso8601String(),
            'stops' => $trip->stops->map(fn ($s) => [
                'id' => $s->id,
                'sequence' => $s->sequence,
                'destination' => [
                    'id' => $s->destination->id,
                    'name' => $s->destination->name,
                    'lat' => $s->destination->latitude,
                    'lng' => $s->destination->longitude,
                ],
                'eta' => optional($s->eta)->toIso8601String(),
                'status' => $s->status,
            ])->values()->all(),
        ];

        try {
            $this->database->getReference("trips/{$trip->id}/route")->set($payload);
        } catch (Throwable $e) {
            Log::warning('Failed to publish trip route to Firebase', [
                'trip_id' => $trip->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Publish a vehicle's latest position to the Realtime Database. Usually the
     * driver app writes this directly; the server uses it for server-originated
     * updates (e.g. the simulator) and to keep a canonical copy.
     */
    public function publishVehiclePosition(Vehicle $vehicle, array $position): void
    {
        if (! $this->isConfigured() || ! $this->database) {
            return;
        }

        try {
            $this->database->getReference("vehicles/{$vehicle->id}/position")->set($position);
        } catch (Throwable $e) {
            Log::warning('Failed to publish vehicle position to Firebase', [
                'vehicle_id' => $vehicle->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Persist a position fix into SQLite history. Works regardless of whether
     * Firebase is configured (the caller supplies the data).
     *
     * @param  array{lat: float, lng: float, speed?: float|null, heading?: float|null, accuracy?: float|null, ts?: int|string|null, trip_id?: int|null}  $fix
     */
    public function recordPosition(Vehicle $vehicle, array $fix): PositionHistory
    {
        $recordedAt = isset($fix['ts'])
            ? (is_numeric($fix['ts']) ? Carbon::createFromTimestampMs((int) $fix['ts']) : Carbon::parse($fix['ts']))
            : Carbon::now();

        return PositionHistory::create([
            'vehicle_id' => $vehicle->id,
            'trip_id' => $fix['trip_id'] ?? null,
            'latitude' => $fix['lat'],
            'longitude' => $fix['lng'],
            'speed_mps' => $fix['speed'] ?? null,
            'heading_deg' => $fix['heading'] ?? null,
            'accuracy_m' => $fix['accuracy'] ?? null,
            'recorded_at' => $recordedAt,
        ]);
    }
}
