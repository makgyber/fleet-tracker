<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTripRequest;
use App\Http\Resources\TripResource;
use App\Models\Trip;
use App\Models\TripStop;
use App\Services\RouteOptimizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TripController extends Controller
{
    public function index()
    {
        return TripResource::collection(
            Trip::with(['vehicle.driver', 'driver', 'stops.destination'])
                ->latest()
                ->paginate(50)
        );
    }

    public function store(StoreTripRequest $request)
    {
        $data = $request->validated();
        $destinationIds = $data['destination_ids'] ?? [];
        unset($data['destination_ids']);

        $trip = DB::transaction(function () use ($data, $destinationIds) {
            $trip = Trip::create($data);

            foreach (array_values($destinationIds) as $i => $destinationId) {
                TripStop::create([
                    'trip_id' => $trip->id,
                    'destination_id' => $destinationId,
                    'requested_order' => $i + 1,
                    'status' => 'pending',
                ]);
            }

            return $trip;
        });

        return (new TripResource(
            $trip->fresh(['vehicle.driver', 'driver', 'stops.destination'])
        ))->response()->setStatusCode(201);
    }

    public function show(Trip $trip)
    {
        return new TripResource(
            $trip->load(['vehicle.driver', 'driver', 'stops.destination'])
        );
    }

    public function update(StoreTripRequest $request, Trip $trip)
    {
        $data = $request->validated();

        // Allow replacing the stop list on update.
        $destinationIds = $data['destination_ids'] ?? null;
        unset($data['destination_ids']);

        DB::transaction(function () use ($trip, $data, $destinationIds) {
            $trip->update($data);

            if (is_array($destinationIds)) {
                $trip->stops()->delete();
                foreach (array_values($destinationIds) as $i => $destinationId) {
                    TripStop::create([
                        'trip_id' => $trip->id,
                        'destination_id' => $destinationId,
                        'requested_order' => $i + 1,
                        'status' => 'pending',
                    ]);
                }
                // Route is now stale; mark back to planned until re-optimized.
                if (in_array($trip->status, ['optimized'], true)) {
                    $trip->update(['status' => 'planned']);
                }
            }
        });

        return new TripResource(
            $trip->fresh(['vehicle.driver', 'driver', 'stops.destination'])
        );
    }

    public function destroy(Trip $trip)
    {
        $trip->delete();

        return response()->noContent();
    }

    /**
     * Compute an efficient visiting order + per-stop ETAs for the trip's stops,
     * persist the results, and cache the route summary on the trip.
     */
    public function optimize(Trip $trip, RouteOptimizer $optimizer, \App\Services\FirebaseService $firebase)
    {
        $trip->load('stops.destination');

        if ($trip->stops->isEmpty()) {
            return response()->json(['message' => 'Trip has no stops to optimize.'], 422);
        }

        // Origin: explicit trip origin if set, otherwise anchor on the first
        // requested stop so optimization still has a starting point.
        $first = $trip->stops->first()->destination;
        $origin = [
            'lat' => $trip->origin_latitude ?? $first->latitude,
            'lng' => $trip->origin_longitude ?? $first->longitude,
        ];

        $stops = $trip->stops->map(fn (TripStop $s) => [
            'id' => $s->id,
            'lat' => $s->destination->latitude,
            'lng' => $s->destination->longitude,
        ])->all();

        $result = $optimizer->optimize($origin, $stops);

        // ETAs are computed forward from "now" using cumulative leg durations.
        $now = Carbon::now();

        DB::transaction(function () use ($trip, $result, $now) {
            foreach ($result['order'] as $leg) {
                TripStop::where('id', $leg['id'])->update([
                    'sequence' => $leg['sequence'],
                    'leg_distance_m' => $leg['leg_distance_m'],
                    'leg_duration_s' => $leg['leg_duration_s'],
                    'eta' => $now->copy()->addSeconds((int) round($leg['cumulative_duration_s'])),
                ]);
            }

            $trip->update([
                'status' => 'optimized',
                'total_distance_m' => $result['total_distance_m'],
                'total_duration_s' => $result['total_duration_s'],
                'route_geometry' => is_null($result['geometry'])
                    ? null
                    : json_encode($result['geometry']),
                'route_computed_at' => $now,
            ]);
        });

        $fresh = $trip->fresh(['vehicle.driver', 'driver', 'stops.destination']);

        // Push the optimized route + ETAs to the Realtime Database so the driver
        // app and dashboard update live (no-op when Firebase isn't configured).
        $firebase->publishTripRoute($fresh);

        return (new TripResource($fresh))
            ->additional(['meta' => [
                'provider' => $result['provider'],
                'firebase' => $firebase->isConfigured() ? 'published' : 'disabled',
            ]]);
    }
}
