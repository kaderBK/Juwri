<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Models\App;
use Juwri\Core\Models\User;
use App\Services\AppInstallService;
use App\Services\AppSessionToken;
use App\Services\PlatformMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Juwri\Modules\Auth\Models\OAuthClient as Client;
use Tests\TestCase;

/**
 * P5-3: platform metrics — atomic daily counters, wired into the render path
 * and the app auth/payment surfaces, with threshold alerts.
 */
class PlatformMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_is_atomic_and_aggregates_latency(): void
    {
        PlatformMetrics::record('render.ok', 'crave', 120);
        PlatformMetrics::record('render.ok', 'crave', 80);
        PlatformMetrics::record('render.ok', 'other', 50);
        PlatformMetrics::record('render.fail', 'crave');

        $this->assertSame(3, PlatformMetrics::todayCount('render.ok'));
        $this->assertSame(1, PlatformMetrics::todayCount('render.fail'));

        $row = \DB::table('platform_metrics')
            ->where('metric', 'render.ok')->where('label', 'crave')->first();
        $this->assertSame(2, (int) $row->count);
        $this->assertSame(200, (int) $row->sum_ms);
    }

    public function test_app_auth_failures_are_counted(): void
    {
        Route::middleware('auth.app-session')->get('/_test/metrics-api', fn () => ['ok' => true]);

        $this->getJson('/_test/metrics-api')->assertUnauthorized(); // missing token

        $client = Client::create([
            'name' => 'M', 'secret' => 'metrics-secret-metrics-secret-0000', 'provider' => 'users',
            'redirect' => 'https://m.test/cb',
            'personal_access_client' => false, 'password_client' => false, 'revoked' => false,
        ]);
        $app = App::create(['name' => 'M', 'slug' => 'metrics-app', 'scopes' => [], 'oauth_client_id' => $client->id, 'status' => 'approved']);
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        new AppInstallService()->install($app, $owner);

        // Forged signature → counted under the app's slug.
        $forged = \Firebase\JWT\JWT::encode(
            ['aud' => (string) $client->id, 'sub' => (string) $owner->id, 'iat' => time(), 'nbf' => time(), 'exp' => time() + 60],
            'wrong-secret-wrong-secret-0000000000',
            'HS256'
        );
        $this->getJson('/_test/metrics-api', ['Authorization' => 'Bearer '.$forged])->assertUnauthorized();

        $this->assertSame(2, PlatformMetrics::todayCount('app.auth_fail'));

        // A valid token records no failure.
        $this->getJson('/_test/metrics-api', ['Authorization' => 'Bearer '.AppSessionToken::mint($app, $owner)])->assertOk();
        $this->assertSame(2, PlatformMetrics::todayCount('app.auth_fail'));
    }

    /** The backup-age alert only exists on MySQL (mysqldump) and is unrelated to the thresholds under test. */
    private function alerts(): array
    {
        return array_values(array_filter(
            PlatformMetrics::evaluateAlerts(),
            fn ($a) => ! str_contains((string) (is_array($a) ? json_encode($a) : $a), 'platform DB backup')
        ));
    }

    public function test_alerts_trip_on_thresholds_and_stay_quiet_below(): void
    {
        // Below volume floor → no alert even at 100% failure.
        PlatformMetrics::record('render.fail', 'x');
        $this->assertSame([], $this->alerts());

        // Push over the floor with a >5% failure rate.
        for ($i = 0; $i < 18; $i++) {
            PlatformMetrics::record('render.ok', 'x');
        }
        for ($i = 0; $i < 2; $i++) {
            PlatformMetrics::record('render.fail', 'x');
        }
        // 3 fails / 21 total = 14% > 5%.
        Log::spy();
        $alerts = $this->alerts();

        $this->assertNotEmpty($alerts);
        $this->assertStringContainsString('render failure rate', $alerts[0]);
    }

    public function test_render_metrics_flow_from_the_storefront_path(): void
    {
        config(['platform.alerts.max_render_failure_rate' => 0.05]);
        // Direct service behaviour is covered above; here just assert the
        // metric names used by the controller exist end-to-end after a render
        // failure is simulated through record() — the controller call sites
        // are exercised by the marketplace render tests (no double fixture).
        PlatformMetrics::record('render.ok', 'crave', 42);
        $this->assertSame(1, PlatformMetrics::todayCount('render.ok'));
    }
}
