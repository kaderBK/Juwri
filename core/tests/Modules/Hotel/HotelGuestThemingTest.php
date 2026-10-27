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
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * L0.7 — guard tests for the hotel guest-layer theming pack (SPEC.md §6 constraints).
 *
 * Three server-side contracts that can regress silently:
 *  (a) F6 — a Blade layout directive (@yield/@section/@include/@extends) inside a
 *      <script>/<style> block compiles even inside a comment and dumps the block as
 *      page text (it did, on 12 themes). This scans every storefront partial.
 *  (b) The room-QR storefront (HTTP GET, through the controller — not a blade-direct
 *      render, which bypasses the controller→blade payload) surfaces the room chip and
 *      the hub dialog, keeps the forwarder hidden inputs to exactly one each, and gives
 *      every inline style/script the layer emits a CSP nonce.
 *  (c) The forwarder resolves each field by the FIRST [name=] match, so the hotel
 *      hidden inputs must be that first match in document order.
 *
 * Locale pinned to en: the assertions are on ids/attributes, but the store copy and any
 * ___()-translated markers must read as English.
 */
class HotelGuestThemingTest extends TestCase
{
    use RefreshDatabase;

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

    /** (a) F6 regression — no layout directive inside any partial's script/style blocks. */
    public function test_no_layout_directive_inside_partial_script_or_style_blocks(): void
    {
        $offenders = [];
        foreach (glob(resource_path('views/store_templates/_partials').'/*.blade.php') as $file) {
            $src = file_get_contents($file);
            if (! preg_match_all('/<(script|style)\b[^>]*>(.*?)<\/\1>/is', $src, $blocks)) {
                continue;
            }
            foreach ($blocks[2] as $block) {
                if (preg_match('/@(yield|section|extends|include(If|When|First|Unless)?)\b/', $block, $hit)) {
                    $offenders[] = basename($file).' → '.$hit[0];
                }
            }
        }

        $this->assertSame([], $offenders,
            'A Blade layout directive inside a <script>/<style> block compiles even in comments (F6) and '
            .'dumps the block as page text. Offenders: '.implode('; ', $offenders));
    }

    /** (b) HTTP GET through the controller: chip + hub + single hidden inputs + nonces. */
    public function test_room_qr_storefront_renders_chip_hub_hidden_inputs_and_nonces(): void
    {
        [$store, $room] = $this->hotelRoomStore(withStay: true);

        $html = $this->get('/'.$store->slug.'?table='.$room->key)
            ->assertOk()
            ->getContent();

        // Room chip — the single entry surface.
        $this->assertStringContainsString('id="mbHotelChip"', $html, 'the room chip must render');

        // Hub dialog with its a11y attributes.
        $this->assertMatchesRegularExpression('/id="mbHotelHub"[^>]*\brole="dialog"/', $html, 'the hub must be a role=dialog');
        $this->assertStringContainsString('aria-modal="true"', $html, 'the hub must be aria-modal');
        $this->assertStringContainsString('aria-labelledby="mbHotelHubTitle"', $html, 'the hub must be labelled by its title');
        $this->assertStringContainsString('id="mbHotelSvcForm"', $html, 'the services form must render in the hub');
        $this->assertStringContainsString(route('hotel.guest.request', $store->slug), $html, 'the services form must post to the public endpoint');

        // Forwarder hidden inputs present EXACTLY once each.
        $this->assertSame(1, substr_count($html, 'name="room"'), 'the room hidden input must appear exactly once');
        $this->assertSame(1, substr_count($html, 'name="tender"'), 'the tender hidden input must appear exactly once');

        // Every inline style/script the hotel layer emits must carry a non-empty CSP nonce.
        preg_match_all('/<(script|style)\b([^>]*)>(.*?)<\/\1>/is', $html, $blocks, PREG_SET_ORDER);
        $checked = 0;
        foreach ($blocks as [$whole, $tag, $attrs, $body]) {
            if (stripos($attrs, ' src=') !== false) {
                continue; // external asset — carries no inline nonce
            }
            if (strpos($body, 'mbHotel') === false) {
                continue; // not one of the hotel layer's blocks
            }
            $this->assertMatchesRegularExpression('/\bnonce="[^"]+"/', $attrs,
                "a hotel <$tag> block is missing its CSP nonce");
            $checked++;
        }
        $this->assertGreaterThanOrEqual(2, $checked, 'expected the hotel layer to emit at least one nonce-bearing style and script');
    }

    /** (c) Forwarder contract — the hotel hidden inputs are the FIRST [name=] match. */
    public function test_forwarder_hidden_inputs_are_the_first_name_match(): void
    {
        [$store, $room] = $this->hotelRoomStore(withStay: true);

        $html = $this->get('/'.$store->slug.'?table='.$room->key)
            ->assertOk()
            ->getContent();

        // The first element named `room` must be the hotel hidden input.
        $this->assertSame(1, preg_match('/<input\b[^>]*\bname="room"[^>]*>/', $html, $firstRoom));
        $this->assertStringContainsString('id="mbHotelRoomKey"', $firstRoom[0],
            'the first [name=room] element must be the hotel hidden input (forwarder reads the first match)');

        // The first element named `tender` must be the hotel hidden input.
        $this->assertSame(1, preg_match('/<input\b[^>]*\bname="tender"[^>]*>/', $html, $firstTender));
        $this->assertStringContainsString('id="mbHotelTender"', $firstTender[0],
            'the first [name=tender] element must be the hotel hidden input');
    }

    /**
     * A hotel-mode store on a kept theme, with one room reachable by QR and (optionally)
     * an active stay so the charge affordance renders. Mirrors HotelGuestLayerMatrixTest.
     *
     * @return array{0: Store, 1: StoreTable}
     */
    private function hotelRoomStore(bool $withStay): array
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
            'store_template' => 'crave',
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

        $room = StoreTable::create([
            'store_id' => $store->id, 'table_no' => 204, 'key' => Str::random(10),
            'seats' => 2, 'area_type' => 'room', 'label' => 'Room 204',
        ]);

        if ($withStay) {
            HotelFolioService::checkIn($store->id, $room, 'Sam Carter', '555-1');
        }

        return [$store, $room];
    }
}
