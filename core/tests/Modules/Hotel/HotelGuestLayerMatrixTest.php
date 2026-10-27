<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * C1 — the hotel guest layer must actually render, and be reachable, on EVERY modern
 * theme. The audit found the partial painted UNDER the fixed app shell on 12 of 17
 * themes: server-rendered in body flow, never relocated. The theming pack renders the
 * room chip (into the theme header, or placeTop), opens a hub dialog, and relocates the
 * order-flow controls into the checkout (mbAnchor.placeCheckout) at runtime — a browser
 * concern the orchestrator eyeballs post-merge.
 *
 * This class pins the SERVER-SIDE half of that contract, which is what can regress
 * silently in CI: for a room-QR visit at a hotel-mode store with an active stay, every
 * theme must render the layer's markers inside the document, the charge affordance must
 * be present (an active stay is checked in), and the relocatable containers must carry
 * no inline display:none / zero-height trap that would defeat the JS placement.
 */
class HotelGuestLayerMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The 17 modern themes the guest layer ships across — built from the ONE
     * canonical keep-list (KeptTemplates::MODERN), never hard-copied here.
     * KeptTemplateRegistryTest fails any file that grows its own copy of the 17.
     */
    public static function themes(): array
    {
        $sets = [];
        foreach (KeptTemplates::MODERN as $slug) {
            $sets[$slug] = [$slug];
        }

        return $sets;
    }

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function store(string $theme): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => [
                'restaurant_limit' => 5, 'menu_limit' => 999, 'table_qr' => '1',
                'hotel' => '1', 'allow_ordering' => '1',
            ],
            'status' => 1,
        ]);
        $owner = User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
        ]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'x', 'description' => 'x',
            'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => '1 St', 'phone' => '1',
        ]);
        foreach ([
            'store_template' => $theme,
            'restaurant_on_table_order' => '1',
            'hotel_mode' => '1',
        ] as $name => $value) {
            StoreOption::updateStoreOption($store->id, $name, $value);
        }
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1']);
        Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id,
            'name' => 'Club Sandwich', 'price' => 42.00, 'active' => '1',
        ]);

        return $store;
    }

    private function room(Store $store): StoreTable
    {
        return StoreTable::create([
            'store_id' => $store->id, 'table_no' => 204, 'key' => Str::random(10),
            'seats' => 2, 'area_type' => 'room', 'label' => 'Room 204',
        ]);
    }

    #[DataProvider('themes')]
    public function test_guest_layer_renders_for_a_room_visit_with_an_active_stay(string $theme): void
    {
        $store = $this->store($theme);
        $room = $this->room($store);
        HotelFolioService::checkIn($store->id, $room, 'Sam Carter', '555-1');

        $html = $this->get('/'.$store->slug.'?table='.$room->key)
            ->assertOk()
            ->getContent();

        // The entry chip, the hub dialog and the services form must all be present.
        $this->assertStringContainsString('id="mbHotelChip"', $html, "[$theme] the room chip must render");
        $this->assertStringContainsString('id="mbHotelHub"', $html, "[$theme] the hotel hub must render");
        $this->assertStringContainsString('id="mbHotelSvcForm"', $html, "[$theme] the services sheet must render");
        $this->assertStringContainsString(route('hotel.guest.request', $store->slug), $html, "[$theme] the services form must post to the public endpoint");

        // The charge-to-room affordance must be present because a stay is active.
        $this->assertStringContainsString('id="mbHotelChargeToggle"', $html, "[$theme] the charge-to-room toggle must render for an active stay");

        // The hidden tender/room inputs must stay in body flow for the field-forwarder.
        $this->assertStringContainsString('id="mbHotelTender"', $html, "[$theme] the hidden tender field must render");
        $this->assertStringContainsString('id="mbHotelRoomKey"', $html, "[$theme] the hidden room field must render");

        // No inline display:none / zero-height trap on the relocatable containers.
        foreach (['mbHotelChip', 'mbHotelOrderCtrls', 'mbHotelCharge'] as $relocatable) {
            $this->assertDoesNotMatchRegularExpression(
                '/id="'.$relocatable.'"[^>]*style="[^"]*(display:\s*none|visibility:\s*hidden|height:\s*0)/i',
                $html,
                "[$theme] #$relocatable must not be inline-hidden"
            );
        }

        // The layer must live in the body, not stranded in <head>.
        $bodyPos = stripos($html, '<body');
        $this->assertNotFalse($bodyPos, "[$theme] the document must have a body");
        $this->assertGreaterThan($bodyPos, stripos($html, 'id="mbHotelChip"'), "[$theme] the room chip must render inside the body");
    }

    /** A vacant room (no stay) still renders the layer but WITHOUT the charge affordance. */
    public function test_vacant_room_renders_the_layer_without_the_charge_toggle(): void
    {
        $store = $this->store('crave');
        $room = $this->room($store);
        // No check-in.

        $html = $this->get('/'.$store->slug.'?table='.$room->key)->assertOk()->getContent();

        $this->assertStringContainsString('id="mbHotelChip"', $html);
        $this->assertStringNotContainsString('id="mbHotelChargeToggle"', $html, 'a vacant room must not offer charge-to-room');
    }
}
