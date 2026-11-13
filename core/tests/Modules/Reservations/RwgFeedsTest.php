<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Reservations;

use App\Models\Reservation;
use App\Models\StoreOption;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Reservations\Services\Rwg\Feeds\AvailabilityFeedBuilder;
use Juwri\Modules\Reservations\Services\Rwg\Feeds\MerchantsFeedBuilder;
use Juwri\Modules\Reservations\Services\Rwg\Feeds\RwgFeedBuilder;
use Juwri\Modules\Reservations\Services\Rwg\Feeds\ServicesFeedBuilder;
use Juwri\Modules\Storefront\Models\StoreDomain;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GOOGLE-PARTNER Lane C — C4 Reserve-with-Google feeds.
 *
 * Merchants / Services / Availability structure per the Actions Center schemas
 * (C0), reseller/custom-domain merchant URL, availability guards (unlimited-covers
 * skip, branch-less skip), and the rwg:feeds command (dark no-op + local artifacts).
 */
class RwgFeedsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware();
        $this->dir = sys_get_temp_dir().'/rwg-feeds-'.uniqid('', true);
        File::deleteDirectory($this->dir);
        config(['filesystems.rwg_feeds_path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St',
            'phone' => '+97412345', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function enable(Store $store): void
    {
        StoreOption::updateStoreOption($store->id, 'rwg_reservations_enabled', '1');
    }

    private function geocodedBranch(Store $store, ?array $hours = null): Branch
    {
        return Branch::create([
            'store_id' => $store->id, 'name' => 'Main', 'active' => 1, 'accepting_orders' => 1,
            'timezone' => 'UTC', 'latitude' => 25.2854, 'longitude' => 51.5310,
            'hours' => $hours,
        ]);
    }

    public function test_merchants_feed_shape(): void
    {
        $store = $this->store();
        $this->enable($store);
        $this->geocodedBranch($store);

        $feed = app(MerchantsFeedBuilder::class)->build();

        $this->assertSame('merchant_feed.json', app(MerchantsFeedBuilder::class)->filename());
        $this->assertSame('PROCESS_AS_COMPLETE', $feed['metadata']['processing_instruction']);
        $this->assertCount(1, $feed['merchant']);
        $m = $feed['merchant'][0];
        $this->assertSame('store-'.$store->id, $m['merchant_id']);
        $this->assertSame((string) $store->title, $m['name']);
        $this->assertSame('restaurant', $m['category']);
        $this->assertEqualsWithDelta(25.2854, $m['geo']['latitude'], 0.0001);
        $this->assertStringContainsString($store->slug, $m['url']);
    }

    public function test_merchant_url_uses_reseller_custom_domain(): void
    {
        $store = $this->store();
        $this->enable($store);
        $this->geocodedBranch($store);
        StoreDomain::create([
            'store_id' => $store->id, 'domain' => 'book.example-bistro.com', 'verified' => true,
            'verification_token' => Str::random(40), 'ssl_status' => 'active',
        ]);

        $feed = app(MerchantsFeedBuilder::class)->build();

        $this->assertSame('https://book.example-bistro.com', $feed['merchant'][0]['url']);
    }

    public function test_store_without_geocoded_branch_is_omitted(): void
    {
        $store = $this->store();
        $this->enable($store);
        // A branch with no coordinates does not qualify.
        Branch::create(['store_id' => $store->id, 'name' => 'NoGeo', 'active' => 1, 'accepting_orders' => 1]);

        $this->assertCount(0, app(MerchantsFeedBuilder::class)->build()['merchant']);
    }

    public function test_disabled_store_not_in_feed(): void
    {
        $store = $this->store(); // NOT enabled
        $this->geocodedBranch($store);

        $this->assertCount(0, app(MerchantsFeedBuilder::class)->build()['merchant']);
    }

    public function test_services_feed_shape(): void
    {
        $store = $this->store();
        $this->enable($store);
        $this->geocodedBranch($store);

        $feed = app(ServicesFeedBuilder::class)->build();

        $s = $feed['service'][0];
        $this->assertSame('store-'.$store->id, $s['merchant_id']);
        $this->assertSame('dining-'.$store->id, $s['service_id']);
        $this->assertSame('SERVICE_TYPE_DINING_RESERVATION', $s['type']);
        $this->assertSame('NOT_SUPPORTED', $s['prepayment_type']);
    }

    public function test_services_feed_appends_waitlist_service_when_store_opts_in(): void
    {
        // F0 wiring (D5→C): a reservations+waitlist store emits BOTH a dining
        // reservation service and a waitlist service in the one Services feed.
        $store = $this->store();
        $this->enable($store);
        StoreOption::updateStoreOption($store->id, 'rwg_waitlist_enabled', '1');
        StoreOption::updateStoreOption($store->id, 'waitlist_max_party', '9');
        $this->geocodedBranch($store);

        $feed = app(ServicesFeedBuilder::class)->build();

        $ids = array_column($feed['service'], 'service_id');
        $this->assertContains('dining-'.$store->id, $ids);
        $this->assertContains('waitlist', $ids);

        $wl = collect($feed['service'])->firstWhere('service_id', 'waitlist');
        $this->assertSame('store-'.$store->id, $wl['merchant_id']);
        $this->assertSame(9, $wl['waitlist_rules']['max_party_size']);
    }

    public function test_services_feed_omits_waitlist_when_not_opted_in(): void
    {
        $store = $this->store();
        $this->enable($store); // reservations only, waitlist OFF
        $this->geocodedBranch($store);

        $ids = array_column(app(ServicesFeedBuilder::class)->build()['service'], 'service_id');
        $this->assertContains('dining-'.$store->id, $ids);
        $this->assertNotContains('waitlist', $ids);
    }

    public function test_availability_feed_emits_slots_when_covers_finite(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00:00', 'UTC'));
        $store = $this->store();
        $this->enable($store);
        StoreOption::updateStoreOption($store->id, 'reservation_covers_per_slot', '10');
        StoreOption::updateStoreOption($store->id, 'reservation_slot_interval', '60');
        StoreOption::updateStoreOption($store->id, 'reservation_horizon_days', '1');
        $this->geocodedBranch($store, [
            'mon' => ['open' => '09:00', 'close' => '17:00', 'closed' => false],
            'tue' => ['open' => '09:00', 'close' => '17:00', 'closed' => false],
        ]);

        $feed = app(AvailabilityFeedBuilder::class)->build();
        $rows = $feed['service_availability'][0]['availability'];

        $this->assertNotEmpty($rows);
        $row = $rows[0];
        $this->assertSame('store-'.$store->id, $row['merchant_id']);
        $this->assertSame('dining-'.$store->id, $row['service_id']);
        $this->assertSame(3600, $row['duration_sec']);
        $this->assertSame(10, $row['spots_total']);
        $this->assertIsInt($row['start_sec']);
        $this->assertArrayHasKey('spots_open', $row);

        Carbon::setTestNow();
    }

    public function test_availability_reflects_booked_covers(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00:00', 'UTC'));
        $store = $this->store();
        $this->enable($store);
        StoreOption::updateStoreOption($store->id, 'reservation_covers_per_slot', '10');
        StoreOption::updateStoreOption($store->id, 'reservation_slot_interval', '60');
        StoreOption::updateStoreOption($store->id, 'reservation_horizon_days', '0');
        $this->geocodedBranch($store, ['tue' => ['open' => '09:00', 'close' => '17:00', 'closed' => false]]);
        // 4 covers booked into the 12:00 bucket.
        Reservation::create(['store_id' => $store->id, 'name' => 'G', 'party_size' => 4,
            'reserved_at' => '2026-09-01 12:00:00', 'status' => 'confirmed', 'token' => Str::random(40)]);

        $rows = app(AvailabilityFeedBuilder::class)->build()['service_availability'][0]['availability'];
        $noon = collect($rows)->firstWhere('start_sec', Carbon::parse('2026-09-01 12:00:00', 'UTC')->getTimestamp());

        $this->assertNotNull($noon);
        $this->assertSame(10, $noon['spots_total']);
        $this->assertSame(6, $noon['spots_open']); // 10 - 4

        Carbon::setTestNow();
    }

    public function test_availability_skips_unlimited_covers_store(): void
    {
        $store = $this->store();
        $this->enable($store);
        // covers default 0 = unlimited → cannot publish finite spots → skipped.
        $this->geocodedBranch($store, ['mon' => ['open' => '09:00', 'close' => '17:00', 'closed' => false]]);

        $rows = app(AvailabilityFeedBuilder::class)->build()['service_availability'][0]['availability'];
        $this->assertSame([], $rows);
    }

    public function test_availability_skips_hourless_branch_no_247(): void
    {
        $store = $this->store();
        $this->enable($store);
        StoreOption::updateStoreOption($store->id, 'reservation_covers_per_slot', '10');
        $this->geocodedBranch($store, null); // no hours → must NOT advertise 24/7

        $rows = app(AvailabilityFeedBuilder::class)->build()['service_availability'][0]['availability'];
        $this->assertSame([], $rows);
    }

    public function test_feed_builder_registry_wires_all_three(): void
    {
        $out = new RwgFeedBuilder()->buildAll();

        $this->assertArrayHasKey('merchant_feed.json', $out);
        $this->assertArrayHasKey('service_feed.json', $out);
        $this->assertArrayHasKey('availability_feed.json', $out);
    }

    public function test_command_dark_noop_writes_nothing(): void
    {
        $dir = $this->dir;
        File::deleteDirectory($dir);
        config(['services.reserve_with_google.enabled' => false]);

        $this->artisan('rwg:feeds')->assertExitCode(0);

        $this->assertFalse(File::exists($dir.'/merchant_feed.json'));
    }

    public function test_command_enabled_writes_local_artifacts_but_no_upload(): void
    {
        $dir = $this->dir;
        File::deleteDirectory($dir);
        config(['services.reserve_with_google.enabled' => true, 'services.reserve_with_google.driver' => 'fake']);

        $store = $this->store();
        $this->enable($store);
        $this->geocodedBranch($store);

        $this->artisan('rwg:feeds')->assertExitCode(0);

        $this->assertTrue(File::exists($dir.'/merchant_feed.json'));
        $this->assertTrue(File::exists($dir.'/merchant_feed.json.gz'));
        $decoded = json_decode(File::get($dir.'/merchant_feed.json'), true);
        $this->assertSame('store-'.$store->id, $decoded['merchant'][0]['merchant_id']);

        File::deleteDirectory($dir);
    }
}
