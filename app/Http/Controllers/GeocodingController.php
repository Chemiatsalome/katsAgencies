<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server-side proxy for the property address autocomplete. The LocationIQ
 * key is only ever read here, from config/services.php — it never reaches
 * the browser, unlike a direct client-side call to a mapping API would.
 *
 * Degrades gracefully rather than erroring: no key configured, the upstream
 * API unreachable, a timeout, or genuinely zero matches for the query all
 * come back as an empty suggestion list (optionally with a short `error`
 * the frontend can show), so the address field can always fall back to
 * plain manual typing.
 */
class GeocodingController extends Controller
{
    public function autocomplete(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 3) {
            return response()->json(['suggestions' => []]);
        }

        $apiKey = config('services.locationiq.key');

        if (! $apiKey) {
            return response()->json([
                'suggestions' => [],
                'error' => 'Location search is not set up — enter the address manually.',
            ]);
        }

        try {
            $response = Http::timeout(5)->get('https://api.locationiq.com/v1/autocomplete', [
                'key' => $apiKey,
                'q' => $query,
                'limit' => 5,
                'countrycodes' => 'ke',
                'format' => 'json',
                'normalizeaddress' => 1,
            ]);
        } catch (\Throwable $e) {
            Log::warning('LocationIQ autocomplete request failed', ['message' => $e->getMessage()]);

            return response()->json([
                'suggestions' => [],
                'error' => 'Location search is temporarily unavailable — enter the address manually.',
            ]);
        }

        // LocationIQ returns a 404 with {"error":"Unable to geocode"} for a
        // query that simply has no matches — that's an empty result, not a
        // failure, and shouldn't be logged as one.
        if ($response->status() === 404) {
            return response()->json(['suggestions' => []]);
        }

        if (! $response->successful()) {
            Log::warning('LocationIQ autocomplete request returned an error', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);

            return response()->json([
                'suggestions' => [],
                'error' => 'Location search is temporarily unavailable — enter the address manually.',
            ]);
        }

        $suggestions = collect($response->json())
            ->map(fn (array $item) => [
                'label' => $item['display_name'] ?? '',
                'lat' => isset($item['lat']) ? (float) $item['lat'] : null,
                'lon' => isset($item['lon']) ? (float) $item['lon'] : null,
            ])
            ->filter(fn (array $s) => $s['label'] !== '' && $s['lat'] !== null && $s['lon'] !== null)
            ->values();

        return response()->json(['suggestions' => $suggestions]);
    }
}
