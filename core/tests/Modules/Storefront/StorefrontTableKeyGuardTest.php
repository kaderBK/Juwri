<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store as SessionStore;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\ScanDedupe;
use Juwri\Core\Services\TableKeyGuard;
use Juwri\Core\Support\ClientNetwork;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * The QR table-key guard on a DEFAULT install (no migration module): every route into the storefront page
 * (`/{slug}`, `/{numericId}`, the old `/restaurant/{slug}/…` URL) resolves `?table=` through the same guarded lookup,
 * so guessing keys is cut off per store (visitor session + client network). Plus the guard's own buckets.
 */
class StorefrontTableKeyGuardTest extends TestCase
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
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);
        config(['abuse.table_key_misses_per_minute' => 3, 'abuse.table_key_misses_per_minute_network' => 3]);
    }

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'settings' => ['scan_limit' => 999, 'table_qr' => '1'], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'store-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Store',
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123',
        ]);
    }

    /** @return array{Store, mixed} */
    private function storeWithTable(): array
    {
        $store = $this->store();

        return [$store, $store->tables()->create(['table_no' => 7, 'key' => 'tk-'.Str::random(12), 'seats' => 2])];
    }

    public function test_a_default_install_limits_table_key_guessing_on_the_slug_page(): void
    {
        [$store, $table] = $this->storeWithTable();

        $this->get('/'.$store->slug.'?table='.$table->key)->assertOk()->assertViewHas('table', fn ($t) => $t && (int) $t->id === (int) $table->id);
        foreach (['g1', 'g2', 'g3'] as $guess) {
            $this->get('/'.$store->slug.'?table='.$guess)->assertOk()->assertViewHas('table', null);
        }
        // Over the limit: even the real key answers "no table" (no lookup, no oracle), and the menu still opens.
        $this->get('/'.$store->slug.'?table='.$table->key)->assertOk()->assertViewHas('table', null);
        $this->get('/'.$store->id.'?table='.$table->key)->assertOk()->assertViewHas('table', null);
    }

    public function test_the_old_url_route_goes_through_the_same_guard(): void
    {
        [$store, $table] = $this->storeWithTable();

        foreach (['g1', 'g2', 'g3'] as $guess) {
            $this->get('/restaurant/'.$store->slug.'?table='.$guess)->assertOk();
        }
        $this->get('/restaurant/'.$store->slug.'?table='.$table->key)->assertOk()->assertViewHas('table', null);
        $this->get('/'.$store->slug.'?table='.$table->key)->assertOk()->assertViewHas('table', null);
    }

    public function test_an_array_table_is_never_a_key_and_counts_as_a_miss(): void
    {
        [$store, $table] = $this->storeWithTable();

        $this->get('/'.$store->slug.'?table[]='.$table->key)->assertOk()->assertViewHas('table', null);
        $this->get('/'.$store->slug.'?table[]=a')->assertOk();
        $this->get('/'.$store->slug.'?table[]=b')->assertOk();
        $this->get('/'.$store->slug.'?table='.$table->key)->assertOk()->assertViewHas('table', null);
    }

    public function test_an_array_room_token_is_ignored_and_never_crashes(): void
    {
        [$store, $table] = $this->storeWithTable();

        $this->get('/'.$store->slug.'?table='.$table->key.'&rt[]=x')->assertOk();
        $this->assertNull(session('hotel_room_token'));
    }

    public function test_the_limit_is_per_store(): void
    {
        [$store] = $this->storeWithTable();
        [$other, $otherTable] = $this->storeWithTable();

        foreach (['g1', 'g2', 'g3'] as $guess) {
            $this->get('/'.$store->slug.'?table='.$guess)->assertOk();
        }
        $this->get('/'.$other->slug.'?table='.$otherTable->key)->assertOk()
            ->assertViewHas('table', fn ($t) => $t && (int) $t->id === (int) $otherTable->id);
    }

    private function onRequest(string $ip, ?string $sessionId = null): void
    {
        $request = Request::create('/x', 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);
        if ($sessionId !== null) {
            $session = new SessionStore('test', new ArraySessionHandler(10), $sessionId);
            $request->setLaravelSession($session);
        }
        $this->app->instance('request', $request);
    }

    /** One guest on shared venue Wi-Fi is limited by their own session; the network limit is higher. */
    public function test_a_session_is_limited_before_the_shared_network(): void
    {
        config(['abuse.table_key_misses_per_minute' => 2, 'abuse.table_key_misses_per_minute_network' => 50]);
        $sessionA = str_repeat('a', 40);
        $sessionB = str_repeat('b', 40);

        $this->onRequest('198.51.100.9', $sessionA);
        TableKeyGuard::miss(9);
        $this->onRequest('198.51.100.9', $sessionA);
        TableKeyGuard::miss(9);
        $this->assertTrue(TableKeyGuard::blocked(9), 'the guessing session is cut off');

        $this->onRequest('198.51.100.9', $sessionB);
        $this->assertFalse(TableKeyGuard::blocked(9), 'another guest on the same Wi-Fi is not');
    }

    public function test_a_miss_counts_once_per_request(): void
    {
        config(['abuse.table_key_misses_per_minute_network' => 2]);
        $this->onRequest('198.51.100.10');
        TableKeyGuard::miss(9);
        TableKeyGuard::miss(9);
        $this->assertFalse(TableKeyGuard::blocked(9));
    }

    /** IPv6: the whole /64 is one client network (a subscriber can rotate addresses inside it). */
    public function test_ipv6_addresses_are_bucketed_per_64(): void
    {
        $this->assertSame(ClientNetwork::bucket('2001:db8:1:2::1'), ClientNetwork::bucket('2001:db8:1:2:ffff::9'));
        $this->assertNotSame(ClientNetwork::bucket('2001:db8:1:2::1'), ClientNetwork::bucket('2001:db8:1:3::1'));
        $this->assertSame('198.51.100.1', ClientNetwork::bucket('198.51.100.1'));
        // IPv4-mapped IPv6 is the IPv4 client itself, not one shared ::ffff:0:0/64 bucket for all IPv4 visitors.
        $this->assertSame('198.51.100.7', ClientNetwork::bucket('::ffff:198.51.100.7'));
        $this->assertNotSame(ClientNetwork::bucket('::ffff:198.51.100.7'), ClientNetwork::bucket('::ffff:198.51.100.8'));

        config(['abuse.table_key_misses_per_minute_network' => 2]);
        $this->onRequest('2001:db8:1:2::1');
        TableKeyGuard::miss(9);
        $this->onRequest('2001:db8:1:2::2');
        TableKeyGuard::miss(9);
        $this->onRequest('2001:db8:1:2::3');
        $this->assertTrue(TableKeyGuard::blocked(9));
        $this->onRequest('2001:db8:1:3::1');
        $this->assertFalse(TableKeyGuard::blocked(9));
    }

    public function test_scan_dedupe_is_per_store_and_network(): void
    {
        $this->onRequest('198.51.100.11');
        $this->assertTrue(ScanDedupe::first(5));
        $this->assertFalse(ScanDedupe::first(5));
        $this->assertTrue(ScanDedupe::first(6));
        $this->onRequest('198.51.100.12');
        $this->assertTrue(ScanDedupe::first(5));
    }
}
