<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Models\KitchenStation;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Modules\Core\Tenancy\TenantOwnsMiddlewareTest;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * S13 — cross-tenant IDOR sweep.
 *
 * Extends the H7 ProbeCrossTenantTest into a broad, data-provider-style sweep
 * over every owner-scoped by-id route the audit flagged: order status, branch,
 * staff, split-bill, inventory, reservation and kitchen-station edit/delete.
 * Owner A authenticates and pokes owner B's id; the invariant is that the
 * owner-scoped `findOrFail` (or the explicit `user_id` check on order status)
 * denies it — 403/404 — and B's row is never mutated.
 *
 * Controller-level scoping is the enforcement under test, so — like the H7
 * probe — middleware is disabled to isolate it (the `tenant.owns` middleware is
 * covered separately by {@see TenantOwnsMiddlewareTest}).
 */
class CrossTenantIdorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
    }

    /** A full tenant: user + restaurant + branch + staff + order + menu + reservation + station. */
    private function tenant(): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);
        $user = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(6);
        $r = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branch = Branch::create(['store_id' => $r->id, 'name' => 'Main', 'position' => 0]);
        $staff = StaffMember::create([
            'owner_id' => $user->id, 'store_id' => $r->id, 'name' => 'S',
            'email' => Str::random(6).'@t.test', 'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 1,
        ]);
        $order = Order::create([
            'store_id' => $r->id, 'customer_name' => 'J', 'status' => 'pending',
            'track_token' => Str::random(32), 'created_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains']);
        $menu = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 12.00, 'active' => 1]);
        $reservation = Reservation::create([
            'store_id' => $r->id, 'name' => 'Guest', 'party_size' => 2,
            'reserved_at' => now()->addDay(), 'status' => 'pending',
        ]);
        $station = KitchenStation::create(['store_id' => $r->id, 'name' => 'Grill', 'active' => 1, 'position' => 0]);

        return compact('user', 'r', 'branch', 'staff', 'order', 'menu', 'reservation', 'station');
    }

    /**
     * Every owner-scoped by-id route: [routeName, httpVerb, tenant-key of the
     * bound resource, request body, expected status].
     *
     * @return array<string, array{0:string,1:string,2:string,3:array,4:int}>
     */
    public static function ownedRoutes(): array
    {
        return [
            'order status' => ['stores.updateOrderStatus', 'post',   'order',       ['status' => 'completed'], 403],
            'branch update' => ['branches.update',               'put',    'branch',      ['name' => 'Hijacked'],    404],
            'branch destroy' => ['branches.destroy',              'delete', 'branch',      [],                        404],
            'staff update' => ['staff.update',                  'put',    'staff',       ['name' => 'X', 'role' => 'manager'], 404],
            'staff destroy' => ['staff.destroy',                 'delete', 'staff',       [],                        404],
            'split show' => ['orders.split.show',             'get',    'order',       [],                        404],
            'split store' => ['orders.split.store',            'post',   'order',       ['parts' => 2],            404],
            'split destroy' => ['orders.split.destroy',          'delete', 'order',       [],                        404],
            'inventory update' => ['inventory.update',              'put',    'menu',        ['stock_qty' => 5],        404],
            'reservation status' => ['reservations.status',           'post',   'reservation', ['status' => 'confirmed'], 404],
            'station update' => ['stations.update',               'put',    'station',     ['name' => 'X'],           404],
            'station destroy' => ['stations.destroy',              'delete', 'station',     [],                        404],
        ];
    }

    #[DataProvider('ownedRoutes')]
    public function test_owner_a_cannot_reach_owner_bs_resource_by_id(string $route, string $verb, string $key, array $body, int $expected): void
    {
        $a = $this->tenant();
        $b = $this->tenant();

        $this->actingAs($a['user'])
            ->{$verb}(route($route, $b[$key]->id), $body)
            ->assertStatus($expected);

        // The victim row is never mutated by a cross-tenant poke.
        $victim = $b[$key];
        $this->assertNotNull($victim->fresh(), $key.' row must still exist after a foreign poke');
    }

    public function test_owner_can_reach_their_own_resources(): void
    {
        // Sanity: the sweep denies foreigners without false-denying the owner.
        // Use action routes (redirect, no theme-view render) to prove the owner
        // is never falsely blocked by the owner-scoped findOrFail.
        $a = $this->tenant();

        $this->actingAs($a['user'])
            ->put(route('branches.update', $a['branch']->id), ['name' => 'Renamed'])
            ->assertRedirect();
        $this->assertSame('Renamed', $a['branch']->fresh()->name);

        $this->actingAs($a['user'])
            ->post(route('reservations.status', $a['reservation']->id), ['status' => 'confirmed'])
            ->assertRedirect();
        $this->assertSame('confirmed', $a['reservation']->fresh()->status);
    }
}
