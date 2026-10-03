<?php

namespace App\Services;

use App\Models\Destination;
use App\Models\Team;
use App\Models\Trip;
use App\Models\TripStop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Imports the tbss daily field schedule into the fleet-tracker: upserts teams,
 * materializes their destinations, builds one trip per team, and optimizes it
 * (computing an efficient stop order + ETAs and publishing to Firebase).
 */
class ScheduleImporter
{
    /**
     * Team codes that represent non-field states (unavailable crews, office/
     * warehouse duty) rather than real routes. These are skipped entirely on
     * import: no team record, no trip. Matched case-insensitively after
     * trimming/collapsing whitespace.
     */
    private const EXCLUDED_TEAM_CODES = [
        'BODEGERO/OFFICE',
        'ABSENT',
        'LEAVE',
        'DAY OFF/WALANG PASOK',
    ];

    /**
     * tbss emits this sentinel coordinate for addresses it could not geocode,
     * so many unrelated stops collapse onto the same point. When we see it (or a
     * missing/zero coordinate) we re-geocode the address via Mapbox instead of
     * trusting the feed. Compared with a small epsilon to absorb rounding.
     */
    private const TBSS_FALLBACK_LAT = 14.444546;
    private const TBSS_FALLBACK_LNG = 120.9938736;
    private const COORD_EPSILON = 0.0005; // ~55m

    public function __construct(
        private readonly TbssClient $tbss,
        private readonly RouteOptimizer $optimizer,
        private readonly FirebaseService $firebase,
    ) {
    }

    /**
     * Whether a team should be excluded from import based on its tbss code.
     */
    private function isExcludedTeam(array $teamData): bool
    {
        $code = strtoupper(trim(preg_replace('/\s+/', ' ', (string) ($teamData['code'] ?? ''))));

        return in_array($code, self::EXCLUDED_TEAM_CODES, true);
    }

    /**
     * Import a given date (defaults to tbss "today").
     *
     * @return array{date: string, teams_imported: int, trips_optimized: int, skipped_no_destinations: int, skipped_excluded: int}
     */
    public function import(?string $date = null): array
    {
        $payload = $this->tbss->schedule($date);
        $resolvedDate = $payload['date'];
        $scheduleId = $payload['schedule_id'];

        $teamsImported = 0;
        $tripsOptimized = 0;
        $skipped = 0;
        $skippedExcluded = 0;

        foreach ($payload['teams'] as $teamData) {
            // Skip non-field teams (absent/leave/day off/office) outright.
            if ($this->isExcludedTeam($teamData)) {
                $skippedExcluded++;
                continue;
            }

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
            'skipped_excluded' => $skippedExcluded,
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
     * Find or create a destination for a tbss stop.
     *
     * De-duplicates on the stop's tbss source identity (source_type +
     * source_id), NOT on coordinates. Keying on coordinates silently merged
     * distinct addresses that shared a point (e.g. the tbss geocode-failure
     * fallback), which dropped stops on import. Keying on source identity keeps
     * every distinct stop and lets re-imports refresh name/address/coordinates.
     *
     * Stops without a source identity fall back to coordinate de-duplication.
     */
    private function upsertDestination(array $d): Destination
    {
        [$lat, $lng] = $this->resolveCoordinates($d);

        $sourceType = $d['source_type'] ?? null;
        $sourceId = $d['source_id'] ?? null;

        $attributes = [
            'name' => $d['name'] ?? ($d['code'] ?? 'Stop'),
            'client_name' => $this->resolveClientName($d),
            'address' => $d['name'] ?? null,
            'latitude' => $lat,
            'longitude' => $lng,
            'notes' => $sourceType !== null ? "tbss {$sourceType} #{$sourceId}" : null,
        ];

        // Preferred path: identify the destination by its tbss source. Refresh
        // mutable fields each import so corrected names/coordinates propagate.
        if ($sourceType !== null && $sourceId !== null) {
            return Destination::updateOrCreate(
                ['source_type' => $sourceType, 'source_id' => (int) $sourceId],
                $attributes,
            );
        }

        // Fallback for ad-hoc stops with no source identity: dedup by coordinate.
        return Destination::firstOrCreate(
            ['latitude' => $lat, 'longitude' => $lng],
            $attributes,
        );
    }

    /**
     * Extract the client/customer name for a tbss stop. The feed is not fully
     * standardized, so accept the common key variants and return the first
     * non-empty one, trimmed. Returns null when none is present.
     */
    private function resolveClientName(array $d): ?string
    {
        foreach (['client_name', 'client', 'customer_name', 'customer', 'account_name', 'account'] as $key) {
            $value = $d[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * Resolve a usable [lat, lng] for a tbss stop. Trusts the feed's coordinate
     * unless it is missing, zero, or the known tbss fallback sentinel, in which
     * case we geocode the address via Mapbox. Falls back to the original value
     * if geocoding is unavailable or fails, so import never breaks.
     *
     * @return array{0: float, 1: float}
     */
    private function resolveCoordinates(array $d): array
    {
        $lat = round((float) ($d['latitude'] ?? 0), 7);
        $lng = round((float) ($d['longitude'] ?? 0), 7);

        if (! $this->needsGeocoding($lat, $lng)) {
            return [$lat, $lng];
        }

        $address = $d['name'] ?? $d['code'] ?? null;
        if ($address) {
            $geocoded = $this->geocode($address);
            if ($geocoded !== null) {
                Log::info('Re-geocoded tbss stop with fallback/missing coordinate', [
                    'address' => $address,
                    'from' => [$lat, $lng],
                    'to' => $geocoded,
                ]);

                return [round($geocoded[0], 7), round($geocoded[1], 7)];
            }

            Log::warning('tbss stop has bad coordinate and geocoding failed; keeping original', [
                'address' => $address,
                'coord' => [$lat, $lng],
            ]);
        }

        return [$lat, $lng];
    }

    /** Whether a coordinate is missing, zero, or the tbss fallback sentinel. */
    private function needsGeocoding(float $lat, float $lng): bool
    {
        if (abs($lat) < 1e-6 && abs($lng) < 1e-6) {
            return true;
        }

        return abs($lat - self::TBSS_FALLBACK_LAT) < self::COORD_EPSILON
            && abs($lng - self::TBSS_FALLBACK_LNG) < self::COORD_EPSILON;
    }

    /**
     * Forward-geocode an address with Mapbox, biased to the Philippines.
     * Returns [lat, lng] of the best match, or null when unavailable/no match.
     *
     * @return array{0: float, 1: float}|null
     */
    private function geocode(string $address): ?array
    {
        $token = config('services.mapbox.token');
        if (empty($token)) {
            return null;
        }

        $base = rtrim(config('services.mapbox.base_url', 'https://api.mapbox.com'), '/');
        $url = "{$base}/geocoding/v5/mapbox.places/" . rawurlencode($address) . '.json';

        try {
            $response = Http::acceptJson()
                ->timeout(15)
                ->get($url, [
                    'access_token' => $token,
                    'country' => 'ph',
                    'limit' => 1,
                ]);

            if (! $response->successful()) {
                return null;
            }

            $center = $response->json('features.0.center');
            if (! is_array($center) || count($center) < 2) {
                return null;
            }

            // Mapbox returns [lng, lat]; normalize to [lat, lng].
            return [(float) $center[1], (float) $center[0]];
        } catch (\Throwable $e) {
            Log::warning('Mapbox geocoding failed', ['address' => $address, 'error' => $e->getMessage()]);

            return null;
        }
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
