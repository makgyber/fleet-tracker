<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * HTTP client for the tbss daily field schedule API.
 */
class TbssClient
{
    public function isConfigured(): bool
    {
        return ! empty(config('services.tbss.url')) && ! empty(config('services.tbss.token'));
    }

    /**
     * Fetch the schedule (teams + destinations) for a given date.
     *
     * @param  string|null  $date  Y-m-d; defaults to tbss "today" when null.
     * @return array{date: string, schedule_id: int|null, teams: array}
     *
     * @throws \RuntimeException on misconfiguration or a failed request.
     */
    public function schedule(?string $date = null): array
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('tbss integration is not configured (set TBSS_API_URL and TBSS_API_TOKEN).');
        }

        $base = rtrim(config('services.tbss.url'), '/');

        $request = Http::acceptJson()
            ->withToken(config('services.tbss.token'))
            ->timeout(30);

        // Local TBSS hosts (e.g. https://tbss.test) use self-signed certs that
        // the system trust store can't verify. Allow opting out of TLS
        // verification via config for development only; defaults to on.
        if (! config('services.tbss.verify_ssl', true)) {
            $request = $request->withoutVerifying();
        }

        $response = $request->get("{$base}/fleet/schedule", array_filter(['date' => $date]));

        if (! $response->successful()) {
            throw new \RuntimeException("tbss request failed: HTTP {$response->status()} {$response->body()}");
        }

        $data = $response->json();

        return [
            'date' => $data['date'] ?? $date,
            'schedule_id' => $data['schedule_id'] ?? null,
            'teams' => $data['teams'] ?? [],
        ];
    }
}
