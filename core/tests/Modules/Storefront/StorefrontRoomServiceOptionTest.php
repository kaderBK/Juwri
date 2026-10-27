<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * The storefront half of the room-service contract.
 *
 * submitOrder only resolves the guest's room, writes service_location and evaluates the
 * charge-to-room `tender` inside its `ordering-type == 'room_service'` branch. For a
 * long time NO template could produce that type — 0 hits for `room_service` across all
 * 47 templates — so the branch was dead code from the storefront's point of view and
 * charge-to-room silently never worked.
 *
 * StoreController now computes `$allow_room_service` and passes it to every template,
 * and the templates render a Room service option behind it. This class pins the gate:
 * the option must appear for a guest who actually scanned a room QR at a hotel-mode
 * store, and must NOT leak anywhere else. The negative cases matter most — a room
 * service button on an ordinary restaurant's table QR would be a visible regression.
 */
class StorefrontRoomServiceOptionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every theme that must offer the option, with the exact marker its control
     * renders. The themes speak three dialects for the same thing: `data-ot`
     * (most), `data-type` (aurora, vivid, feast, velvet), and a theme-local name
     * mapped to the server value at submit time (picnic, scoop, which emit
     * `roomservice` and translate it to `room_service` in JS).
     *
     * An earlier version of this test asserted on the visible label "Room service"
     * instead, on the theory that one marker covering all dialects was simpler.
     * It was WRONG and passed for the wrong reason: dough, picnic, orbit and crumb
     * emit their room-service label into an inline JS config block that renders
     * unconditionally, so the string is present even when the option is not
     * offered. The per-theme negative below is what caught it. Assert on the
     * control, not on a string that happens to appear nearby.
     */
    public static function themes(): array
    {
        return [
            'crave' => ['crave',  'data-ot="room_service"'],
            'matcha' => ['matcha', 'data-ot="room_service"'],
            'glow' => ['glow',   'data-ot="room_service"'],
            'aurora' => ['aurora', 'data-type="room_service"'],
            'vivid' => ['vivid',  'data-type="room_service"'],
            'feast' => ['feast',  'data-type="room_service"'],
            'velvet' => ['velvet', 'data-type="room_service"'],
            'dough' => ['dough',  'data-ot="room_service"'],
            'plate' => ['plate',  'data-ot="room_service"'],
            'lime' => ['lime',   'data-ot="room_service"'],
            'picnic' => ['picnic', 'data-ot="roomservice"'],
            'orbit' => ['orbit',  'data-ot="room_service"'],
            'velora' => ['velora', 'data-ot="room_service"'],
            'crumb' => ['crumb',  'data-ot="room_service"'],
            'soiree' => ['soiree', 'data-ot="room_service"'],
            'scoop' => ['scoop',  'data-ot="roomservice"'],
            'ember' => ['ember',  'data-ot="room_service"'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        /* Storefront templates extend a layout that reads $settings/$activeTheme from a
           view()->composer which ViewServiceProvider only registers when APP_INSTALLED is
           set — and phpunit.xml deliberately leaves it unset. Rendering a template
           therefore dies on "Undefined variable $settings" unless the composer's output is
           shared by hand. Same stub ThemeFollowButtonGateTest uses. */
        $this->shareLayoutGlobals();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    /**
     * A store on a hotel-entitled plan. $hotelMode toggles the owner-side switch;
     * HotelService::enabled() requires BOTH it and the plan entitlement.
     *
     * Traps: the plan entitlement must be the STRING '1' (Entitlements compares
     * (string) $value === '1'), and table_qr must be on or the controller never
     * resolves ?table= at all — which would make every case here silently negative.
     */
    private function store(bool $hotelMode = true, string $theme = 'crave'): Store
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
            'hotel_mode' => $hotelMode ? '1' : '0',
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

    private function area(Store $store, string $areaType, int $no): StoreTable
    {
        return StoreTable::create([
            'store_id' => $store->id, 'table_no' => $no, 'key' => Str::random(10),
            'seats' => 2, 'area_type' => $areaType,
        ]);
    }

    // ------------------------------------------------------------------ positive
    #[DataProvider('themes')]
    public function test_room_qr_at_a_hotel_store_offers_the_room_service_option(string $theme, string $marker): void
    {
        $store = $this->store(theme: $theme);
        $room = $this->area($store, 'room', 101);

        $this->get('/'.$store->slug.'?table='.$room->key)->assertOk()->assertSeeHtml($marker);
    }

    // ------------------------------------------------------------------ negatives
    /**
     * The discriminating half, and the reason it runs per theme rather than once.
     *
     * Several themes render an order-type label map into an inline JS config block,
     * which is emitted unconditionally. If a theme puts its "Room service" label
     * there, the positive test above would pass on a string that is present even
     * when the option is not offered — green for the wrong reason. Running the
     * negative across every theme is what proves the label tracks the gate.
     */
    #[DataProvider('themes')]
    public function test_the_same_store_without_a_table_param_does_not_offer_it(string $theme, string $marker): void
    {
        $store = $this->store(theme: $theme);
        $this->area($store, 'room', 101);

        // Walk-in with no QR: there is no room to serve, so the option must not show.
        $this->get('/'.$store->slug)->assertOk()->assertDontSeeHtml($marker);
    }

    public function test_an_ordinary_table_qr_at_a_hotel_store_does_not_offer_it(): void
    {
        $store = $this->store();
        $table = $this->area($store, 'table', 7);

        // A hotel with a restaurant floor: sitting at table 7 is not room service.
        $this->get('/'.$store->slug.'?table='.$table->key)->assertOk()->assertDontSeeHtml('data-ot="room_service"');
    }

    public function test_a_room_qr_with_hotel_mode_off_does_not_offer_it(): void
    {
        $store = $this->store(hotelMode: false);
        $room = $this->area($store, 'room', 101);

        // Entitlement alone is not enough; the owner switch gates it too.
        $this->get('/'.$store->slug.'?table='.$room->key)->assertOk()->assertDontSeeHtml('data-ot="room_service"');
    }
}
