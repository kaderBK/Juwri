<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Tables;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StaffMember;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

class FloorMapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'Test', 'meta_description' => 'Test', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };

        view()->share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => $settings,
            'languages' => collect(),
        ]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    private function makePlan(): object
    {
        return TestPlans::create([
            'name' => 'Plan '.Str::random(6), 'description' => 'Test',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999]),
            'status' => 1,
        ]);
    }

    private function makeUser(): User
    {
        return User::factory()->create([
            'group_id' => (string) $this->makePlan()->id,
            'status' => 1, 'email_verified_at' => now(),
        ]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'Restaurant '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'Test',
            'description' => 'Test', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 Test St',
            'phone' => '1234567890', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeTable(Store $store): StoreTable
    {
        return StoreTable::create([
            'store_id' => $store->id,
            'table_no' => rand(1, 99),
            'key' => Str::random(8),
            'map_x' => 100,
            'map_y' => 100,
            'shape' => 'square',
            'seats' => 4,
        ]);
    }

    /** Save layout persists positions/shape */
    public function test_save_layout_persists(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $table = $this->makeTable($store);

        $this->actingAs($owner)
            ->postJson(route('floor-map.save', $store->id), [
                'tables' => [[
                    'id' => $table->id,
                    'map_x' => 250,
                    'map_y' => 300,
                    'shape' => 'circle',
                    'seats' => 6,
                    'floor_label' => 'VIP',
                ]],
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('store_tables', [
            'id' => $table->id,
            'map_x' => 250,
            'map_y' => 300,
            'shape' => 'circle',
            'seats' => 6,
            'floor_label' => 'VIP',
        ]);
    }

    /** FP7 #38/#39: save persists section + a server that belongs to the restaurant. */
    public function test_save_layout_persists_section_and_own_server(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $table = $this->makeTable($store);
        $server = StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $store->id, 'name' => 'Sam',
            'role' => 'waiter', 'email' => 'sam'.Str::random(4).'@ex.com',
            'password' => bcrypt('x'), 'active' => 1,
        ]);

        $this->actingAs($owner)->postJson(route('floor-map.save', $store->id), [
            'tables' => [[
                'id' => $table->id, 'map_x' => 20, 'map_y' => 20, 'shape' => 'square', 'seats' => 4,
                'section' => 'Patio', 'server_staff_id' => $server->id,
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('store_tables', [
            'id' => $table->id, 'section' => 'Patio', 'server_staff_id' => $server->id,
        ]);
    }

    /** FP4 #26/#22: save persists a capacity range + room, clamped to the seat count. */
    public function test_save_layout_persists_capacity_range_and_room(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $table = $this->makeTable($store);

        $this->actingAs($owner)->postJson(route('floor-map.save', $store->id), [
            'tables' => [[
                'id' => $table->id, 'map_x' => 20, 'map_y' => 20, 'shape' => 'square', 'seats' => 4,
                'floor_label' => 'Patio', 'min_covers' => 2, 'max_covers' => 4,
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('store_tables', [
            'id' => $table->id, 'floor_label' => 'Patio', 'min_covers' => 2, 'max_covers' => 4,
        ]);
    }

    /** FP4 #26: a backwards range is swapped and a max above the seats is clamped to seats. */
    public function test_capacity_range_is_normalised(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $table = $this->makeTable($store); // seats default 4

        $this->actingAs($owner)->postJson(route('floor-map.save', $store->id), [
            'tables' => [[
                'id' => $table->id, 'map_x' => 0, 'map_y' => 0, 'shape' => 'square', 'seats' => 4,
                'min_covers' => 8, 'max_covers' => 2, // backwards + max over seats
            ]],
        ])->assertOk();

        // swapped to 2..8, then both clamped to ≤ seats(4) → 2..4
        $this->assertDatabaseHas('store_tables', [
            'id' => $table->id, 'min_covers' => 2, 'max_covers' => 4,
        ]);
    }

    /** FP4 #26: the status feed exposes the capacity range for seating decisions. */
    public function test_status_exposes_capacity_range(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $table = $this->makeTable($store);
        $table->update(['min_covers' => 2, 'max_covers' => 6, 'floor_label' => 'Bar']);

        $data = $this->actingAs($owner)->getJson(route('floor-map.status', $store->id))->assertOk()->json();
        $this->assertSame(2, $data[$table->id]['min_covers']);
        $this->assertSame(6, $data[$table->id]['max_covers']);
        $this->assertSame('Bar', $data[$table->id]['floor_label']);
    }

    /** FP4 #22: the status feed can be scoped to one room/zone. */
    public function test_status_filters_by_room(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $patio = $this->makeTable($store);
        $patio->update(['floor_label' => 'Patio']);
        $bar = $this->makeTable($store);
        $bar->update(['floor_label' => 'Bar']);

        $data = $this->actingAs($owner)->getJson(route('floor-map.status', $store->id).'?floor=Patio')->assertOk()->json();
        $this->assertArrayHasKey($patio->id, $data);
        $this->assertArrayNotHasKey($bar->id, $data);
    }

    /** FP9 #48/#49: turn-times roll up per table + section, with a by-hour histogram. */
    public function test_turn_times_aggregates_per_table_and_section(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        // Two tables, one in section Patio.
        StoreTable::create(['store_id' => $store->id, 'table_no' => 5, 'key' => Str::random(8), 'seats' => 4, 'section' => 'Patio', 'map_x' => 0, 'map_y' => 0, 'shape' => 'square']);
        StoreTable::create(['store_id' => $store->id, 'table_no' => 6, 'key' => Str::random(8), 'seats' => 2, 'section' => 'Bar', 'map_x' => 0, 'map_y' => 0, 'shape' => 'square']);

        // Table 5: two completed on-table orders, 30 + 50 min → avg 40.
        Order::create(['store_id' => $store->id, 'type' => 'on-table', 'status' => 'completed', 'table_number' => 5, 'created_at' => now()->subDays(2)->setTime(12, 0), 'completed_at' => now()->subDays(2)->setTime(12, 30)]);
        Order::create(['store_id' => $store->id, 'type' => 'on-table', 'status' => 'completed', 'table_number' => 5, 'created_at' => now()->subDays(1)->setTime(19, 0), 'completed_at' => now()->subDays(1)->setTime(19, 50)]);
        // Table 6: one 20-min order.
        Order::create(['store_id' => $store->id, 'type' => 'on-table', 'status' => 'completed', 'table_number' => 6, 'created_at' => now()->subDays(1)->setTime(12, 0), 'completed_at' => now()->subDays(1)->setTime(12, 20)]);
        // Noise: not completed / not on-table / too old — all excluded.
        Order::create(['store_id' => $store->id, 'type' => 'on-table', 'status' => 'preparing', 'table_number' => 5, 'created_at' => now(), 'completed_at' => null]);
        Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'completed', 'table_number' => 5, 'created_at' => now(), 'completed_at' => now()]);
        Order::create(['store_id' => $store->id, 'type' => 'on-table', 'status' => 'completed', 'table_number' => 5, 'created_at' => now()->subDays(90), 'completed_at' => now()->subDays(90)->addMinutes(10)]);

        $d = $this->actingAs($owner)->getJson(route('floor-map.turnTimes', $store->id))->assertOk()->json();

        $this->assertSame(3, $d['total_orders']);              // only the 3 recent completed on-table
        $t5 = collect($d['tables'])->firstWhere('table_no', '5');
        $this->assertSame(2, $t5['orders']);
        $this->assertEqualsWithDelta(40.0, $t5['avg_minutes'], 0.1);
        $patio = collect($d['sections'])->firstWhere('section', 'Patio');
        $this->assertSame(2, $patio['orders']);
        // by-hour histogram: two orders at 12:00, one at 19:00.
        $this->assertSame(2, $d['by_hour'][12]);
        $this->assertSame(1, $d['by_hour'][19]);
        // busiest-first ordering.
        $this->assertSame('5', $d['tables'][0]['table_no']);
    }

    /** FP9: turn-times respect tenant + branch scope. */
    public function test_turn_times_are_tenant_scoped(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        Order::create(['store_id' => $store->id, 'type' => 'on-table', 'status' => 'completed', 'table_number' => 1, 'created_at' => now()->subDay(), 'completed_at' => now()->subDay()->addMinutes(15)]);

        $other = $this->makeUser();
        $this->actingAs($other)->getJson(route('floor-map.turnTimes', $store->id))->assertNotFound(); // ownerRestaurant 404
    }

    /** FP8 #46: save persists a per-table note. */
    public function test_save_layout_persists_table_note(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $table = $this->makeTable($store);

        $this->actingAs($owner)->postJson(route('floor-map.save', $store->id), [
            'tables' => [[
                'id' => $table->id, 'map_x' => 10, 'map_y' => 10, 'shape' => 'square', 'seats' => 4,
                'note' => 'VIP — birthday',
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('store_tables', ['id' => $table->id, 'note' => 'VIP — birthday']);
    }

    /** FP7 #39: a server from another restaurant cannot be assigned (stored as null). */
    public function test_save_layout_rejects_foreign_server(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $table = $this->makeTable($store);
        $otherOwner = $this->makeUser();
        $otherRestaurant = $this->makeRestaurant($otherOwner);
        $foreignServer = StaffMember::create([
            'owner_id' => $otherOwner->id, 'store_id' => $otherRestaurant->id, 'name' => 'X',
            'role' => 'waiter', 'email' => 'x'.Str::random(4).'@ex.com', 'password' => bcrypt('x'), 'active' => 1,
        ]);

        $this->actingAs($owner)->postJson(route('floor-map.save', $store->id), [
            'tables' => [[
                'id' => $table->id, 'map_x' => 20, 'map_y' => 20, 'shape' => 'square', 'seats' => 4,
                'server_staff_id' => $foreignServer->id,
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('store_tables', ['id' => $table->id, 'server_staff_id' => null]);
    }

    /** Status endpoint returns tables with free status */
    public function test_status_returns_tables(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $table = $this->makeTable($store);

        $response = $this->actingAs($owner)
            ->getJson(route('floor-map.status', $store->id))
            ->assertOk();

        $this->assertArrayHasKey($table->id, $response->json());
        $this->assertEquals('free', $response->json()[$table->id]['status']);
    }

    /** Tenant isolation: other owner cannot save layout */
    public function test_save_layout_tenant_isolation(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $table = $this->makeTable($store);

        $other = $this->makeUser();

        $this->actingAs($other)
            ->postJson(route('floor-map.save', $store->id), [
                'tables' => [['id' => $table->id, 'map_x' => 0, 'map_y' => 0, 'shape' => 'square', 'seats' => 1, 'floor_label' => null]],
            ])
            ->assertNotFound();
    }
}
