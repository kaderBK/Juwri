<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Reservations;

use App\Models\Reservation;
use App\Models\StoreOption;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\PlatformAlert;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Reservations\Services\Rwg\Feeds\RwgFeedBuilder;
use Juwri\Modules\Reservations\Services\Rwg\Feeds\RwgFeedValidator;
use Juwri\Modules\Reservations\Services\Rwg\RwgFeedDrop;
use Juwri\Modules\Reservations\Services\Rwg\Sftp\FakeSftpTransport;
use Juwri\Modules\Reservations\Services\Rwg\Sftp\RwgSftpTransport;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Reserve-with-Google feed hardening: field rules, stable order and shards, tenancy, the SFTP drop with retries
 * (fake transport), and the admin view of the last drop. All of it stays off unless RWG_ENABLED.
 */
class RwgFeedHardeningTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware();
        $this->dir = sys_get_temp_dir().'/rwg-feeds-'.uniqid('', true);
        File::deleteDirectory($this->dir);
        config(['filesystems.rwg_feeds_path' => $this->dir]);
        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function store(string $phone = '+97412345'): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => $title,
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St',
            'phone' => $phone, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function open(Store $store, float $lat = 25.2854, float $lng = 51.5310, bool $waitlist = false, bool $branch = true): Store
    {
        StoreOption::updateStoreOption($store->id, 'rwg_reservations_enabled', '1');
        StoreOption::updateStoreOption($store->id, 'reservation_covers_per_slot', '10');
        StoreOption::updateStoreOption($store->id, 'reservation_slot_interval', '60');
        StoreOption::updateStoreOption($store->id, 'reservation_horizon_days', '0');
        if ($waitlist) {
            StoreOption::updateStoreOption($store->id, 'rwg_waitlist_enabled', '1');
        }
        if ($branch) {
            Branch::create(['store_id' => $store->id, 'name' => 'Main', 'active' => 1, 'accepting_orders' => 1, 'timezone' => 'UTC',
                'latitude' => $lat, 'longitude' => $lng, 'hours' => ['tue' => ['open' => '09:00', 'close' => '17:00', 'closed' => false]]]);
        }

        return $store;
    }

    private function feeds(): array
    {
        return (new RwgFeedBuilder)->buildAll();
    }

    // ---- field rules --------------------------------------------------------------------------------------------------

    public function test_merchants_feed_rules_drop_bad_entries_and_fix_optional_fields(): void
    {
        $v = new RwgFeedValidator;
        $ok = ['merchant_id' => 'store-1', 'name' => 'Cafe', 'telephone' => '+97412345', 'url' => 'https://cafe.example', 'geo' => ['latitude' => 25.2, 'longitude' => 51.5], 'category' => 'restaurant'];
        $feed = ['metadata' => [], 'merchant' => [
            $ok,
            ['merchant_id' => 'bad id!'] + $ok,
            ['merchant_id' => 'store-1'] + $ok, // duplicate
            array_merge($ok, ['merchant_id' => 'store-2', 'name' => ' ']),
            ['merchant_id' => 'store-3', 'geo' => ['latitude' => 91, 'longitude' => 0]] + $ok,
            ['merchant_id' => 'store-4', 'geo' => ['latitude' => 0, 'longitude' => 0]] + $ok,
            ['merchant_id' => 'store-5', 'telephone' => 'call me', 'url' => 'http://insecure.example'] + $ok,
        ]];

        $out = $v->sanitize('merchant_feed.json', $feed);

        $this->assertSame(['store-1', 'store-5'], array_column($out['payload']['merchant'], 'merchant_id'));
        $five = $out['payload']['merchant'][1];
        $this->assertSame(['', ''], [$five['telephone'], $five['url']], 'a bad optional field is removed, not the merchant');
        $this->assertCount(7, $out['problems']);
        $this->assertCount(4, array_filter($out['problems'], fn ($p) => str_contains($p, 'geo') || str_contains($p, 'merchant_id') || str_contains($p, 'duplicate')));
    }

    public function test_the_google_place_id_is_sent_when_set_and_a_bad_one_is_removed(): void
    {
        $with = $this->open($this->store());
        $without = $this->open($this->store());
        $with->forceFill(['google_place_id' => 'ChIJN1t_tDeuEmsRUsoyG83frY4'])->save();
        $merchants = collect($this->feeds()['merchant_feed.json']['merchant'])->keyBy('merchant_id');
        $this->assertSame(['place_id' => 'ChIJN1t_tDeuEmsRUsoyG83frY4'], $merchants['store-'.$with->id]['matching_hints']);
        $this->assertArrayNotHasKey('matching_hints', $merchants['store-'.$without->id], 'no place ID, no matching hints');

        $ok = ['merchant_id' => 'store-1', 'name' => 'Cafe', 'geo' => ['latitude' => 25.2, 'longitude' => 51.5]];
        $out = (new RwgFeedValidator)->sanitize('merchant_feed.json', ['merchant' => [
            $ok + ['matching_hints' => ['place_id' => 'bad id <script>']],
            ['merchant_id' => 'store-2', 'matching_hints' => ['place_id' => 'ChIJN1t_tDeuEmsRUsoyG83frY4']] + $ok,
        ]]);
        $this->assertSame(['store-1', 'store-2'], array_column($out['payload']['merchant'], 'merchant_id'), 'a bad place ID drops the hint, not the merchant');
        $this->assertArrayNotHasKey('matching_hints', $out['payload']['merchant'][0]);
        $this->assertSame('ChIJN1t_tDeuEmsRUsoyG83frY4', $out['payload']['merchant'][1]['matching_hints']['place_id']);
        $this->assertCount(1, $out['problems']);
    }

    public function test_a_place_id_belongs_to_one_store_only(): void
    {
        $a = $this->store();
        $b = $this->store();
        $a->forceFill(['google_place_id' => 'ChIJN1t_tDeuEmsRUsoyG83frY4'])->save();
        $b->forceFill(['google_place_id' => null])->save();
        $this->store()->forceFill(['google_place_id' => null])->save(); // many stores without one
        $this->expectException(UniqueConstraintViolationException::class);
        $b->forceFill(['google_place_id' => 'ChIJN1t_tDeuEmsRUsoyG83frY4'])->save();
    }

    public function test_services_feed_rules_need_a_known_merchant_valid_rules_and_unique_ids(): void
    {
        $v = new RwgFeedValidator;
        $v->sanitize('merchant_feed.json', ['merchant' => [['merchant_id' => 'store-1', 'name' => 'C', 'geo' => ['latitude' => 1, 'longitude' => 1]]]]);
        $svc = ['merchant_id' => 'store-1', 'service_id' => 'dining-1', 'type' => 'SERVICE_TYPE_DINING_RESERVATION', 'prepayment_type' => 'NOT_SUPPORTED'];
        $out = $v->sanitize('service_feed.json', ['service' => [
            $svc,
            $svc, // duplicate
            ['merchant_id' => 'store-9'] + $svc, // unknown merchant
            ['service_id' => 'x', 'prepayment_type' => 'MAGIC'] + $svc,
            ['service_id' => 'waitlist', 'waitlist_rules' => ['min_party_size' => 5, 'max_party_size' => 2]] + $svc,
            ['service_id' => 'waitlist', 'waitlist_rules' => ['min_party_size' => 1, 'max_party_size' => 8]] + $svc,
        ]]);

        $this->assertSame(['dining-1', 'waitlist'], array_column($out['payload']['service'], 'service_id'));
        $this->assertCount(4, $out['problems']);
    }

    public function test_availability_feed_rules(): void
    {
        $v = new RwgFeedValidator;
        $v->sanitize('merchant_feed.json', ['merchant' => [['merchant_id' => 'store-1', 'name' => 'C', 'geo' => ['latitude' => 1, 'longitude' => 1]]]]);
        $v->sanitize('service_feed.json', ['service' => [['merchant_id' => 'store-1', 'service_id' => 'dining-1', 'type' => 'T']]]);
        $row = ['merchant_id' => 'store-1', 'service_id' => 'dining-1', 'start_sec' => 1_800_000_000, 'duration_sec' => 3600, 'spots_total' => 10, 'spots_open' => 4];
        $out = $v->sanitize('availability_feed.json', ['service_availability' => [['availability' => [
            $row,
            $row, // duplicate slot
            ['start_sec' => 1_800_003_600, 'spots_open' => 11] + $row, // open > total
            ['start_sec' => 1_800_007_200, 'spots_open' => -1] + $row,
            ['start_sec' => 0] + $row,
            ['start_sec' => 1_800_010_800, 'duration_sec' => 0] + $row,
            ['service_id' => 'nope'] + $row, // unknown service
            ['start_sec' => 1_800_014_400, 'spots_open' => 0] + $row, // a full slot is valid
        ]]]]);

        $this->assertSame([1_800_000_000, 1_800_014_400], array_column($out['payload']['service_availability'][0]['availability'], 'start_sec'));
        $this->assertCount(6, $out['problems']);
    }

    public function test_every_real_feed_passes_its_own_rules_untouched(): void
    {
        $this->open($this->store(), waitlist: true);
        $this->open($this->store('+97498765'));
        $v = new RwgFeedValidator(allowHttp: true);
        $all = $this->feeds();
        foreach (['merchant_feed.json', 'service_feed.json', 'availability_feed.json'] as $name) {
            $out = $v->sanitize($name, $all[$name]);
            $this->assertSame([], $out['problems'], $name);
            $this->assertEquals($all[$name], $out['payload'], $name.' is unchanged by validation');
        }
        $this->assertNotEmpty($all['availability_feed.json']['service_availability'][0]['availability']);
        $this->assertContains('waitlist', array_column($all['service_feed.json']['service'], 'service_id'));
    }

    // ---- order and shards ----------------------------------------------------------------------------------------------

    public function test_order_is_stable_whatever_the_creation_order(): void
    {
        $a = $this->open($this->store());
        $b = $this->open($this->store());
        $feed = $this->feeds();
        $sorted = RwgFeedBuilder::sorted('merchant_feed.json', $feed['merchant_feed.json']);
        $ids = array_column($sorted['merchant'], 'merchant_id');
        $this->assertSame(['store-'.$a->id, 'store-'.$b->id], $ids);

        $shuffled = $feed['availability_feed.json'];
        $shuffled['service_availability'][0]['availability'] = array_reverse($shuffled['service_availability'][0]['availability']);
        $this->assertSame(
            json_encode(RwgFeedBuilder::sorted('availability_feed.json', $feed['availability_feed.json'])),
            json_encode(RwgFeedBuilder::sorted('availability_feed.json', $shuffled)),
            'the same data in any order gives byte-identical feeds'
        );
        $this->assertSame(
            json_encode(RwgFeedBuilder::sorted('service_feed.json', $feed['service_feed.json'])),
            json_encode(RwgFeedBuilder::sorted('service_feed.json', $this->feeds()['service_feed.json']))
        );
    }

    public function test_shards_keep_a_merchant_together_cover_everything_and_are_stable(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->open($this->store());
        }
        $feed = RwgFeedBuilder::sorted('availability_feed.json', $this->feeds()['availability_feed.json']);
        $all = $feed['service_availability'][0]['availability'];

        $one = RwgFeedBuilder::shard('availability_feed.json', $feed, 100000);
        $this->assertSame(['availability_feed.json'], array_keys($one));
        $this->assertSame([0, 1], [$one['availability_feed.json']['metadata']['shard_number'], $one['availability_feed.json']['metadata']['total_shards']]);

        $size = (int) ceil(count($all) / 3);
        $shards = RwgFeedBuilder::shard('availability_feed.json', $feed, $size);
        $this->assertCount(3, $shards);
        $count = 0;
        $home = [];
        foreach (array_values($shards) as $n => $shard) {
            $this->assertSame([$n, 3], [$shard['metadata']['shard_number'], $shard['metadata']['total_shards']]);
            $this->assertSame($feed['metadata']['nonce'], $shard['metadata']['nonce'], 'shards of one run share the nonce');
            foreach ($shard['service_availability'][0]['availability'] as $row) {
                $count++;
                $home[$row['merchant_id']][$n] = true;
            }
        }
        $this->assertSame(count($all), $count, 'every slot is in exactly one shard');
        foreach ($home as $merchant => $in) {
            $this->assertCount(1, $in, "$merchant stays in one shard");
        }
        $this->assertSame(array_keys($shards), array_keys(RwgFeedBuilder::shard('availability_feed.json', $feed, $size)), 'same input, same shard files');
        $this->assertStringContainsString('shard-1-of-3', array_keys($shards)[0]);
    }

    // ---- tenancy -------------------------------------------------------------------------------------------------------

    public function test_a_feed_for_one_store_never_carries_another_stores_data(): void
    {
        $x = $this->open($this->store('+97411111'), 25.1, 51.1);
        $y = $this->store('+97422222'); // Y is NOT opted in, but has data
        $x->forceFill(['google_place_id' => 'ChIJxxxxXXXXxxxx-X1'])->save();
        $y->forceFill(['google_place_id' => 'ChIJyyyyYYYYyyyy-Y2'])->save();
        StoreOption::updateStoreOption($y->id, 'reservation_covers_per_slot', '10');
        StoreOption::updateStoreOption($y->id, 'rwg_waitlist_enabled', '1');
        Branch::create(['store_id' => $y->id, 'name' => 'Main', 'active' => 1, 'accepting_orders' => 1, 'timezone' => 'UTC', 'latitude' => 30.0, 'longitude' => 40.0,
            'hours' => ['tue' => ['open' => '09:00', 'close' => '17:00', 'closed' => false]]]);
        Reservation::create(['store_id' => $y->id, 'name' => 'Y guest', 'party_size' => 9, 'reserved_at' => '2026-09-01 12:00:00', 'status' => 'confirmed', 'token' => Str::random(40)]);

        $json = json_encode($this->feeds());
        foreach ([$y->title, $y->slug, '+97422222', 'store-'.$y->id, 'dining-'.$y->id, '30.0', 'Y guest', 'ChIJyyyyYYYYyyyy-Y2'] as $needle) {
            $this->assertStringNotContainsString((string) $needle, (string) $json, "nothing of store Y ($needle) in X's feeds");
        }

        // both opted in: each entry carries only its own store's values, and Y's bookings do not reduce X's spots
        $this->open($y, 30.0, 40.0, branch: false);
        $merchants = collect($this->feeds()['merchant_feed.json']['merchant'])->keyBy('merchant_id');
        $this->assertSame('+97411111', $merchants['store-'.$x->id]['telephone']);
        $this->assertSame('+97422222', $merchants['store-'.$y->id]['telephone']);
        $this->assertEqualsWithDelta(25.1, $merchants['store-'.$x->id]['geo']['latitude'], 0.0001);
        $this->assertSame(['place_id' => 'ChIJxxxxXXXXxxxx-X1'], $merchants['store-'.$x->id]['matching_hints']);
        $this->assertSame(['place_id' => 'ChIJyyyyYYYYyyyy-Y2'], $merchants['store-'.$y->id]['matching_hints']);
        $this->assertEqualsWithDelta(30.0, $merchants['store-'.$y->id]['geo']['latitude'], 0.0001);
        $noon = Carbon::parse('2026-09-01 12:00:00', 'UTC')->getTimestamp();
        $slots = collect($this->feeds()['availability_feed.json']['service_availability'][0]['availability'])->where('start_sec', $noon)->keyBy('merchant_id');
        $this->assertSame(10, $slots['store-'.$x->id]['spots_open'], 'X is untouched by Y\'s 9 covers');
        $this->assertSame(1, $slots['store-'.$y->id]['spots_open']);
        $wl = collect($this->feeds()['service_feed.json']['service'])->where('service_id', 'waitlist');
        $this->assertSame(['store-'.$y->id], $wl->pluck('merchant_id')->values()->all(), 'a waitlist service only for the store that opted in');
    }

    // ---- the SFTP drop ------------------------------------------------------------------------------------------------

    private function gz(): array
    {
        File::ensureDirectoryExists($this->dir);
        $paths = [];
        foreach (['merchant_feed.json', 'service_feed.json'] as $n) {
            File::put($this->dir.'/'.$n.'.gz', gzencode('{}'));
            $paths[] = $this->dir.'/'.$n.'.gz';
        }

        return $paths;
    }

    private function sftpOn(): void
    {
        config(['services.reserve_with_google.enabled' => true, 'services.reserve_with_google.driver' => 'real',
            'services.reserve_with_google.sftp_host' => 'sftp.example.test', 'services.reserve_with_google.sftp_user' => 'u', 'services.reserve_with_google.sftp_path' => '/drop']);
    }

    public function test_the_drop_retries_a_flaky_upload_and_records_ok(): void
    {
        $this->sftpOn();
        $fake = new FakeSftpTransport(failConnects: 1, failPuts: ['service_feed.json.gz' => 2]);
        $waits = [];
        $status = (new RwgFeedDrop($fake, function (int $s) use (&$waits) {
            $waits[] = $s;
        }))->send($this->gz());

        $this->assertSame('ok', $status['status']);
        $this->assertSame(['merchant_feed.json.gz', 'service_feed.json.gz'], $fake->uploaded);
        $this->assertSame([1, 3], array_column($status['files'], 'attempts'), 'the second file needed 3 attempts');
        $this->assertSame(2, $fake->connects);
        $this->assertSame([2, 2, 4], $waits, 'backoff between attempts');
        $this->assertSame('ok', RwgFeedDrop::last()['status']);
        $this->assertSame(0, PlatformAlert::query()->count());
    }

    public function test_a_permanent_failure_is_reported_and_alerts_the_platform(): void
    {
        $this->sftpOn();
        $fake = new FakeSftpTransport(failPuts: ['service_feed.json.gz' => PHP_INT_MAX]);
        $status = (new RwgFeedDrop($fake, fn () => null))->send($this->gz(), ['merchant store-9: name is required']);

        $this->assertSame('partial', $status['status']);
        $this->assertSame(['ok', 'failed'], array_column($status['files'], 'status'));
        $this->assertSame(3, $status['files'][1]['attempts']);
        $this->assertSame(1, $status['problem_count']);
        $this->assertSame(1, PlatformAlert::query()->where('kind', RwgFeedDrop::ALERT_KIND)->count());

        $down = new FakeSftpTransport(failConnects: 99);
        $s2 = (new RwgFeedDrop($down, fn () => null))->send($this->gz());
        $this->assertSame('failed', $s2['status']);
        $this->assertSame(3, $down->connects);
        $this->assertSame([], $down->uploaded);
        $this->assertStringContainsString('connection refused', (string) $s2['error']);
    }

    public function test_the_command_stays_dark_without_real_sftp_and_uploads_with_it(): void
    {
        $fake = new FakeSftpTransport;
        $this->app->instance(RwgSftpTransport::class, $fake);
        $this->open($this->store());

        config(['services.reserve_with_google.enabled' => false]);
        $this->artisan('rwg:feeds')->assertExitCode(0);
        $this->assertSame(0, $fake->connects);
        $this->assertNull(RwgFeedDrop::last());

        config(['services.reserve_with_google.enabled' => true, 'services.reserve_with_google.driver' => 'fake']);
        $this->artisan('rwg:feeds')->assertExitCode(0);
        $this->assertSame(0, $fake->connects, 'the fake driver never connects');
        $this->assertSame('written', RwgFeedDrop::last()['status']);

        $this->sftpOn();
        $this->artisan('rwg:feeds')->assertExitCode(0);
        $this->assertSame(['availability_feed.json.gz', 'merchant_feed.json.gz', 'service_feed.json.gz'], collect($fake->uploaded)->sort()->values()->all());
        $this->assertSame('ok', RwgFeedDrop::last()['status']);
    }

    public function test_the_command_drops_an_entry_that_breaks_a_rule_and_reports_it(): void
    {
        $this->app->instance(RwgSftpTransport::class, new FakeSftpTransport);
        config(['services.reserve_with_google.enabled' => true, 'services.reserve_with_google.driver' => 'fake']);
        $good = $this->open($this->store());
        $bad = $this->open($this->store('not a phone'), 0.0, 0.0); // no usable geo
        $this->artisan('rwg:feeds')->assertExitCode(0);

        $merchants = json_decode(File::get($this->dir.'/merchant_feed.json'), true)['merchant'];
        $this->assertSame(['store-'.$good->id], array_column($merchants, 'merchant_id'));
        $last = RwgFeedDrop::last();
        $this->assertGreaterThan(0, $last['problem_count']);
        $this->assertStringContainsString('store-'.$bad->id, implode(' ', $last['problems']));
        $svc = json_decode(File::get($this->dir.'/service_feed.json'), true)['service'];
        $this->assertSame([], array_values(array_filter($svc, fn ($s) => $s['merchant_id'] === 'store-'.$bad->id)), 'its services are dropped with it');
    }

    public function test_the_command_removes_stale_shards_of_an_earlier_run(): void
    {
        config(['services.reserve_with_google.enabled' => true, 'services.reserve_with_google.driver' => 'fake']);
        $this->open($this->store());
        File::ensureDirectoryExists($this->dir);
        File::put($this->dir.'/merchant_feed.shard-2-of-2.json', '{}');
        $this->artisan('rwg:feeds')->assertExitCode(0);
        $this->assertFileDoesNotExist($this->dir.'/merchant_feed.shard-2-of-2.json');
        $this->assertFileExists($this->dir.'/merchant_feed.json');
    }

    // ---- the admin view -----------------------------------------------------------------------------------------------

    public function test_the_admin_page_shows_the_last_drop_and_what_was_left_out(): void
    {
        $this->sftpOn();
        $admin = User::factory()->create(['user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
        $fake = new FakeSftpTransport(failPuts: ['service_feed.json.gz' => PHP_INT_MAX]);
        (new RwgFeedDrop($fake, fn () => null))->send($this->gz(), ['merchant store-9: name is required']);

        $page = (string) $this->actingAs($admin)->get(route('admin.reserve-google.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-rwg-last-drop', $page);
        $this->assertStringContainsString('data-rwg-drop-status="partial"', $page);
        $this->assertStringContainsString('service_feed.json.gz', $page);
        $this->assertStringContainsString('put service_feed.json.gz failed', $page);
        $this->assertStringContainsString('merchant store-9: name is required', $page);
    }
}
