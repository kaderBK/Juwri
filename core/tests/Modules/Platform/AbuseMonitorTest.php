<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Models\Theme;
use Juwri\Core\Services\AbuseMonitor;
use App\Services\PlatformMetrics;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * P5-5: abuse monitoring — cap-breach spikes flag (and optionally auto-delist)
 * a theme; CSP violations count per blocked host as the phone-home signal.
 */
class AbuseMonitorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([\App\Http\Middleware\PreventRequestForgery::class]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/marketplace-themes/abusetest'));
        parent::tearDown();
    }

    private function makePublishedTheme(): Theme
    {
        File::ensureDirectoryExists(storage_path('app/marketplace-themes/abusetest/1.0.0/templates'));
        File::put(storage_path('app/marketplace-themes/abusetest/1.0.0/templates/index.liquid'), '<h1>x</h1>');

        $theme = Theme::create(['name' => 'Abuse Test', 'slug' => 'abusetest', 'status' => 'published', 'price' => 0]);
        $theme->versions()->create(['semver' => '1.0.0', 'manifest' => [], 'checksum' => str_repeat('f', 64), 'status' => 'published']);

        return $theme;
    }

    public function test_breaching_theme_is_flagged_but_not_delisted_by_default(): void
    {
        $this->makePublishedTheme();
        for ($i = 0; $i < 30; $i++) {
            PlatformMetrics::record('render.fail', 'abusetest');
        }

        $result = AbuseMonitor::scan();

        $this->assertCount(1, $result['flagged']);
        $this->assertSame('abusetest', $result['flagged'][0]['slug']);
        $this->assertFalse($result['flagged'][0]['delisted'], 'auto-delist is OFF by default');
        $this->assertNotNull(MarketplaceThemeResolver::resolve('mp:abusetest@1.0.0'), 'still serving');
    }

    public function test_auto_delist_stops_the_resolver_when_enabled(): void
    {
        config(['platform.alerts.auto_delist_breaching_themes' => true]);
        $theme = $this->makePublishedTheme();
        for ($i = 0; $i < 30; $i++) {
            PlatformMetrics::record('render.fail', 'abusetest');
        }

        $result = AbuseMonitor::scan();

        $this->assertTrue($result['flagged'][0]['delisted']);
        $this->assertSame('delisted', $theme->fresh()->status);
        $this->assertNull(MarketplaceThemeResolver::resolve('mp:abusetest@1.0.0'), 'resolver refuses the delisted version — diners fall back to Blade');
    }

    public function test_below_threshold_is_quiet(): void
    {
        $this->makePublishedTheme();
        for ($i = 0; $i < 5; $i++) {
            PlatformMetrics::record('render.fail', 'abusetest');
        }

        $result = AbuseMonitor::scan();

        $this->assertSame([], $result['flagged']);
    }

    public function test_csp_report_feeds_the_phone_home_metric(): void
    {
        $this->postJson('csp-report', [
            'csp-report' => ['blocked-uri' => 'https://evil-tracker.example/collect', 'violated-directive' => 'connect-src'],
        ])->assertNoContent();

        $this->assertSame(1, PlatformMetrics::todayCount('csp.violation'));
        $this->assertSame(1, (int) \DB::table('platform_metrics')
            ->where('metric', 'csp.violation')->where('label', 'evil-tracker.example')->value('count'));

        // Spike over threshold → phone-home alert.
        config(['platform.alerts.max_csp_violations_per_host' => 3]);
        for ($i = 0; $i < 4; $i++) {
            PlatformMetrics::record('csp.violation', 'evil-tracker.example');
        }
        $result = AbuseMonitor::scan();
        $this->assertSame('evil-tracker.example', $result['phone_home'][0]['host']);
    }
}
