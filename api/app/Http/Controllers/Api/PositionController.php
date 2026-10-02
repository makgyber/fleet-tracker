<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PositionHistory;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Services\ArrivalDetector;
use App\Services\FirebaseService;
use Illuminate\Http\Request;

class PositionController extends Controller
{
    public function __construct(
        private readonly FirebaseService $firebase,
        private readonly ArrivalDetector $arrivals,
    ) {
    }

    /**
     * Run geofence arrival detection for a trip against a position. Any stops
     * that flip to "arrived" trigger a refresh of the trip's route node in
     * Firebase so the dashboard + app update live. Returns the changed stops.
     */
    private function detectArrivals(?Trip $trip, array $position): array
    {
        if (! $trip) {
            return [];
        }

        $changed = $this->arrivals->detect($trip, $position);

        if (! empty($changed)) {
            $this->firebase->publishTripRoute($trip->fresh(['stops.destination']));
        }

        return $changed;
    }

    /**
     * Ingest a GPS fix for a vehicle: persist to SQLite history and mirror the
     * latest position to the Firebase Realtime Database for live consumers.
     *
     * In production the driver app writes positions to Firebase directly; this
     * endpoint gives a server-side path (used by the simulator and as a
     * canonical fallback) and keeps the authoritative history in SQLite.
     */
    public function store(Request $request, Vehicle $vehicle)
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'speed' => ['nullable', 'numeric', 'min:0'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'trip_id' => ['nullable', 'integer', 'exists:trips,id'],
            'ts' => ['nullable'],
        ]);

        $position = $this->firebase->recordPosition($vehicle, $data);

        $this->firebase->publishVehiclePosition($vehicle, [
            'lat' => (float) $data['lat'],
            'lng' => (float) $data['lng'],
            'speed' => $data['speed'] ?? null,
            'heading' => $data['heading'] ?? null,
            'accuracy' => $data['accuracy'] ?? null,
            'trip_id' => $data['trip_id'] ?? null,
            'ts' => $position->recorded_at->valueOf(),
        ]);

        // Resolve the trip to check for arrivals: the explicit trip_id if given,
        // otherwise the vehicle's current active trip.
        $trip = isset($data['trip_id'])
            ? Trip::find($data['trip_id'])
            : $vehicle->trips()->whereIn('status', ['optimized', 'in_progress'])->latest()->first();

        $arrived = $this->detectArrivals($trip, ['lat' => (float) $data['lat'], 'lng' => (float) $data['lng']]);

        return response()->json([
            'ok' => true,
            'recorded_at' => $position->recorded_at->toIso8601String(),
            'firebase' => $this->firebase->isConfigured() ? 'published' : 'disabled',
            'arrived_stops' => array_map(fn ($s) => $s->id, $arrived),
        ], 201);
    }

    /**
     * PUBLIC: ingest a GPS fix from the driver app using the scanned team UUID
     * as the credential. Resolves the team's current trip + vehicle. Always
     * publishes the live position to Firebase (keyed by vehicle when assigned,
     * else by team); writes SQLite history only when a vehicle is assigned.
     */
    public function storeByTeam(Request $request, string $uuid)
    {
        $team = \App\Models\Team::where('team_uuid', $uuid)
            ->with(['trips' => fn ($q) => $q->latest()])
            ->first();

        if (! $team) {
            return response()->json(['message' => 'Team not found.'], 404);
        }

        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'speed' => ['nullable', 'numeric', 'min:0'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'ts' => ['nullable'],
        ]);

        $trip = $team->trips->first();
        $vehicle = $trip?->vehicle_id ? Vehicle::find($trip->vehicle_id) : $team->vehicle;

        $fix = [
            'lat' => (float) $data['lat'],
            'lng' => (float) $data['lng'],
            'speed' => $data['speed'] ?? null,
            'heading' => $data['heading'] ?? null,
            'accuracy' => $data['accuracy'] ?? null,
            'trip_id' => $trip?->id,
            'ts' => now()->valueOf(),
        ];

        $recordedHistory = false;
        if ($vehicle) {
            $this->firebase->recordPosition($vehicle, $fix);
            $this->firebase->publishVehiclePosition($vehicle, $fix);
            $recordedHistory = true;
        }

        // Always publish under the team so the dashboard can track it even
        // before a fleet vehicle is assigned.
        $this->firebase->publishTeamPosition($team, $fix);

        // Auto-detect arrivals against the team's trip stops.
        $arrived = $this->detectArrivals($trip, ['lat' => (float) $data['lat'], 'lng' => (float) $data['lng']]);

        return response()->json([
            'ok' => true,
            'history_recorded' => $recordedHistory,
            'firebase' => $this->firebase->isConfigured() ? 'published' : 'disabled',
            'arrived_stops' => array_map(fn ($s) => $s->id, $arrived),
        ], 201);
    }

    /**
     * Return recent position history for a vehicle (for replay / debugging).
     */
    public function index(Request $request, Vehicle $vehicle)
    {
        $limit = min((int) $request->query('limit', 100), 1000);

        $positions = $vehicle->positions()
            ->latest('recorded_at')
            ->limit($limit)
            ->get(['id', 'latitude', 'longitude', 'speed_mps', 'heading_deg', 'recorded_at']);

        return response()->json(['data' => $positions]);
    }
}
