<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PositionHistory;
use App\Models\Vehicle;
use App\Services\FirebaseService;
use Illuminate\Http\Request;

class PositionController extends Controller
{
    public function __construct(private readonly FirebaseService $firebase)
    {
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

        return response()->json([
            'ok' => true,
            'recorded_at' => $position->recorded_at->toIso8601String(),
            'firebase' => $this->firebase->isConfigured() ? 'published' : 'disabled',
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
