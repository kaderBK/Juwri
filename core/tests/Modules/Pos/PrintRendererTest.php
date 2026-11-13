<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Models\KitchenStation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosShift;
use App\Models\StoreOption;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Pos\Models\PrinterProfile;
use Juwri\Modules\Pos\Services\Printing\EscPosInspector;
use Juwri\Modules\Pos\Services\Printing\EscPosRaster;
use Juwri\Modules\Pos\Services\Printing\EscPosRenderer;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * PARITY-RUN T1-2: the generalised ESC/POS renderer — documents, widths, the
 * money contract, RTL and the logo raster.
 */
class PrintRendererTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1]);
        $this->store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Bites',
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '12 St', 'phone' => '0500', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function menu(string $name, float $price, ?int $stationId = null): Menu
    {
        return Menu::create([
            'store_id' => $this->store->id, 'name' => $name, 'price' => $price,
            'active' => '1', 'station_id' => $stationId,
        ]);
    }

    private function order(array $lines, array $attrs = []): Order
    {
        $order = Order::create(array_merge([
            'store_id' => $this->store->id, 'type' => 'takeaway', 'status' => 'new',
            'track_token' => Str::random(32), 'created_at' => now(),
        ], $attrs));

        foreach ($lines as $line) {
            OrderItem::create(array_merge(['order_id' => $order->id], $line));
        }

        return $order->load('items.menu', 'items.itemExtras.extra', 'items.variant');
    }

    // ── widths ───────────────────────────────────────────────────────────────

    public function test_width_mm_maps_to_the_column_count(): void
    {
        $this->assertSame(48, EscPosRenderer::forWidthMm(80)->columns());
        $this->assertSame(32, EscPosRenderer::forWidthMm(57)->columns());
        $this->assertSame(48, EscPosRenderer::forWidthMm(999)->columns()); // unknown → 80mm
    }

    public function test_separator_rules_match_the_paper_width(): void
    {
        $order = $this->order([['item_id' => $this->menu('Burger', 20)->id, 'quantity' => 1]]);

        $wide = EscPosRenderer::forWidthMm(80)->render($order);
        $narrow = EscPosRenderer::forWidthMm(57)->render($order);

        $this->assertStringContainsString(str_repeat('-', 48), $wide);
        $this->assertStringNotContainsString(str_repeat('-', 48), $narrow);
        $this->assertStringContainsString(str_repeat('-', 32), $narrow);
    }

    public function test_a_narrow_receipt_never_overflows_its_column_count(): void
    {
        $order = $this->order([['item_id' => $this->menu('Triple Bacon Cheeseburger Deluxe', 42.5)->id, 'quantity' => 3]]);

        $bytes = EscPosRenderer::forWidthMm(57)->render($order);

        foreach (explode("\x0A", $bytes) as $line) {
            // Strip ESC/GS control sequences before measuring the printable text.
            $text = preg_replace('/\x1B@|\x1B[Ea].|\x1DV0/', '', $line);
            $this->assertLessThanOrEqual(32, mb_strlen($text), "Line overflows 32 columns: {$text}");
        }
    }

    public function test_a_long_store_header_wraps_within_the_narrow_column(): void
    {
        // The original narrow-overflow test used the short factory store, whose
        // name and address fit 32 columns — so it never exercised the free-text
        // header path, which cols() does not width-fit. A real store with a long
        // name + address overflowed the 57mm head; caught by decoding the bytes.
        $this->store->update([
            'title' => 'The Genuinely Very Long Restaurant Name Bistro',
            'address' => '123 Flavor City, Food State, Some Long Region',
        ]);
        $order = $this->order([['item_id' => $this->menu('Tea', 5)->id, 'quantity' => 1]]);

        $bytes = EscPosRenderer::forWidthMm(57)->render($order->fresh('items.menu'));

        foreach (new EscPosInspector()->inspect($bytes)['text'] as $row) {
            $this->assertLessThanOrEqual(32, mb_strlen($row), "Header line overflows 32 columns: {$row}");
        }
    }

    public function test_the_byte_stream_is_well_formed_escpos(): void
    {
        // A structural finding from the inspector means a real printer could
        // garble the ticket — e.g. the malformed GS !0x11 constant this run
        // removed, which would have printed the literal "0x11".
        $order = $this->order([['item_id' => $this->menu('Pizza', 9)->id, 'quantity' => 2]]);

        foreach ([80, 57] as $mm) {
            $bytes = EscPosRenderer::forWidthMm($mm)->render($order);
            $findings = new EscPosInspector()->inspect($bytes)['findings'];
            $this->assertSame([], $findings, "{$mm}mm receipt has ESC/POS findings: ".json_encode($findings));
        }
    }

    // ── money contract ───────────────────────────────────────────────────────

    /**
     * The receipt must print what the server charges. Choice-group options are
     * part of Order::getTotalAttribute's subtotal, and used to be missing here —
     * so the printed lines did not add up to the printed total.
     */
    public function test_receipt_totals_reconcile_with_the_order_total(): void
    {
        StoreOption::updateStoreOption($this->store->id, 'restaurant_taxx_charge', '10');
        StoreOption::updateStoreOption($this->store->id, 'restaurant_tax_type', 'percentage');

        $order = $this->order([[
            'item_id' => $this->menu('Burger', 20)->id,
            'quantity' => 2,
            'options' => [['group_id' => 1, 'title' => 'Sauce', 'selected' => [['option' => 'Extra cheese', 'price' => 2.5]]]],
        ]]);

        $bytes = EscPosRenderer::forWidthMm(80)->render($order->fresh()->load('items.menu'));

        // subtotal = 2×20 + 2×2.5 = 45.00 ; tax = 4.50 ; total = 49.50
        $this->assertStringContainsString('45.00', $bytes);
        $this->assertStringContainsString('Extra cheese', $bytes);
        $this->assertStringContainsString('4.50', $bytes);
        $this->assertStringContainsString(number_format((float) $order->fresh()->total, 2), $bytes);
    }

    public function test_discount_is_printed_after_tax_like_the_total_accessor(): void
    {
        StoreOption::updateStoreOption($this->store->id, 'restaurant_taxx_charge', '10');
        $order = $this->order(
            [['item_id' => $this->menu('Burger', 20)->id, 'quantity' => 1]],
            ['discount_amount' => 5]
        );

        $bytes = EscPosRenderer::forWidthMm(80)->render($order->fresh()->load('items.menu'));

        $this->assertStringContainsString('-5.00', $bytes);
        // P6-271: tax is 10% of the subtotal AFTER the discount (15.00), as the order charges it.
        $this->assertMatchesRegularExpression('/Tax +1\.50 SAR/', $bytes);
        $this->assertMatchesRegularExpression('/Total +16\.50 SAR/', $bytes);

        // An order placed before P6-271 keeps its tax on the bare subtotal on a reprint.
        $order->forceFill(['tax_pre_discount' => true])->save();
        $old = EscPosRenderer::forWidthMm(80)->render($order->fresh()->load('items.menu'));
        $this->assertMatchesRegularExpression('/Tax +2\.00 SAR/', $old);
        $this->assertMatchesRegularExpression('/Total +17\.00 SAR/', $old);
    }

    public function test_tenders_are_shown_as_deductions_not_discounts(): void
    {
        $order = $this->order(
            [['item_id' => $this->menu('Burger', 20)->id, 'quantity' => 1]],
            ['wallet_applied' => 5]
        );

        $bytes = EscPosRenderer::forWidthMm(80)->render($order->fresh()->load('items.menu'));

        $this->assertStringContainsString('Wallet', $bytes);
        // The order value (Total) is untouched by the tender.
        $this->assertStringContainsString('20.00', $bytes);
    }

    // ── KOT ──────────────────────────────────────────────────────────────────

    public function test_kot_carries_only_the_stations_lines_and_no_prices(): void
    {
        $kitchen = KitchenStation::create(['store_id' => $this->store->id, 'name' => 'Kitchen']);
        $bar = KitchenStation::create(['store_id' => $this->store->id, 'name' => 'Bar']);

        $order = $this->order([
            ['item_id' => $this->menu('Burger', 20, $kitchen->id)->id, 'quantity' => 1, 'station_id' => $kitchen->id],
            ['item_id' => $this->menu('Mojito', 15, $bar->id)->id, 'quantity' => 2, 'station_id' => $bar->id],
        ]);

        $barTicket = EscPosRenderer::forWidthMm(80)->renderKot($order, [$bar->id], 'Bar');

        $this->assertStringContainsString('Mojito', $barTicket);
        $this->assertStringNotContainsString('Burger', $barTicket);
        $this->assertStringNotContainsString('15.00', $barTicket); // a cook needs no prices
        $this->assertStringContainsString('Bar', $barTicket);
        $this->assertStringContainsString("\x1DV0", $barTicket);   // cut
    }

    public function test_kot_without_a_station_filter_prints_every_line(): void
    {
        $order = $this->order([
            ['item_id' => $this->menu('Burger', 20)->id, 'quantity' => 1],
            ['item_id' => $this->menu('Fries', 8)->id, 'quantity' => 1],
        ]);

        $ticket = EscPosRenderer::forWidthMm(80)->renderKot($order);

        $this->assertStringContainsString('Burger', $ticket);
        $this->assertStringContainsString('Fries', $ticket);
    }

    public function test_kot_shows_item_notes_and_choice_options(): void
    {
        $order = $this->order([[
            'item_id' => $this->menu('Steak', 90)->id,
            'quantity' => 1,
            'order_note' => 'well done',
            'options' => [['group_id' => 1, 'title' => 'Side', 'selected' => [['option' => 'Salad', 'price' => 0]]]],
        ]]);

        $ticket = EscPosRenderer::forWidthMm(80)->renderKot($order->fresh()->load('items.menu'));

        $this->assertStringContainsString('well done', $ticket);
        $this->assertStringContainsString('Salad', $ticket);
    }

    /** Station resolution falls back to the menu's default when the line has none. */
    public function test_station_matching_falls_back_to_the_menu_default(): void
    {
        $bar = KitchenStation::create(['store_id' => $this->store->id, 'name' => 'Bar']);
        $order = $this->order([['item_id' => $this->menu('Mojito', 15, $bar->id)->id, 'quantity' => 1]]);

        $items = EscPosRenderer::forWidthMm(80)->itemsForStations($order, [$bar->id]);

        $this->assertCount(1, $items);
    }

    // ── shift summary ────────────────────────────────────────────────────────

    public function test_shift_summary_prints_the_zreport_numbers(): void
    {
        $shift = PosShift::create([
            'store_id' => $this->store->id, 'branch_id' => null, 'staff_id' => 0, 'status' => 'closed',
            'opened_at' => now()->subHours(8), 'closed_at' => now(), 'opening_float' => 100,
        ]);
        $z = PosService::zReport($shift);
        $z['counted_cash'] = 260.0;
        $z['over_short'] = -5.0;

        $bytes = EscPosRenderer::forWidthMm(80)->renderShiftSummary($shift, $z);

        $this->assertStringStartsWith("\x1B@", $bytes);
        $this->assertStringContainsString('Shift report', $bytes);
        $this->assertStringContainsString('100.00', $bytes);
        $this->assertStringContainsString('260.00', $bytes);
        $this->assertStringContainsString('-5.00', $bytes);
    }

    // ── test page ────────────────────────────────────────────────────────────

    public function test_test_page_names_the_printer_and_proves_the_column_count(): void
    {
        $bytes = EscPosRenderer::forWidthMm(57)->renderTest($this->store, 'Bar printer');

        $this->assertStringContainsString('Bar printer', $bytes);
        $this->assertStringContainsString('Bites', $bytes);
        // The ruler line is exactly the column count, so mis-set paper is obvious.
        $this->assertStringContainsString(substr(str_repeat('1234567890', 6), 0, 32), $bytes);
    }

    // ── RTL ──────────────────────────────────────────────────────────────────

    /** Without a font, the RTL lane still shapes the text into presentation forms. */
    public function test_rtl_text_lane_emits_shaped_presentation_forms(): void
    {
        $arabic = Store::find($this->store->id);
        $arabic->title = 'بيت';
        $arabic->save();

        $bytes = EscPosRenderer::forWidthMm(80)->render(
            $this->order([['item_id' => $this->menu('Burger', 20)->id, 'quantity' => 1]])
        );

        $this->assertStringNotContainsString('بيت', $bytes);                 // not raw logical order
        $this->assertStringContainsString("\u{FE96}\u{FEF4}\u{FE91}", $bytes); // shaped + reordered
        $this->assertStringContainsString("\x1Ba2", $bytes);                  // right-aligned
    }

    /** With a TTF configured, RTL lines become raster strips instead of characters. */
    public function test_rtl_raster_lane_emits_gs_v_0_bitmap_bytes(): void
    {
        if (! EscPosRaster::available()) {
            $this->markTestSkipped('GD is not available in this PHP.');
        }

        $font = $this->fakeTtfPath();
        $renderer = EscPosRenderer::forWidthMm(80)->rtl(true)->font($font);

        // The font path is not a real TTF, so imagettfbbox fails and the renderer
        // must fall back to the text lane rather than emitting nothing.
        $bytes = $renderer->render($this->order([['item_id' => $this->menu('Burger', 20)->id, 'quantity' => 1]]));
        $this->assertNotSame('', $bytes);

        // The raster encoder itself is proved directly with GD's built-in font.
        $raster = EscPosRaster::textLine('TOTAL', 576);
        $this->assertNotNull($raster);
        $this->assertStringStartsWith("\x1Dv0\x00", $raster);
    }

    public function test_raster_encoder_reports_the_right_row_width(): void
    {
        if (! EscPosRaster::available()) {
            $this->markTestSkipped('GD is not available in this PHP.');
        }

        $img = imagecreatetruecolor(16, 2);
        imagefilledrectangle($img, 0, 0, 16, 2, imagecolorallocate($img, 0, 0, 0));
        $bytes = EscPosRaster::encode($img);
        imagedestroy($img);

        // GS v 0 m xL xH yL yH: 16 px = 2 bytes per row, 2 rows, all ink.
        $this->assertSame("\x1Dv0\x00\x02\x00\x02\x00\xFF\xFF\xFF\xFF", $bytes);
    }

    public function test_logo_raster_is_centred_on_the_full_paper_width(): void
    {
        if (! EscPosRaster::available()) {
            $this->markTestSkipped('GD is not available in this PHP.');
        }

        $path = tempnam(sys_get_temp_dir(), 'logo').'.png';
        $img = imagecreatetruecolor(40, 20);
        imagefilledrectangle($img, 0, 0, 40, 20, imagecolorallocate($img, 0, 0, 0));
        imagepng($img, $path);
        imagedestroy($img);

        $bytes = EscPosRaster::logo($path, 384);
        @unlink($path);

        $this->assertNotNull($bytes);
        $this->assertStringStartsWith("\x1Dv0\x00", $bytes);
        // 384 dots → 48 bytes per row.
        $this->assertSame("\x30", $bytes[4]);
    }

    public function test_missing_logo_never_breaks_a_receipt(): void
    {
        $bytes = EscPosRenderer::forWidthMm(80)
            ->logo('/nope/does-not-exist.png')
            ->render($this->order([['item_id' => $this->menu('Burger', 20)->id, 'quantity' => 1]]));

        $this->assertStringStartsWith("\x1B@", $bytes);
        $this->assertStringContainsString('Burger', $bytes);
    }

    // ── profile-driven construction ──────────────────────────────────────────

    public function test_for_profile_picks_up_the_stores_print_options(): void
    {
        StoreOption::updateStoreOption($this->store->id, 'print_rtl', '1');

        $profile = PrinterProfile::create([
            'store_id' => $this->store->id, 'name' => 'Front', 'transport' => 'browser',
            'width_mm' => 57, 'events' => ['paid'], 'auto_print' => true, 'active' => true,
        ]);

        $renderer = EscPosRenderer::forProfile($profile);

        $this->assertSame(32, $renderer->columns());
        $bytes = $renderer->render($this->order([['item_id' => $this->menu('Burger', 20)->id, 'quantity' => 1]]));
        $this->assertStringContainsString("\x1Ba2", $bytes); // RTL forced → right-aligned lines
    }

    private function fakeTtfPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'font').'.ttf';
        file_put_contents($path, 'not-a-real-font');

        return $path;
    }
}
