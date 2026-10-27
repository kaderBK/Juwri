<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Reservations;

use Juwri\Modules\Reservations\Services\Rwg\Feeds\RwgFeedBuilder;
use Juwri\Modules\Reservations\Services\Rwg\Feeds\RwgFeedContributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GOOGLE-PARTNER Lane C — C2 shared skeleton.
 *
 * Booking-server auth (HTTP Basic + kill-switch), CSRF-exemption of the rwg/v3
 * group, and the feed-builder framework/registry.
 */
class RwgBookingServerAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The outer web group redirects (APP_INSTALLED=false → installer; locale
        // normalisation) before RwgAuth would run. Disable ONLY those so RwgAuth —
        // the subject — still executes on the rwg/v3 group.
        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
            \Juwri\Modules\Auth\Http\Middleware\CheckUserIsBanned::class,
            \Juwri\Core\Http\Middleware\RememberLocale::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRoutes::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
        ]);
    }

    private function enable(string $user = 'goog', string $pass = 's3cret'): void
    {
        config([
            'services.reserve_with_google.enabled'    => true,
            'services.reserve_with_google.basic_user' => $user,
            'services.reserve_with_google.basic_pass' => $pass,
        ]);
    }

    private function basic(string $user, string $pass): array
    {
        return ['Authorization' => 'Basic ' . base64_encode("$user:$pass")];
    }

    public function test_healthcheck_dark_by_default_returns_503(): void
    {
        // No creds / disabled = dark: Google gets 503, backs off.
        config(['services.reserve_with_google.enabled' => false]);

        $this->getJson('/rwg/v3/HealthCheck')->assertStatus(503);
    }

    public function test_healthcheck_200_with_correct_basic_auth(): void
    {
        $this->enable();

        $this->getJson('/rwg/v3/HealthCheck', $this->basic('goog', 's3cret'))
            ->assertStatus(200);
    }

    public function test_healthcheck_401_with_wrong_password(): void
    {
        $this->enable();

        $this->getJson('/rwg/v3/HealthCheck', $this->basic('goog', 'wrong'))
            ->assertStatus(401);
    }

    public function test_healthcheck_401_with_wrong_user(): void
    {
        $this->enable();

        $this->getJson('/rwg/v3/HealthCheck', $this->basic('nope', 's3cret'))
            ->assertStatus(401);
    }

    public function test_healthcheck_401_with_no_auth_header(): void
    {
        $this->enable();

        $this->getJson('/rwg/v3/HealthCheck')->assertStatus(401);
    }

    /**
     * The whole point of the CSRF exemption: a Google POST carries no CSRF token,
     * so without ->withoutMiddleware(PreventRequestForgery) every call would 419. With
     * valid Basic auth the request reaches the CreateBooking method — an unresolvable
     * slot returns a 200 booking_failure (E2E contract). Anything other than 419
     * proves CSRF was skipped.
     */
    public function test_post_without_csrf_token_is_not_419(): void
    {
        $this->enable();

        $res = $this->postJson('/rwg/v3/CreateBooking', ['idempotency_token' => 'x'], $this->basic('goog', 's3cret'));

        $this->assertNotSame(419, $res->getStatusCode());
        $res->assertStatus(200)->assertJsonPath('booking_failure.cause', 'SLOT_UNAVAILABLE');
    }

    public function test_post_still_authenticated_wrong_creds_401(): void
    {
        $this->enable();

        $this->postJson('/rwg/v3/CreateBooking', [], $this->basic('goog', 'bad'))
            ->assertStatus(401);
    }

    // --- feed framework ---------------------------------------------------

    public function test_feed_builder_empty_registry_builds_nothing(): void
    {
        config(['services.reserve_with_google.feed_builders' => []]);

        $this->assertSame([], new RwgFeedBuilder()->buildAll());
    }

    public function test_feed_builder_runs_registered_contributors(): void
    {
        config(['services.reserve_with_google.feed_builders' => [RwgTestFeedContributor::class]]);

        $out = new RwgFeedBuilder()->buildAll();

        $this->assertArrayHasKey('test_feed.json', $out);
        $this->assertSame(['metadata' => 'ok', 'rows' => [1, 2, 3]], $out['test_feed.json']);
    }

    public function test_feed_builder_skips_non_contributor_config_entries(): void
    {
        // Stale/garbage entries must never fatal a run.
        config(['services.reserve_with_google.feed_builders' => ['Nonexistent\\Class', \stdClass::class, RwgTestFeedContributor::class]]);

        $out = new RwgFeedBuilder()->buildAll();

        $this->assertCount(1, $out);
        $this->assertArrayHasKey('test_feed.json', $out);
    }

    public function test_metadata_is_full_snapshot_shape(): void
    {
        $meta = RwgFeedBuilder::metadata();

        $this->assertSame('PROCESS_AS_COMPLETE', $meta['processing_instruction']);
        $this->assertSame(0, $meta['shard_number']);
        $this->assertSame(1, $meta['total_shards']);
        $this->assertArrayHasKey('generation_timestamp', $meta);
    }
}

/** Test-only feed contributor (a real named class so config class-string resolution works). */
class RwgTestFeedContributor implements RwgFeedContributor
{
    public function filename(): string
    {
        return 'test_feed.json';
    }

    public function build(): array
    {
        return ['metadata' => 'ok', 'rows' => [1, 2, 3]];
    }
}
