<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\ScheduleImporter;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    /**
     * List imported teams (optionally filtered by schedule date) with their
     * current trip summary.
     */
    public function index(Request $request)
    {
        $query = Team::query()
            ->with(['vehicle', 'trips' => fn ($q) => $q->latest()->with('stops.destination')])
            ->latest('imported_at');

        if ($date = $request->query('date')) {
            $query->whereDate('schedule_date', $date);
        }

        $teams = $query->get()->map(function (Team $team) {
            $trip = $team->trips->first();

            return [
                'id' => $team->id,
                'team_uuid' => $team->team_uuid,
                'code' => $team->code,
                'color' => $team->color,
                'schedule_date' => optional($team->schedule_date)->toDateString(),
                'members' => $team->members ?? [],
                'vehicle_id' => $team->vehicle_id,
                'vehicle' => $team->vehicle?->only(['id', 'label', 'registration']),
                'trip' => $trip ? [
                    'id' => $trip->id,
                    'status' => $trip->status,
                    'total_distance_m' => $trip->total_distance_m,
                    'total_duration_s' => $trip->total_duration_s,
                    'stops_count' => $trip->stops->count(),
                ] : null,
            ];
        });

        return response()->json(['data' => $teams]);
    }

    /**
     * Fleet overview: every team for a date with its full route geometry and
     * stops, in a single payload — so the dashboard can draw all teams and
     * destinations on the map at once without N per-team requests.
     */
    public function overview(Request $request)
    {
        $query = Team::query()
            ->with(['trips' => fn ($q) => $q->latest()->with('stops.destination')]);

        if ($date = $request->query('date')) {
            $query->whereDate('schedule_date', $date);
        }

        $teams = $query->get()
            ->filter(fn (Team $team) => $team->trips->first())
            ->map(function (Team $team) {
                $trip = $team->trips->first();

                return [
                    'id' => $team->id,
                    'team_uuid' => $team->team_uuid,
                    'code' => $team->code,
                    'color' => $team->color,
                    'trip_id' => $trip->id,
                    'status' => $trip->status,
                    'geometry' => $trip->route_geometry ? json_decode($trip->route_geometry, true) : null,
                    'stops' => $trip->stops->map(fn ($s) => [
                        'id' => $s->id,
                        'sequence' => $s->sequence,
                        'eta' => $s->eta,
                        'status' => $s->status,
                        'destination' => $s->destination ? [
                            'name' => $s->destination->name,
                            'latitude' => (float) $s->destination->latitude,
                            'longitude' => (float) $s->destination->longitude,
                        ] : null,
                    ])->values(),
                ];
            })
            ->values();

        return response()->json(['data' => $teams]);
    }

    /**
     * Trigger an import of the tbss schedule for a date (defaults to today).
     */
    public function import(Request $request, ScheduleImporter $importer)
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
        ]);

        try {
            $result = $importer->import($validated['date'] ?? null);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json($result);
    }

    /**
     * PUBLIC: resolve a team by its QR UUID and return its current trip with
     * route + stops. No auth — the UUID itself is the hard-to-guess credential
     * printed on the team's QR code. Used by the driver app after scanning.
     */
    public function byUuid(string $uuid)
    {
        $team = Team::where('team_uuid', $uuid)
            ->with(['trips' => fn ($q) => $q->latest()->with('stops.destination')])
            ->first();

        if (! $team) {
            return response()->json(['message' => 'Team not found.'], 404);
        }

        $trip = $team->trips->first();

        return response()->json([
            'team' => [
                'uuid' => $team->team_uuid,
                'code' => $team->code,
                'color' => $team->color,
                'schedule_date' => optional($team->schedule_date)->toDateString(),
                'members' => $team->members ?? [],
            ],
            // vehicle_id drives position ingest from the app.
            'vehicle_id' => $trip?->vehicle_id ?? $team->vehicle_id,
            'trip' => $trip ? [
                'id' => $trip->id,
                'status' => $trip->status,
                'reference' => $trip->reference,
                'route' => [
                    'total_distance_m' => $trip->total_distance_m,
                    'total_duration_s' => $trip->total_duration_s,
                    'geometry' => $trip->route_geometry,
                ],
                'stops' => $trip->stops->map(fn ($s) => [
                    'id' => $s->id,
                    'sequence' => $s->sequence,
                    'eta' => $s->eta,
                    'status' => $s->status,
                    'destination' => [
                        'name' => $s->destination->name,
                        'latitude' => $s->destination->latitude,
                        'longitude' => $s->destination->longitude,
                    ],
                ])->values(),
            ] : null,
        ]);
    }

    /**
     * Assign a fleet vehicle to a team (and propagate to its current trip).
     */
    public function assignVehicle(Request $request, Team $team)
    {
        $validated = $request->validate([
            'vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
        ]);

        $team->update(['vehicle_id' => $validated['vehicle_id']]);
        $team->trips()->latest()->first()?->update(['vehicle_id' => $validated['vehicle_id']]);

        return response()->json(['ok' => true]);
    }
}
