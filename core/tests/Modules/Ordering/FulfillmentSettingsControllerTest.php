<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ordering\Services\Fulfillment\FulfillmentSettingsService;
use Tests\TestCase;

/**
 * rev/multi-vertical B1 — fulfillment mode selection per store.
 *
 * A store's owner toggles which of its vertical's allowed fulfillment modes
 * (FulfillmentRegistry->modesFor(store_type)) are switched on. Disallowed
 * modes (outside the vertical's ceiling) can never be enabled, even by a
 * tampered form field.
 */
class FulfillmentSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function retailStore(): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'retail']);

        return [$owner, $store];
    }

    public function test_edit_page_lists_the_verticals_available_modes(): void
    {
        [$owner, $store] = $this->retailStore();

        $res = $this->actingAs($owner)->get(route('stores.fulfillment.edit', ['store_id' => $store->id]));

        $res->assertOk();
        $res->assertSeeHtml('name="modes[]"');
        $res->assertSeeHtml('value="ship"');
        $res->assertSeeHtml('value="pickup_slot"');
        $res->assertSeeHtml('value="in_store"');
        // Not in retail's vertical ceiling — never rendered.
        $res->assertDontSeeHtml('value="on-table"');
        $res->assertDontSeeHtml('value="appointment"');
        // The form must post back to THIS store, not whatever the session
        // considers "current" — otherwise a multi-store owner editing store
        // B's fulfillment settings would silently save onto store A.
        $res->assertSeeHtml('store_id='.$store->id);
    }

    public function test_owner_can_enable_ship_and_pickup_slot(): void
    {
        [$owner, $store] = $this->retailStore();

        $res = $this->actingAs($owner)->put(route('stores.fulfillment.update', ['store_id' => $store->id]), [
            'modes' => ['ship', 'pickup_slot'],
        ]);

        $res->assertRedirect();
        $enabled = app(FulfillmentSettingsService::class)->enabledModes($store->fresh());
        $this->assertSame(['ship', 'pickup_slot'], $enabled);
    }

    public function test_a_mode_outside_the_vertical_ceiling_is_dropped(): void
    {
        [$owner, $store] = $this->retailStore();

        $this->actingAs($owner)->put(route('stores.fulfillment.update', ['store_id' => $store->id]), [
            // 'appointment' is a laundry mode, not retail's; 'on-table' is food-only.
            'modes' => ['ship', 'appointment', 'on-table'],
        ]);

        $enabled = app(FulfillmentSettingsService::class)->enabledModes($store->fresh());
        $this->assertSame(['ship'], $enabled);
    }

    public function test_a_store_that_never_configured_it_defaults_to_all_available_modes(): void
    {
        [, $store] = $this->retailStore();

        $enabled = app(FulfillmentSettingsService::class)->enabledModes($store);

        $this->assertSame(['ship', 'pickup_slot', 'in_store'], $enabled);
    }

    public function test_restaurant_store_never_sees_the_fulfillment_link_on_its_settings_page(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'restaurant']);

        $res = $this->actingAs($owner)->get(route('stores.edit', $store->id));

        $res->assertOk();
        $res->assertDontSee(___('Fulfillment'));
    }
}
