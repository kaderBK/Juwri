<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Juwri\Core\Models\Language;
use Juwri\Core\Services\Maps\GoogleGeocodeProvider;
use Juwri\Core\Services\Maps\MapsManager;
use Juwri\Core\Services\Maps\OsmGeocodeProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Juwri\Core\Settings\PlatformSettings;
use Tests\TestCase;

/**
 * theme-pack-blade F5: maps/geocode abstraction. All external HTTP is faked —
 * no live Photon/Nominatim/Google calls.
 */
class MapsGeocodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Cache::flush();
    }

    private function manager(): MapsManager
    {
        return app(MapsManager::class);
    }

    public function test_osm_is_the_default_provider(): void
    {
        // No setting stored → osm.
        $this->assertInstanceOf(OsmGeocodeProvider::class, $this->manager()->provider());
        $this->assertEquals('osm', $this->manager()->provider()->key());
    }

    public function test_google_selected_only_when_key_present(): void
    {
        PlatformSettings::set('maps_provider', 'google');

        // Selected google but NO key → inert, falls back to osm.
        $this->assertInstanceOf(OsmGeocodeProvider::class, app(MapsManager::class)->provider());

        // With a key → google resolves.
        PlatformSettings::set('maps_google_api_key', 'test-key-123');
        $fresh = new MapsManager();
        $this->assertInstanceOf(GoogleGeocodeProvider::class, $fresh->provider());
        $this->assertTrue($fresh->provider()->isConfigured());
    }

    public function test_google_provider_is_inert_without_key(): void
    {
        $g = new GoogleGeocodeProvider(null);
        $this->assertFalse($g->isConfigured());
        $this->expectException(\RuntimeException::class);
        $g->reverse(24.7, 46.7);
    }

    public function test_osm_autocomplete_returns_mapped_shape_from_photon(): void
    {
        Http::fake([
            'photon.komoot.io/*' => Http::response([
                'features' => [[
                    'geometry'   => ['coordinates' => [46.6753, 24.7136]],
                    'properties' => ['name' => 'Riyadh', 'city' => 'Riyadh', 'country' => 'Saudi Arabia'],
                ]],
            ], 200),
        ]);

        $results = $this->manager()->provider()->autocomplete('Riy');

        $this->assertCount(1, $results);
        $this->assertEquals(24.7136, $results[0]['lat']);
        $this->assertEquals(46.6753, $results[0]['lng']);
        $this->assertStringContainsString('Riyadh', $results[0]['label']);
    }

    public function test_osm_reverse_returns_mapped_shape_from_nominatim(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/reverse*' => Http::response([
                'display_name' => 'King Fahd Rd, Riyadh, Saudi Arabia',
                'lat'          => '24.7136',
                'lon'          => '46.6753',
                'address'      => ['road' => 'King Fahd Rd', 'city' => 'Riyadh'],
            ], 200),
        ]);

        $hit = $this->manager()->provider()->reverse(24.7136, 46.6753);

        $this->assertNotNull($hit);
        $this->assertEquals('King Fahd Rd, Riyadh, Saudi Arabia', $hit['label']);
        $this->assertEquals(24.7136, $hit['lat']);
    }

    public function test_osm_forward_returns_coords(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/search*' => Http::response([[
                'lat' => '24.5', 'lon' => '46.5', 'display_name' => 'Somewhere, Riyadh',
            ]], 200),
        ]);

        $hit = $this->manager()->geocodeAddress('Somewhere, Riyadh');
        $this->assertEquals(['lat' => 24.5, 'lng' => 46.5], $hit);
    }

    public function test_results_are_cached_second_call_hits_no_http(): void
    {
        Http::fake([
            'photon.komoot.io/*' => Http::response([
                'features' => [[
                    'geometry'   => ['coordinates' => [46.6, 24.7]],
                    'properties' => ['name' => 'Cached'],
                ]],
            ], 200),
        ]);

        $p = $this->manager()->provider();
        $p->autocomplete('cache-me');
        $p->autocomplete('cache-me'); // served from cache

        Http::assertSentCount(1);
    }

    public function test_sends_descriptive_user_agent(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            'display_name' => 'X', 'lat' => '1', 'lon' => '1', 'address' => [],
        ], 200)]);

        $this->manager()->provider()->reverse(1, 1);

        Http::assertSent(function ($request) {
            $ua = $request->header('User-Agent')[0] ?? '';
            return $ua !== '' && str_contains($ua, '+');
        });
    }

    public function test_throttle_spaces_out_live_requests(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            'display_name' => 'X', 'lat' => '1', 'lon' => '1', 'address' => [],
        ], 200)]);

        $p = $this->manager()->provider();
        $start = microtime(true);
        $p->reverse(10, 10); // first (records timestamp)
        $p->reverse(20, 20); // second must wait ~1s
        $elapsed = microtime(true) - $start;

        $this->assertGreaterThanOrEqual(0.9, $elapsed);
    }

    public function test_autocomplete_endpoint_proxies_provider(): void
    {
        Http::fake([
            'photon.komoot.io/*' => Http::response([
                'features' => [[
                    'geometry'   => ['coordinates' => [46.6, 24.7]],
                    'properties' => ['name' => 'Proxy Place'],
                ]],
            ], 200),
        ]);

        $res = $this->getJson('/geo/autocomplete?q=proxy');
        $res->assertOk()
            ->assertJsonPath('provider', 'osm')
            ->assertJsonPath('results.0.label', 'Proxy Place');
        $this->assertNotNull($res->json('attribution'));
    }

    public function test_reverse_endpoint_validates_and_proxies(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            'display_name' => 'Reverse St', 'lat' => '1', 'lon' => '2', 'address' => [],
        ], 200)]);

        $this->getJson('/geo/reverse?lat=1&lng=2')
            ->assertOk()
            ->assertJsonPath('result.label', 'Reverse St');

        $this->getJson('/geo/reverse?lat=999&lng=2')->assertStatus(422);
    }
}
