<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Diagnoses why route optimization may be returning straight lines (the local
 * nearest-neighbor fallback) instead of real Mapbox road geometry.
 *
 * It checks, in order:
 *   1. Whether MAPBOX_TOKEN is loaded into config (empty => always falls back).
 *   2. Whether the token looks like a public pk. token (can't call Optimization).
 *   3. A live call to the Mapbox Optimization API with two sample coordinates,
 *      reporting HTTP status + Mapbox response code and interpreting failures.
 *
 * Read-only: makes a single tiny API call, changes nothing.
 *
 * Usage:
 *   php artisan fleet:mapbox-check
 */
class MapboxCheck extends Command
{
    protected $signature = 'fleet:mapbox-check';

    protected $description = 'Diagnose Mapbox route optimization config (why routes may show straight lines).';

    public function handle(): int
    {
        $token = (string) config('services.mapbox.token');
        $profile = config('services.mapbox.profile', 'mapbox/driving');
        $base = rtrim((string) config('services.mapbox.base_url', 'https://api.mapbox.com'), '/');

        $this->line('');
        $this->info('Mapbox route-optimization check');
        $this->line('--------------------------------');

        // 1. Token presence.
        if ($token === '') {
            $this->error('MAPBOX_TOKEN is EMPTY in the active config.');
            $this->line('  => RouteOptimizer always uses the local straight-line fallback.');
            $this->newLine();
            $this->warn('Fix: set MAPBOX_TOKEN in api/.env, then clear the config cache:');
            $this->line('     php artisan config:clear && php artisan optimize');
            $this->line('  (A cached config ignores new .env values — the most common cause.)');

            return self::FAILURE;
        }

        $masked = $this->mask($token);
        $this->line("Token loaded:   {$masked}");
        $this->line("Profile:        {$profile}");
        $this->line("Base URL:       {$base}");

        // 2. Token kind heuristic. Public tokens start with "pk." and cannot
        //    call the Optimization API; secret tokens start with "sk.".
        if (str_starts_with($token, 'pk.')) {
            $this->newLine();
            $this->warn('This looks like a PUBLIC token (pk.). The Optimization API needs a');
            $this->warn('token with the right scope — a public pk. token typically returns 403.');
            $this->warn('Use the server-side Mapbox token (api/.env MAPBOX_TOKEN), not the');
            $this->warn('dashboard public token.');
        }

        // 3. Live Optimization API call with two sample coordinates (Manila area).
        $this->newLine();
        $this->line('Calling Mapbox Optimization API with 2 sample coordinates ...');

        $coords = '120.9842,14.5995;121.0244,14.5547'; // lng,lat ; lng,lat
        $url = "{$base}/optimized-trips/v1/{$profile}/{$coords}";

        try {
            $response = Http::acceptJson()
                ->timeout(15)
                ->get($url, [
                    'access_token' => $token,
                    'source' => 'first',
                    'destination' => 'last',
                    'roundtrip' => 'false',
                    'geometries' => 'geojson',
                    'overview' => 'full',
                ]);
        } catch (\Throwable $e) {
            $this->error('Request threw an exception: ' . $e->getMessage());
            $this->line('  => Likely a network/DNS/timeout issue reaching api.mapbox.com from the server.');

            return self::FAILURE;
        }

        $status = $response->status();
        $body = $response->json() ?? [];
        $code = $body['code'] ?? null;

        $this->line("HTTP status:    {$status}");
        $this->line('Mapbox code:    ' . ($code ?? '(none)'));

        if ($response->successful() && $code === 'Ok' && ! empty($body['trips'])) {
            $this->newLine();
            $this->info('SUCCESS: Mapbox returned a real optimized route with geometry.');
            $this->line('  => Routing works. If the dashboard still shows straight lines, the');
            $this->line('     affected trips were optimized earlier with the local fallback.');
            $this->line('     Re-run optimization on them (POST /api/trips/{id}/optimize or');
            $this->line('     re-import the schedule) so Mapbox geometry gets saved.');

            return self::SUCCESS;
        }

        // Interpret the common failure modes.
        $this->newLine();
        $this->error('Mapbox did NOT return a usable route — optimization is falling back to local.');

        $message = $body['message'] ?? $response->body();
        $this->line('Response message: ' . (is_string($message) ? $message : json_encode($message)));
        $this->newLine();

        match (true) {
            $status === 401 => $this->warn('401 Unauthorized: the token is invalid or revoked. Issue a valid server token.'),
            $status === 403 => $this->warn('403 Forbidden: the token lacks Optimization scope (often a public pk. token). Use a scoped server token.'),
            $status === 422 => $this->warn('422: request rejected (check coordinates/profile). Token itself may be fine.'),
            $status === 429 => $this->warn('429 Rate limited: too many requests. Retry later or raise the plan limit.'),
            default => $this->warn("Unexpected status {$status}. See the message above."),
        };

        return self::FAILURE;
    }

    /** Mask a token for safe display (keep a short prefix + suffix). */
    private function mask(string $token): string
    {
        $len = strlen($token);
        if ($len <= 10) {
            return str_repeat('*', $len);
        }

        return substr($token, 0, 6) . str_repeat('*', max(0, $len - 10)) . substr($token, -4);
    }
}
