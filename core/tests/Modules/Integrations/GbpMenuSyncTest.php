<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Jobs\SyncGbpMenuJob;
use Juwri\Modules\Integrations\Services\Gbp\FakeGbpClient;
use Juwri\Modules\Integrations\Services\Gbp\GbpClientInterface;
use Juwri\Modules\Integrations\Services\Gbp\GbpMenuSyncService;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * google-partner Lane A / A5 — FoodMenus mapper + SyncGbpMenuJob + fan-out command.
 */
class GbpMenuSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.currency_code' => 'USD']);
        FakeGbpClient::reset();
    }

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Crave', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function category(Store $s, string $name, int $pos = 0): MenuCategory
    {
        return MenuCategory::create(['store_id' => $s->id, 'name' => $name, 'active' => '1', 'position' => $pos]);
    }

    private function item(Store $s, MenuCategory $c, string $name, $price, array $extra = []): Menu
    {
        return Menu::create(array_merge([
            'store_id' => $s->id, 'category_id' => $c->id, 'name' => $name, 'price' => $price, 'active' => '1',
        ], $extra));
    }

    // ---- Mapper ----

    public function test_build_maps_sections_items_money_and_language(): void
    {
        $s = $this->store();
        $mains = $this->category($s, 'Mains', 0);
        $this->item($s, $mains, 'Burger', 12.50, ['description' => 'Juicy']);
        $this->item($s, $mains, 'Fries', 4);

        $body = new GbpMenuSyncService()->build($s);

        $this->assertCount(1, $body['menus']);
        $menu = $body['menus'][0];
        $this->assertSame('Crave', $menu['labels'][0]['displayName']);
        $this->assertCount(1, $menu['sections']);

        $section = $menu['sections'][0];
        $this->assertSame('Mains', $section['labels'][0]['displayName']);
        $this->assertSame('en', $section['labels'][0]['languageCode']);
        $this->assertCount(2, $section['items']);

        $burger = $section['items'][0];
        $this->assertSame('Burger', $burger['labels'][0]['displayName']);
        $this->assertSame('Juicy', $burger['labels'][0]['description']);
        $this->assertSame(['currencyCode' => 'USD', 'units' => 12, 'nanos' => 500000000], $burger['attributes']['price']);

        $fries = $section['items'][1];
        $this->assertSame(['currencyCode' => 'USD', 'units' => 4, 'nanos' => 0], $fries['attributes']['price']);
        $this->assertArrayNotHasKey('description', $fries['labels'][0]); // empty description omitted
    }

    public function test_build_drops_empty_sections_and_truncates_caps(): void
    {
        $s = $this->store();
        $this->category($s, 'Empty Cat', 0); // no items → must be dropped
        $filled = $this->category($s, 'Filled', 1);
        $longName = str_repeat('A', 200);
        $this->item($s, $filled, $longName, 3);

        $body = new GbpMenuSyncService()->build($s);
        $sectionNames = array_map(fn ($sec) => $sec['labels'][0]['displayName'], $body['menus'][0]['sections']);

        $this->assertSame(['Filled'], $sectionNames); // empty category not emitted
        $item = $body['menus'][0]['sections'][0]['items'][0];
        $this->assertSame(140, mb_strlen($item['labels'][0]['displayName'])); // displayName capped at 140
    }

    public function test_build_maps_dietary_flags(): void
    {
        $s = $this->store();
        $c = $this->category($s, 'Mains', 0);
        $this->item($s, $c, 'Vegan Bowl', 9, ['is_vegan' => true, 'is_halal' => true]);

        $body = new GbpMenuSyncService()->build($s);
        $diet = $body['menus'][0]['sections'][0]['items'][0]['attributes']['dietaryRestriction'];
        $this->assertContains('VEGAN', $diet);
        $this->assertContains('HALAL', $diet);
    }

    public function test_build_empty_when_no_menu(): void
    {
        $s = $this->store();
        $this->assertSame(['menus' => []], new GbpMenuSyncService()->build($s));
    }

    // ---- Job ----

    private function linkAndEnable(Store $s, string $loc = '222'): void
    {
        Credential::create([
            'store_id' => $s->id, 'feature' => 'gbp', 'provider' => 'gbp', 'active' => true,
            'secrets' => ['access_token' => 'AT', 'location_id' => $loc, 'gbp_menu_sync_enabled' => true],
        ]);
    }

    public function test_job_pushes_and_is_idempotent(): void
    {
        $s = $this->store();
        $c = $this->category($s, 'Mains', 0);
        $this->item($s, $c, 'Burger', 10);
        $this->linkAndEnable($s, 'LOC-9');

        new SyncGbpMenuJob($s->id)->handle(new GbpMenuSyncService);
        $first = FakeGbpClient::$foodMenus['LOC-9'];
        $this->assertArrayHasKey('menus', $first);

        // Run again → full replace, identical resource (idempotent), no duplication.
        new SyncGbpMenuJob($s->id)->handle(new GbpMenuSyncService);
        $this->assertEquals($first, FakeGbpClient::$foodMenus['LOC-9']);

        $creds = Credential::for('gbp', $s->id);
        $this->assertNotEmpty($creds['last_synced_at']);
        $this->assertArrayNotHasKey('sync_error', $creds);
    }

    public function test_job_dark_no_op_when_not_linked(): void
    {
        $s = $this->store();
        $c = $this->category($s, 'Mains', 0);
        $this->item($s, $c, 'Burger', 10);
        // Credential with token but NO location_id + sync disabled.
        Credential::create([
            'store_id' => $s->id, 'feature' => 'gbp', 'provider' => 'gbp', 'active' => true,
            'secrets' => ['access_token' => 'AT'],
        ]);

        new SyncGbpMenuJob($s->id)->handle(new GbpMenuSyncService);
        $this->assertSame([], FakeGbpClient::$foodMenus); // zero HTTP, nothing pushed
    }

    public function test_job_records_sync_error_on_failure(): void
    {
        $s = $this->store();
        $c = $this->category($s, 'Mains', 0);
        $this->item($s, $c, 'Burger', 10);
        $this->linkAndEnable($s, 'LOC-9');

        // Bind a throwing client (factory returns app-bound instance first).
        app()->bind(GbpClientInterface::class, fn () => new class implements GbpClientInterface
        {
            public function listActionLinks(string $l): array
            {
                return [];
            }

            public function createActionLink(string $l, array $b): array
            {
                return [];
            }

            public function patchActionLink(string $n, array $b): array
            {
                return [];
            }

            public function listAccounts(): array
            {
                return [];
            }

            public function listLocations(string $a): array
            {
                return [];
            }

            public function getFoodMenus(string $l): array
            {
                return [];
            }

            public function updateFoodMenus(string $l, array $f): array
            {
                throw new \RuntimeException('boom');
            }
        });

        new SyncGbpMenuJob($s->id)->handle(new GbpMenuSyncService);

        $creds = Credential::for('gbp', $s->id);
        $this->assertSame('boom', $creds['sync_error']);
        $this->assertNotEmpty($creds['last_synced_at']);
    }

    // ---- Fan-out command ----

    public function test_command_dispatches_only_enabled_linked_stores(): void
    {
        Bus::fake();

        $on = $this->store();
        $this->linkAndEnable($on, 'L1');

        $off = $this->store(); // linked but sync disabled
        Credential::create([
            'store_id' => $off->id, 'feature' => 'gbp', 'provider' => 'gbp', 'active' => true,
            'secrets' => ['access_token' => 'AT', 'location_id' => 'L2', 'gbp_menu_sync_enabled' => false],
        ]);

        $this->artisan('gbp:sync-menus')->assertSuccessful();

        Bus::assertDispatched(SyncGbpMenuJob::class, fn ($j) => $j->storeId === $on->id);
        Bus::assertDispatchedTimes(SyncGbpMenuJob::class, 1);
    }

    public function test_command_dark_no_op_when_unconfigured(): void
    {
        Bus::fake();
        $this->artisan('gbp:sync-menus')->assertSuccessful();
        Bus::assertNotDispatched(SyncGbpMenuJob::class);
    }

    // ---- Controller ----

    public function test_sync_now_queues_job_when_linked(): void
    {
        Bus::fake();
        $s = $this->store();
        $this->linkAndEnable($s, 'L1');
        $this->withoutMiddleware();

        $this->actingAs(User::find($s->user_id))
            ->post(route('stores.gbp.syncNow').'?store_id='.$s->id)
            ->assertRedirect();

        Bus::assertDispatched(SyncGbpMenuJob::class, fn ($j) => $j->storeId === $s->id);
    }

    public function test_save_menu_sync_toggles_flag(): void
    {
        $s = $this->store();
        Credential::create([
            'store_id' => $s->id, 'feature' => 'gbp', 'provider' => 'gbp', 'active' => true,
            'secrets' => ['access_token' => 'AT', 'location_id' => 'L1', 'gbp_menu_sync_enabled' => false],
        ]);
        $this->withoutMiddleware();

        $this->actingAs(User::find($s->user_id))
            ->post(route('stores.gbp.menuSync').'?store_id='.$s->id, ['gbp_menu_sync_enabled' => '1'])
            ->assertRedirect();

        $this->assertTrue((bool) Credential::for('gbp', $s->id)['gbp_menu_sync_enabled']);
    }
}
