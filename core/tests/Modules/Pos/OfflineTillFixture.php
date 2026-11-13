<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Models\MenuCategory;
use App\Models\StaffMember;
use App\Models\StoreOption;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Pos\Pricing\TillCalculator;
use Juwri\Modules\Pos\Services\Offline\OfflineDevices;
use Tests\Support\TestPlans;

/** Shared set-up for the offline POS replay tests: a store, its till staff, a snapshot version, record builders. */
trait OfflineTillFixture
{
    protected const DEVICE = 'till-0001-abcdef';

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    /** @return array{store: Store, cashier: StaffMember, manager: StaffMember, item: Menu, branch: Branch, version: int} */
    protected function make(float $price = 10.5, string $tax = '15'): array
    {
        $plan = TestPlans::create(['name' => 'P '.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $store = Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => $title,
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'SAR');
        StoreOption::updateStoreOption($store->id, 'restaurant_taxx_charge', $tax);
        StoreOption::updateStoreOption($store->id, 'restaurant_tax_type', 'percentage');
        $branch = Branch::create(['store_id' => $store->id, 'name' => 'Main', 'active' => 1, 'accepting_orders' => 1, 'timezone' => 'UTC']);
        $mk = fn (string $role) => StaffMember::create(['owner_id' => $owner->id, 'store_id' => $store->id, 'name' => ucfirst($role).' '.$title,
            'email' => Str::random(8).'@t.test', 'password' => bcrypt('x'), 'role' => $role, 'active' => 1]);
        $cashier = $mk('cashier');
        $manager = $mk('manager');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => 1]);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Kabsa', 'price' => $price, 'active' => 1, 'track_inventory' => 1, 'stock_qty' => 5]);
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $version = $this->asStaff($cashier)->getJson(route('pos.offline.snapshot', ['branch' => $branch->id]))->assertOk()->json('version');

        return compact('store', 'cashier', 'manager', 'item', 'branch', 'version');
    }

    protected static function key(): string
    {
        return (string) Str::uuid7();
    }

    /**
     * A sale record whose declared totals are what the till calculator gives (as the till computes them).
     *
     * @param  array<string, mixed>  $t
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function sale(array $t, array $lines, array $extra = [], ?string $key = null, ?int $staffId = null, string $rate = '15'): array
    {
        $calc = TillCalculator::price(['lines' => array_map(fn ($l) => ['unit_minor' => $l['unit_minor'], 'qty' => $l['qty'],
            'extras_minor' => array_column($l['extras'] ?? [], 'price_minor'), 'options_minor' => array_column($l['options'] ?? [], 'price_minor')], $lines),
            'tax' => ['mode' => 'percentage', 'rate' => $rate], 'discount' => $extra['discount'] ?? ['mode' => 'none'],
            'service_minor' => $extra['service_minor'] ?? 0, 'tip_minor' => $extra['tip_minor'] ?? 0,
            'tax_base' => $extra['tax_base'] ?? 'net']); // P6-271: 'gross' = a till still on the old calculator

        return ['type' => 'sale', 'key' => $key ?? self::key(), 'seq' => ++$this->seq, 'staff_id' => $staffId ?? $t['cashier']->id,
            'snapshot_version' => $t['version'], 'created_at' => now()->subHour()->toIso8601ZuluString(),
            'payload' => ['order_type' => 'takeaway', 'lines' => $lines, 'totals' => $calc] + $extra];
    }

    /** @return array<string, mixed> */
    protected function rec(array $t, string $type, array $payload, ?int $staffId = null): array
    {
        return ['type' => $type, 'key' => self::key(), 'seq' => ++$this->seq, 'staff_id' => $staffId ?? $t['cashier']->id, 'snapshot_version' => $t['version'],
            'created_at' => now()->subMinutes(30)->toIso8601ZuluString(), 'payload' => $payload];
    }

    /** @param  list<array<string, mixed>>  $records */
    protected function replay(array $t, array $records, ?StaffMember $as = null, array $extra = []): TestResponse
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $body = $extra + ['device_id' => self::DEVICE, 'device_clock' => now()->toIso8601ZuluString(), 'branch_id' => $t['branch']->id, 'records' => $records];

        return $this->actingAs($as ?? $t['cashier'], 'staff')->withHeaders($this->till($t, (string) $body['device_id']))->postJson(route('pos.offline.replay'), $body);
    }

    /** @var array<string, string> */
    private array $tillTokens = [];

    /**
     * The device headers of a registered till of this store (registered on first use).
     *
     * @return array<string, string>
     */
    protected function till(array $t, string $deviceId): array
    {
        $k = $t['store']->id.':'.$deviceId;
        if (! isset($this->tillTokens[$k])) {
            $this->tillTokens[$k] = app(OfflineDevices::class)->register($t['store'], null, (int) $t['branch']->id, $deviceId, null, 'web')[1];
        }

        return ['X-Pos-Device' => $deviceId, 'X-Pos-Device-Token' => $this->tillTokens[$k]];
    }

    /** A fresh staff session (the staff guard keeps the previous member's session otherwise). */
    protected function asStaff(StaffMember $staff): static
    {
        $this->flushHeaders();
        $this->flushHeaders();
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($staff, 'staff');
    }
}
