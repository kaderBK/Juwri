<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\StorefrontToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Customers\Models\CustomerToken;
use Juwri\Modules\Hotel\Models\HotelBooking;
use Juwri\Modules\Hotel\Models\RoomType;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\StarterMenuService;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * HPMS §8 P2 — the ADDITIVE MCP manifest extensions for this run's shipped
 * features. Each server keeps its prior tools (pinned by the existing Mcp*Test
 * suites) and gains ONE new read-only tool that resolves:
 *   /mcp/dev       → list_webhook_events  (public event catalog, code-sourced)
 *   /mcp/catalog   → list_starter_menus   (bundled starter presets)
 *   /mcp/customer  → my_bookings          (the diner's own hotel bookings)
 *   /mcp/storefront→ hotel_availability   (room availability for a date range)
 *
 * The contract is: no existing tool renamed/removed, new tools listed AND
 * callable, and the isolation/gating rules of each server preserved.
 */
class HotelPmsMcpAdditionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    // ------------------------------------------------------------- helpers

    private function toolNames($res): array
    {
        return array_column((array) $res->json('result.tools'), 'name');
    }

    private function text($res): ?string
    {
        return $res->json('result.content.0.text');
    }

    private function hotelPlanOwner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'hotel' => '1', 'storefront_api' => '1', 'agent_api' => '1'],
            'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    /** An owner whose plan grants the storefront + agent API (no hotel). */
    private function agentPlanOwner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'storefront_api' => '1', 'agent_api' => '1'],
            'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function store(?User $owner = null): Store
    {
        $owner ??= User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => 1]);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Pizza', 'price' => 12, 'active' => 1]);

        return $store;
    }

    private function hotelStore(string $mode = 'instant'): Store
    {
        $store = $this->store($this->hotelPlanOwner());
        StoreSettings::set($store->id, 'hotel_mode', '1');
        StoreSettings::set($store->id, 'hotel_online_booking', $mode);

        return $store;
    }

    // ---------------------------------------------------- /mcp/dev

    public function test_dev_manifest_keeps_prior_tools_and_adds_list_webhook_events(): void
    {
        $names = $this->toolNames(
            $this->postJson('/mcp/dev', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertOk()
        );

        // prior tools survive
        foreach (['learn_platform', 'search_docs', 'validate_theme', 'fetch_full_doc'] as $prior) {
            $this->assertContains($prior, $names, "prior dev tool {$prior} must remain");
        }
        // new tool present
        $this->assertContains('list_webhook_events', $names);
    }

    public function test_dev_list_webhook_events_resolves_with_the_new_events(): void
    {
        $text = $this->text(
            $this->postJson('/mcp/dev', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'list_webhook_events']])->assertOk()
        );

        // this run's 6 new lifecycle events are all listed
        foreach ([
            'refund.completed', 'reservation.created', 'reservation.cancelled',
            'customer.created', 'shift.opened', 'shift.closed',
        ] as $event) {
            $this->assertStringContainsString($event, (string) $text, "webhook event {$event} must be listed");
        }
        // and the always-present names
        $this->assertStringContainsString('order.created', (string) $text);
        $this->assertStringContainsString('ping', (string) $text);
    }

    // ---------------------------------------------------- /mcp/catalog

    public function test_catalog_manifest_keeps_prior_tools_and_adds_list_starter_menus(): void
    {
        $names = $this->toolNames(
            $this->postJson('/mcp/catalog', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'])->assertOk()
        );

        foreach (['search_stores', 'search_items', 'get_store'] as $prior) {
            $this->assertContains($prior, $names, "prior catalog tool {$prior} must remain");
        }
        $this->assertContains('list_starter_menus', $names);
    }

    public function test_catalog_list_starter_menus_resolves_with_the_bundled_presets(): void
    {
        $out = json_decode((string) $this->text(
            $this->postJson('/mcp/catalog', ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'list_starter_menus']])->assertOk()
        ), true);

        $keys = array_column($out['presets'], 'key');
        // the whitelist (StarterMenuService::PRESETS) — each with a preview
        foreach (StarterMenuService::PRESETS as $preset) {
            $this->assertContains($preset, $keys, "starter preset {$preset} must be listed");
        }
        $first = $out['presets'][0];
        $this->assertArrayHasKey('label', $first);
        $this->assertArrayHasKey('categories', $first);
        $this->assertNotEmpty($first['categories']);
        $this->assertArrayHasKey('items', $first['categories'][0]);
    }

    // ---------------------------------------------------- /mcp/customer

    /** @return array{0:Customer,1:string} */
    private function customer(Store $store, string $name = 'Dana'): array
    {
        $c = Customer::create(['store_id' => $store->id, 'name' => $name, 'phone' => UniquePhone::next('5')]);

        return [$c, CustomerToken::issue($c)];
    }

    private function customerRpc(string $raw, array $body)
    {
        return $this->postJson(route('mcp.customer'), $body, ['Authorization' => 'Bearer '.$raw]);
    }

    public function test_customer_manifest_keeps_prior_tools_and_adds_my_bookings(): void
    {
        $store = $this->store();
        [, $raw] = $this->customer($store);

        $names = $this->toolNames(
            $this->customerRpc($raw, ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/list'])->assertOk()
        );

        foreach (['my_orders', 'order_detail', 'track_order', 'reorder', 'my_profile'] as $prior) {
            $this->assertContains($prior, $names, "prior customer tool {$prior} must remain");
        }
        $this->assertContains('my_bookings', $names);
    }

    public function test_customer_my_bookings_returns_only_the_callers_bookings(): void
    {
        $store = $this->hotelStore();
        $type = RoomType::create(['store_id' => $store->id, 'name' => 'Deluxe', 'base_rate' => 100, 'active' => true]);

        [$mine, $raw] = $this->customer($store, 'Mine');
        [$other] = $this->customer($store, 'Other');

        HotelBooking::create([
            'store_id' => $store->id, 'customer_id' => $mine->id, 'code' => 'MINE01',
            'guest_name' => 'Mine', 'room_type_id' => $type->id,
            'check_in' => '2026-09-01', 'check_out' => '2026-09-03',
            'adults' => 2, 'status' => HotelBooking::STATUS_CONFIRMED,
            'quoted_total' => 200, 'currency' => 'USD',
        ]);
        HotelBooking::create([
            'store_id' => $store->id, 'customer_id' => $other->id, 'code' => 'OTHER1',
            'guest_name' => 'Other', 'room_type_id' => $type->id,
            'check_in' => '2026-09-05', 'check_out' => '2026-09-06',
            'adults' => 1, 'status' => HotelBooking::STATUS_PENDING,
        ]);

        $rows = json_decode((string) $this->text(
            $this->customerRpc($raw, ['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => ['name' => 'my_bookings']])->assertOk()
        ), true);

        $codes = array_column($rows, 'code');
        $this->assertContains('MINE01', $codes);
        $this->assertNotContains('OTHER1', $codes, 'a diner must never see another guest booking');

        $row = $rows[0];
        $this->assertSame('Deluxe', $row['room_type']);
        $this->assertSame(2, $row['nights']);
        $this->assertEquals(200, $row['quoted_total']);
        // the guest manage-link bearer token is never serialised
        $this->assertArrayNotHasKey('token', $row);
    }

    public function test_customer_my_bookings_is_empty_for_a_non_hotel_venue(): void
    {
        $store = $this->store();
        [, $raw] = $this->customer($store);

        $rows = json_decode((string) $this->text(
            $this->customerRpc($raw, ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'my_bookings']])->assertOk()
        ), true);

        $this->assertSame([], $rows);
    }

    // ---------------------------------------------------- /mcp/storefront

    private function agentToken(Store $store): string
    {
        $raw = Str::random(48);
        StorefrontToken::create(['store_id' => $store->id, 'token' => hash('sha256', $raw), 'scopes' => ['agent'], 'active' => true]);

        return $raw;
    }

    private function storefrontRpc(string $raw, array $body)
    {
        return $this->postJson(route('mcp.storefront'), $body, ['X-Storefront-Token' => $raw]);
    }

    public function test_storefront_manifest_keeps_prior_tools_and_adds_hotel_availability(): void
    {
        $store = $this->store($this->agentPlanOwner());
        $raw = $this->agentToken($store);

        $names = $this->toolNames(
            $this->storefrontRpc($raw, ['jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/list'])->assertOk()
        );

        foreach (['search_menu', 'create_order', 'validate_cart', 'restaurant_info'] as $prior) {
            $this->assertContains($prior, $names, "prior storefront tool {$prior} must remain");
        }
        $this->assertContains('hotel_availability', $names);
    }

    public function test_storefront_hotel_availability_disabled_for_a_non_hotel_store(): void
    {
        $store = $this->store($this->agentPlanOwner());
        $raw = $this->agentToken($store);

        $out = json_decode((string) $this->text(
            $this->storefrontRpc($raw, ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call', 'params' => ['name' => 'hotel_availability']])->assertOk()
        ), true);

        $this->assertFalse($out['enabled']);
    }

    public function test_storefront_hotel_availability_lists_room_types_for_a_bookable_hotel(): void
    {
        $store = $this->hotelStore('instant');
        RoomType::create(['store_id' => $store->id, 'name' => 'Suite', 'base_rate' => 150, 'active' => true]);
        $raw = $this->agentToken($store);

        $out = json_decode((string) $this->text(
            $this->storefrontRpc($raw, ['jsonrpc' => '2.0', 'id' => 10, 'method' => 'tools/call', 'params' => [
                'name' => 'hotel_availability',
                // Relative future dates (2 nights): the tool clamps a past check-in
                // to today, so hardcoded dates go stale once the wall clock passes them.
                'arguments' => ['check_in' => now()->addDays(10)->toDateString(), 'check_out' => now()->addDays(12)->toDateString()],
            ]])->assertOk()
        ), true);

        $this->assertTrue($out['enabled']);
        $this->assertSame('instant', $out['online_booking']);
        $this->assertSame(2, $out['nights']);
        $names = array_column($out['room_types'], 'name');
        $this->assertContains('Suite', $names);
        $card = $out['room_types'][array_search('Suite', $names, true)];
        $this->assertArrayHasKey('available', $card);
        $this->assertArrayHasKey('nightly_total', $card);
    }

    public function test_storefront_hotel_availability_off_when_online_booking_disabled(): void
    {
        // hotel module ON but the owner left online booking OFF → not bookable.
        $store = $this->store($this->hotelPlanOwner());
        StoreSettings::set($store->id, 'hotel_mode', '1');
        $raw = $this->agentToken($store);

        $out = json_decode((string) $this->text(
            $this->storefrontRpc($raw, ['jsonrpc' => '2.0', 'id' => 11, 'method' => 'tools/call', 'params' => ['name' => 'hotel_availability']])->assertOk()
        ), true);

        $this->assertFalse($out['enabled']);
        $this->assertSame('off', $out['online_booking']);
    }
}
