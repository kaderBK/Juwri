<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\GoogleOrdering;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Modules\Storefront\Services\GoogleOrdering\Sftp\GoogleOrderingSftpTransport;
use Juwri\Modules\Storefront\Services\GoogleOrdering\GoogleOrderingFeedBuilder;
use Juwri\Modules\Storefront\Services\GoogleOrdering\GoogleOrderingFeedValidator;
use Tests\TestCase;

/**
 * P5-20 Google Ordering Redirect — the offline end-to-end sandbox.
 *
 * Seed a demo store, switch Google Ordering on, run the command, read the artifacts back: every action
 * link in the Service feed passes the offline validator and follows (test HTTP client) to the store home
 * with the right `order_type`; the Menu feed item count equals the storefront catalogue count. Fakes only.
 */
class GoogleOrderingSandboxTest extends TestCase
{
    use RefreshDatabase;
    use GoogleOrderingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, EnsureStaffDashboardPermission::class]);
        config([
            'settings.include_language_code' => false,
            'services.google_ordering.enabled' => true,
            'services.google_ordering.driver' => 'fake',
        ]);
        $this->makeFeedDir();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->goDir);
        parent::tearDown();
    }

    public function test_the_sandbox_round_trip(): void
    {
        $fake = new FakeGoogleOrderingTransport;
        $this->app->instance(GoogleOrderingSftpTransport::class, $fake);
        Http::fake();

        $store = $this->foodStore('sandbox-'.Str::lower(Str::random(6)), 'Sandbox');
        $this->googleOn($store);
        $this->geocodedBranch($store);

        $this->artisan('google:ordering-feeds')->assertExitCode(0);

        $merchant = $this->readFeed('merchant_feed.json');
        $service = $this->readFeed('service_feed.json');
        $menu = $this->readFeed('menu_feed.json');

        // The eligible merchant is published.
        $this->assertContains('store-'.$store->id, array_column($this->entriesOf($merchant), 'merchant_id'));

        // The menu feed carries the whole storefront catalogue, no more, no less.
        $this->assertCount($this->catalogueItems($store)->count(), $this->menuItemsOf($menu));

        // Every service feed passes the offline rules (merchants first, then services).
        $validator = new GoogleOrderingFeedValidator;
        $validator->sanitize('merchant_feed.json', $merchant);
        $clean = $validator->sanitize('service_feed.json', $service);
        $this->assertSame([], $clean['problems'], 'the built service feed passes the validator');

        // Every action link follows to the store home with its order_type (and the UTM params survive).
        $links = $this->actionLinks($service);
        $this->assertNotSame([], $links);
        foreach ($links as $link) {
            $this->assertStringStartsWith('https://', $link);
            preg_match('#/order/([a-z_]+)#', $link, $m);
            $kit = $m[1];

            $res = $this->get($link);
            $res->assertStatus(302);

            $location = (string) $res->headers->get('Location');
            $this->assertStringContainsString($store->slug, $location, 'redirects into the storefront');
            $query = [];
            parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
            $this->assertSame($kit, $query['order_type'] ?? null, "order_type survives for {$kit}");
            $this->assertSame('google', $query['utm_source'] ?? null);
        }

        $this->assertSame(0, $fake->puts, 'no upload anywhere in the sandbox');
        Http::assertNothingSent();
    }
}
