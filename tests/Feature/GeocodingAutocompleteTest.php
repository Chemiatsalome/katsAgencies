<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers App\Http\Controllers\GeocodingController — the server-side proxy
 * the location-autocomplete field talks to, so the LocationIQ key is never
 * present in the browser. Each scenario mirrors a requirement from the
 * Add Property location-autocomplete brief: authenticated-only, a short
 * query never hits the upstream API, no key configured degrades
 * gracefully, a genuine upstream failure degrades gracefully, and a real
 * result set is parsed into the shape the frontend expects.
 */
class GeocodingAutocompleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_autocomplete_requires_authentication(): void
    {
        $this->seed();

        $response = $this->get(route('manage.geocoding.autocomplete', ['q' => 'Nyali']));

        $response->assertRedirect(route('login'));
    }

    public function test_a_short_query_returns_no_suggestions_without_calling_the_upstream_api(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        config(['services.locationiq.key' => 'fake-key']);
        Http::fake();

        $response = $this->actingAs($landlord)->getJson(route('manage.geocoding.autocomplete', ['q' => 'Ny']));

        $response->assertOk()->assertJson(['suggestions' => []]);
        Http::assertNothingSent();
    }

    public function test_no_api_key_configured_degrades_to_an_empty_list_with_a_helpful_message(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        config(['services.locationiq.key' => null]);

        $response = $this->actingAs($landlord)->getJson(route('manage.geocoding.autocomplete', ['q' => 'Nyali, Mombasa']));

        $response->assertOk();
        $response->assertJsonPath('suggestions', []);
        $this->assertNotEmpty($response->json('error'));
    }

    public function test_a_successful_upstream_response_is_parsed_into_label_lat_lon(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        config(['services.locationiq.key' => 'fake-key']);

        Http::fake([
            'api.locationiq.com/*' => Http::response([
                ['display_name' => 'Nyali, Mombasa, Kenya', 'lat' => '-4.0088', 'lon' => '39.7202'],
                ['display_name' => 'Nyali Beach, Mombasa, Kenya', 'lat' => '-4.0010', 'lon' => '39.7300'],
            ], 200),
        ]);

        $response = $this->actingAs($landlord)->getJson(route('manage.geocoding.autocomplete', ['q' => 'Nyali, Mombasa']));

        $response->assertOk();
        $response->assertJsonCount(2, 'suggestions');
        $response->assertJsonPath('suggestions.0.label', 'Nyali, Mombasa, Kenya');
        $response->assertJsonPath('suggestions.0.lat', -4.0088);
        $response->assertJsonPath('suggestions.0.lon', 39.7202);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.locationiq.com')
                && $request['key'] === 'fake-key'
                && $request['countrycodes'] === 'ke';
        });
    }

    public function test_an_upstream_404_for_no_matches_is_treated_as_an_empty_result_not_a_failure(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        config(['services.locationiq.key' => 'fake-key']);

        Http::fake([
            'api.locationiq.com/*' => Http::response(['error' => 'Unable to geocode'], 404),
        ]);

        $response = $this->actingAs($landlord)->getJson(route('manage.geocoding.autocomplete', ['q' => 'Xyzzyqqq Nowhere']));

        $response->assertOk();
        $response->assertJson(['suggestions' => []]);
        $this->assertNull($response->json('error'));
    }

    public function test_an_upstream_server_error_degrades_gracefully(): void
    {
        $this->seed();
        $landlord = User::role('landlord')->firstOrFail();
        config(['services.locationiq.key' => 'fake-key']);

        Http::fake([
            'api.locationiq.com/*' => Http::response('Internal Server Error', 500),
        ]);

        $response = $this->actingAs($landlord)->getJson(route('manage.geocoding.autocomplete', ['q' => 'Nyali, Mombasa']));

        $response->assertOk();
        $response->assertJsonPath('suggestions', []);
        $this->assertNotEmpty($response->json('error'));
    }
}
