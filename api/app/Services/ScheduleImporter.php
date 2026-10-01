<?php

namespace App\Services;

use App\Models\Destination;
use App\Models\Team;
use App\Models\Trip;
use App\Models\TripStop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Imports the tbss daily field schedule into the fleet-tracker: upserts teams,
 * materializes their destinations, builds one trip per team, and optimizes it
 * (computing an efficient stop order + ETAs and publishing to Firebase).
 */
class ScheduleImporter
{
    public function __construct(
        private readonly TbssClient $tbss,
        private readonly RouteOptimizer $optimizer,
        private readonly FirebaseService $firebase,
    ) {
    }

    /**
     * Import a given date (defaults to tbss "today").
     *
     * @return array{date: string, teams_imported: int, trips_optimized: int, skipped_no_destinations: int}
     */
    public function import(?string $date = null): array
    {
        $payload = $this->tbss->schedule($date);
        $resolvedDate = $payload['date'];
        $scheduleId = $payload['schedule_id'];

        $teamsImported = 0;
        $tripsOptimized = 0;
        $skipped = 0;

        foreach ($payload['teams'] as $teamData) {
            $destinations = $teamData['destinations'] ?? [];

            // A team with no mappable destinations gets a team record but no trip.
            if (count($destinations) === 0) {
                $this->upsertTeam($teamData, $resolvedDate, $scheduleId);
                $teamsImported++;
                $skipped++;
                continue;
            }

            $team = $this->upsertTeam($teamData, $resolvedDate, $scheduleId);
            $teamsImported++;

            $trip = $this->rebuildTrip($team, $destinations);

            // Optimize the trip (road route + ETAs), then publish to Firebase.
            $this->optimizeTrip($trip);
            $this->firebase->publishTripRoute($trip->fresh(['stops.destination']));
            $tripsOptimized++;
        }

        return [
            'date' => $resolvedDate,
            'teams_imported' => $teamsImported,
            'trips_optimized' => $tripsOptimized,
            'skipped_no_destinations' => $skipped,
        ];
    }

    /**
     * Create or update the team record keyed by its stable tbss UUID.
     */
    private function upsertTeam(array $teamData, string $date, ?int $scheduleId): Team
    {
        return Team::updateOrCreate(
            ['team_uuid' => $teamData['uuid']],
            [
                'code' => $teamData['code'] ?? 'TEAM',
                'schedule_date' => $date,
                'tbss_schedule_id' => $scheduleId,
                'color' => $teamData['color'] ?? null,
                'vehicle_hint' => $teamData['vehicle'] ?? null,
                'members' => $teamData['members'] ?? [],
                'imported_at' => Carbon::now(),
            ],
        );
    }

    /**
     * Replace the team's trip and stops with the current destination set.
     * Idempotent: re-importing refreshes stops rather than duplicating trips.
     */
    private function rebuildTrip(Team $team, array $destinations): Trip
    {
        return DB::transaction(function () use ($team, $destinations) {
            // Reuse an existing trip for this team (keeps assigned vehicle/driver),
            // otherwise create a fresh one.
            $trip = $team->trips()->latest()->first()
                ?? Trip::create([
                    'team_id' => $team->id,
                    'reference' => $team->code,
                    'status' => 'planned',
                ]);

            // Anchor the route at the first destination (teams have no depot).
            $first = $destinations[0];
            $trip->update([
                'reference' => $team->code,
                'origin_latitude' => $first['latitude'],
                'origin_longitude' => $first['longitude'],
                'status' => 'planned',
            ]);

            // Rebuild stops from scratch.
            $trip->stops()->delete();
            foreach (array_values($destinations) as $i => $d) {
                $destination = $this->upsertDestination($d);
                TripStop::create([
                    'trip_id' => $trip->id,
                    'destination_id' => $destination->id,
                    'requested_order' => $i + 1,
                    'status' => 'pending',
                ]);
            }

            return $trip;
        });
    }

    /**
     * Find or create a destination for a tbss stop. De-duplicates on
     * coordinates so repeated imports reuse the same destination row.
     */
    private function upsertDestination(array $d): Destination
    {
        $lat = round((float) $d['latitude'], 7);
        $lng = round((float) $d['longitude'], 7);

        return Destination::firstOrCreate(
            ['latitude' => $lat, 'longitude' => $lng],
            [
                'name' => $d['name'] ?? ($d['code'] ?? 'Stop'),
                'address' => $d['name'] ?? null,
                'notes' => isset($d['source_type']) ? "tbss {$d['source_type']} #{$d['source_id']}" : null,
            ],
        );
    }

    /**
     * Run optimization and persist sequence/ETA/geometry on the trip + stops.
     * Mirrors TripController@optimize so imported trips behave identically.
     */
    private function optimizeTrip(Trip $trip): void
    {
        $trip->load('stops.destination');

        $origin = [
            'lat' => $trip->origin_latitude ?? $trip->stops->first()->destination->latitude,
            'lng' => $trip->origin_longitude ?? $trip->stops->first()->destination->longitude,
        ];
        $stops = $trip->stops->map(fn (TripStop $s) => [
            'id' => $s->id,
            'lat' => $s->destination->latitude,
            'lng' => $s->destination->longitude,
        ])->all();

        $result = $this->optimizer->optimize($origin, $stops);
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
                'route_geometry' => is_null($result['geometry']) ? null : json_encode($result['geometry']),
                'route_computed_at' => $now,
            ]);
        });
    }
}
