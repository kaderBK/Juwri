<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StaffMember;
use App\Models\StoreOption;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\BranchMenu;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Pos\Models\PosOfflineSnapshot;
use Juwri\Modules\Pos\Services\Offline\OfflineSnapshot;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Offline POS (the offline POS spec §2): the catalogue snapshot a till keeps to sell offline — minor
 * units, versioned by content (a new version only when something the till uses changed, kept for replay), 304 when the
 * till is current, valid for the store's offline hours (owner Q2: 12 h default, 24 h max), and ONE store's and branch's
 * data only (tenancy).
 */
class PosOfflineSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    /** @return array{0: Store, 1: StaffMember, 2: Menu, 3: Branch} */
    private function make(string $dish = 'Dish', float $price = 10.5): array
    {
        $plan = TestPlans::create(['name' => 'P '.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $r = Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => $title,
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now()]);
        StoreOption::updateStoreOption($r->id, 'currency_code', 'SAR');
        $branch = Branch::create(['store_id' => $r->id, 'name' => 'Main', 'active' => 1, 'accepting_orders' => 1, 'timezone' => 'UTC']);
        $staff = StaffMember::create(['owner_id' => $r->user_id, 'store_id' => $r->id, 'name' => 'Cashier '.$title, 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 1, 'pin' => '4321']);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains '.$title, 'active' => 1]);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => $dish, 'price' => $price, 'active' => 1]);

        return [$r, $staff, $item, $branch];
    }

    public function test_the_snapshot_holds_the_till_catalogue_in_minor_units_and_no_secrets(): void
    {
        [$r, $staff, $item, $branch] = $this->make('Kabsa', 10.5);
        StoreOption::updateStoreOption($r->id, 'restaurant_taxx_charge', '15');
        StoreOption::updateStoreOption($r->id, 'restaurant_tax_type', 'percentage');

        $doc = $this->actingAs($staff, 'staff')->getJson(route('pos.offline.snapshot', ['branch' => $branch->id]))->assertOk()
            ->assertHeader('ETag', '"1"')->json();

        $this->assertSame(1, $doc['schema']);
        $this->assertSame(1, $doc['version']);
        $this->assertSame($branch->id, $doc['branch_id']);
        $this->assertEquals(['id' => $r->id, 'title' => $r->title, 'currency' => 'SAR', 'exponent' => 2, 'rounding' => 'half_up'], $doc['parts']['store']);
        $items = collect($doc['parts']['catalogue']['items'])->keyBy('id');
        $this->assertSame(1050, $items[$item->id]['price_minor']);
        $this->assertSame('Kabsa', $items[$item->id]['name']);
        $this->assertEquals(['mode' => 'percentage', 'rate' => '15', 'base' => 'subtotal_before_discount'], $doc['parts']['taxes']);
        $this->assertSame(hash('sha256', OfflineSnapshot::canonical($doc['parts'])), $doc['hash'], 'the hash is the canonical parts');
        $this->assertSame(['id', 'name', 'permissions', 'pin_rev', 'role'], array_keys($doc['parts']['staff'][0]));
        $json = (string) json_encode($doc);
        foreach (['4321', 'password', '"pin":', 'pin_hash', '@t.test'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, "no $secret in the snapshot");
        }
        $this->assertFalse($doc['parts']['caps']['card_offline_enabled'], 'offline card stays off until the provider question is answered');
        $this->assertSame([25000, 250000], [$doc['parts']['caps']['card_max_amount_minor'], $doc['parts']['caps']['card_max_total_minor']]);
    }

    public function test_a_new_version_only_when_the_content_changes_and_304_when_current(): void
    {
        [$r, $staff, $item, $branch] = $this->make();
        $get = fn (array $h = []) => $this->actingAs($staff, 'staff')->withHeaders($h)->get(route('pos.offline.snapshot', ['branch' => $branch->id]));

        $this->assertSame(1, $get()->json('version'));
        $this->assertSame(1, $get()->json('version'), 'unchanged content: same version');
        $get(['If-None-Match' => '"1"'])->assertStatus(304);

        $item->update(['price' => 12]);
        $second = $get(['If-None-Match' => '"1"'])->assertOk();
        $this->assertSame(2, $second->json('version'));
        $this->assertSame(1200, collect($second->json('parts.catalogue.items'))->firstWhere('id', $item->id)['price_minor']);

        StoreOption::updateStoreOption($r->id, 'restaurant_taxx_charge', '5');
        $this->assertSame(3, $get()->json('version'), 'a tax change is a new version');
        StaffMember::create(['owner_id' => $r->user_id, 'store_id' => $r->id, 'name' => 'New', 'email' => Str::random(6).'@t.test', 'password' => 'x', 'role' => 'cashier', 'active' => 1]);
        $this->assertSame(4, $get()->json('version'), 'a staff change is a new version');
        $item->update(['price' => 10.5]);
        $this->assertSame(5, $get()->json('version'), 'going back to an old price is still a NEW version (monotonic)');

        $service = app(OfflineSnapshot::class);
        $this->assertSame(1050, collect(json_decode((string) $service->version($r->id, $branch->id, 1)?->payload, true)['catalogue']['items'])->firstWhere('id', $item->id)['price_minor'],
            'an older version is kept for replay');
        $this->assertSame(1200, collect(json_decode((string) $service->version($r->id, $branch->id, 2)?->payload, true)['catalogue']['items'])->firstWhere('id', $item->id)['price_minor']);
    }

    public function test_branch_prices_and_versions_are_per_branch(): void
    {
        [$r, $staff, $item, $branch] = $this->make('Dish', 10);
        $second = Branch::create(['store_id' => $r->id, 'name' => 'Second', 'active' => 1, 'accepting_orders' => 1, 'timezone' => 'UTC']);
        BranchMenu::create(['menu_id' => $item->id, 'branch_id' => $second->id, 'price' => 11.25]);

        $a = $this->actingAs($staff, 'staff')->getJson(route('pos.offline.snapshot', ['branch' => $branch->id]))->json();
        $b = $this->actingAs($staff, 'staff')->getJson(route('pos.offline.snapshot', ['branch' => $second->id]))->json();
        $this->assertSame(1000, $a['parts']['catalogue']['items'][0]['price_minor']);
        $this->assertSame(1125, $b['parts']['catalogue']['items'][0]['price_minor']);
        $this->assertSame([1, 1], [$a['version'], $b['version']]);
    }

    public function test_valid_until_follows_the_store_offline_hours_within_the_platform_maximum(): void
    {
        [$r, $staff, , $branch] = $this->make();
        $this->travelTo(now()->startOfMinute());
        $hours = fn () => now()->diffInHours(Carbon::parse($this->actingAs($staff, 'staff')->getJson(route('pos.offline.snapshot', ['branch' => $branch->id]))->json('valid_until')));

        $this->assertEqualsWithDelta(12, $hours(), 0.01, 'owner Q2: 12 h by default');
        StoreOption::updateStoreOption($r->id, 'pos_offline_hours', '20');
        $this->assertEqualsWithDelta(20, $hours(), 0.01);
        StoreOption::updateStoreOption($r->id, 'pos_offline_hours', '72');
        $this->assertEqualsWithDelta(24, $hours(), 0.01, 'never above the platform maximum of 24 h');
        $this->assertSame(1, PosOfflineSnapshot::query()->where('store_id', $r->id)->max('version'), 'offline hours are outside the hash');
    }

    public function test_a_snapshot_never_carries_another_stores_data_and_other_stores_are_404(): void
    {
        [$a, $staffA, , $branchA] = $this->make('Alpha dish', 10);
        [$b, $staffB, $itemB, $branchB] = $this->make('Bravo secret dish', 99);
        StoreOption::updateStoreOption($b->id, 'restaurant_taxx_charge', '7');

        $doc = $this->actingAs($staffA, 'staff')->getJson(route('pos.offline.snapshot', ['branch' => $branchA->id]))->assertOk()->json();
        $json = (string) json_encode($doc);
        foreach ([$b->title, 'Bravo secret dish', $staffB->name, 'Mains '.$b->title, '9900'] as $needle) {
            $this->assertStringNotContainsString($needle, $json, "nothing of store B ($needle) in A's snapshot");
        }
        $this->assertNotContains($itemB->id, array_column($doc['parts']['catalogue']['items'], 'id'));
        $this->assertNotContains($staffB->id, array_column($doc['parts']['staff'], 'id'));
        $this->assertSame(['mode' => 'none'], $doc['parts']['taxes']);

        // A's cashier asking for B's branch, or for a branch that does not exist, gets 404 and nothing is stored for B.
        $this->actingAs($staffA, 'staff')->getJson(route('pos.offline.snapshot', ['branch' => $branchB->id]))->assertNotFound();
        $this->actingAs($staffA, 'staff')->getJson(route('pos.offline.snapshot', ['branch' => 999999]))->assertNotFound();
        $this->assertSame(0, PosOfflineSnapshot::query()->withoutGlobalScopes()->where('store_id', $b->id)->count());

        // A cashier tied to one branch cannot fetch another branch of the same store.
        $other = Branch::create(['store_id' => $a->id, 'name' => 'Other', 'active' => 1, 'accepting_orders' => 1, 'timezone' => 'UTC']);
        $staffA->forceFill(['branch_id' => $branchA->id])->save();
        $this->actingAs($staffA, 'staff')->getJson(route('pos.offline.snapshot', ['branch' => $other->id]))->assertNotFound();

        // The stored rows carry the store's account.
        $this->assertSame((int) $a->account_id, (int) PosOfflineSnapshot::query()->withoutGlobalScopes()->where('store_id', $a->id)->value('account_id'));
    }

    public function test_versions_older_than_30_days_are_pruned_but_the_latest_stays(): void
    {
        [$r, $staff, $item, $branch] = $this->make();
        $service = app(OfflineSnapshot::class);
        $service->current($r, $branch->id);
        $this->travel(31)->days();
        $service->current($r, $branch->id); // unchanged: no new version, nothing pruned
        $this->assertNotNull($service->version($r->id, $branch->id, 1), 'the latest version is never pruned');
        $item->update(['price' => 13]);
        $service->current($r, $branch->id);
        $this->assertNull($service->version($r->id, $branch->id, 1));
        $this->assertNotNull($service->version($r->id, $branch->id, 2));
    }

    public function test_the_snapshot_needs_pos_access(): void
    {
        [, $staff, , $branch] = $this->make();
        $staff->forceFill(['role' => 'kitchen', 'permissions' => ['pos' => false]])->save();
        $this->actingAs($staff, 'staff')->getJson(route('pos.offline.snapshot', ['branch' => $branch->id]))->assertForbidden();
        auth('staff')->logout();
        $this->getJson(route('pos.offline.snapshot'))->assertUnauthorized();
    }
}
