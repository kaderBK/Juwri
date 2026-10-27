<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Support\Pdf\PdfDocument;
use Juwri\Modules\Fiscal\Contracts\FiscalReceipts;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Services\OrderFiscalContext;
use Juwri\Modules\Ordering\Services\OrderReceiptPdf;
use Juwri\Modules\Tables\Contracts\QrImages;
use Juwri\Modules\Tables\Services\Qr\QrRenderService;
use Juwri\Modules\Tables\Services\Qr\QrSheetPdfService;
use PHPUnit\Framework\Attributes\DataProvider;
use Smalot\PdfParser\Parser;
use Tests\Support\PdfInspector;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * J70: the PDF engine is TCPDF. Every community PDF type (order receipt, table QR sheet) must produce a valid PDF
 * for an English and an Arabic store, with SAR and AED amounts printed as number + ISO code. Visual review aid:
 * PDF_VISUAL_DIR=/dir also writes the files for rendering to PNG.
 */
class PdfEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::firstOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        View::share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => (object) [], 'languages' => collect(), 'admin_languages' => collect()]);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> [locale, currency, store title] */
    public static function receiptCases(): array
    {
        return [
            'en SAR' => ['en', 'SAR', 'Hope Cafe'],
            'ar SAR' => ['ar', 'SAR', 'مطعم الأمل'],
            'en AED' => ['en', 'AED', 'Palm Grill'],
            'ar AED' => ['ar', 'AED', 'مشاوي النخيل'],
        ];
    }

    #[DataProvider('receiptCases')]
    public function test_receipt_pdf_is_valid_and_carries_the_amounts_and_currency_code(string $locale, string $currency, string $title): void
    {
        app()->setLocale($locale);
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
            'username' => 'owner'.Str::lower(Str::random(6)), 'email' => Str::lower(Str::random(8)).'@owner.test',
        ]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => $title, 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png', 'cover_image' => 'd.png',
            'address' => '1 St', 'phone' => '1', 'store_type' => 'restaurant', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency); // the order is stamped with it at creation
        $order = Order::create([
            'store_id' => $store->id, 'type' => 'takeaway', 'status' => 'new',
            'track_token' => Str::lower(Str::random(32)), 'created_at' => now(),
        ]);
        $arabic = $locale === 'ar';
        $m1 = Menu::create(['store_id' => $store->id, 'name' => $arabic ? 'فلافل' : 'Falafel', 'price' => 10, 'active' => '1']);
        $m2 = Menu::create(['store_id' => $store->id, 'name' => $arabic ? 'شاي بالنعناع' : 'Mint tea', 'price' => 7, 'active' => '1']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $m1->id, 'quantity' => 2, 'unit_price' => 12.5]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $m2->id, 'quantity' => 3, 'unit_price' => 7.25]);
        $order = $order->fresh();

        $pdf = app(OrderReceiptPdf::class)->render($store, $order);
        $this->dump($pdf, 'receipt-'.$locale.'-'.strtolower($currency));

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('%%EOF', $pdf);
        $text = preg_replace('/\s+/u', ' ', (new Parser)->parseContent($pdf)->getText());

        $ctx = OrderFiscalContext::for($store, $order);
        $this->assertSame($currency, $ctx->currency);
        $dec = money_decimals($ctx->currency);
        $fmt = fn (float $v) => number_format($v, $dec, '.', ',');
        $this->assertStringContainsString($fmt($ctx->total), $text);
        $this->assertStringContainsString($currency, $text);
        foreach ($ctx->lines as $l) {
            $this->assertStringContainsString($fmt($l->amount ?? $l->unitPrice * $l->quantity), $text);
        }
        if ($arabic) {
            // Shaped (presentation-form) Arabic glyphs are in the text layer.
            $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}\x{FB50}-\x{FEFF}]/u', $text);
        } else {
            $this->assertStringContainsString('Falafel', $text);
        }
    }

    public function test_receipt_html_input_holds_the_formatted_amounts(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now(), 'group_id' => 1]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'T',
            'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1',
            'phone' => '1', 'store_type' => 'restaurant', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'AED');
        $order = Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'new',
            'track_token' => Str::lower(Str::random(32)), 'created_at' => now()]);
        [$html] = app(OrderReceiptPdf::class)->html($store, $order);
        $this->assertStringContainsString(' AED', $html);
    }

    public function test_html_cannot_call_tcpdf_methods(): void
    {
        PdfDocument::make('A4', [10, 10, 10, 10], 't');
        $this->assertFalse(K_TCPDF_CALLS_IN_HTML);
    }

    /** A KSA store with its fiscal profile on, so the receipt carries the fiscal QR. */
    private function ksaOrder(string $currency = 'SAR', string $country = 'SA'): array
    {
        config(['settings.admin_allow_fiscalization' => true, 'settings.admin_allow_fiscal_'.strtolower($country) => true]);
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'مطعم الأمل',
            'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1',
            'phone' => '1', 'store_type' => 'restaurant', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (['fiscal_country' => $country, 'fiscal_vat_number' => '300000000000003', 'fiscal_legal_name' => 'Hope Co', 'restaurant_taxx_charge' => 15, 'currency_code' => $currency] as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }
        $order = Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'new',
            'track_token' => Str::lower(Str::random(32)), 'created_at' => now()]);
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Tea', 'price' => 10, 'active' => '1']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 2, 'unit_price' => 10]);

        return [$store, $order->fresh()];
    }

    public function test_ksa_receipt_draws_the_fiscal_qr_and_keeps_the_totals_in_the_text(): void
    {
        [$store, $order] = $this->ksaOrder();
        $pdf = app(OrderReceiptPdf::class)->render($store, $order);
        $this->dump($pdf, 'receipt-ksa-fiscal');

        $text = preg_replace('/\s+/u', ' ', (new Parser)->parseContent($pdf)->getText());
        $ctx = OrderFiscalContext::for($store, $order);
        $this->assertSame('SAR', $ctx->currency);
        $this->assertStringContainsString(number_format($ctx->total, 2, '.', ','), $text);
        $this->assertStringContainsString('SAR', $text);
        $this->assertGreaterThan(100, PdfInspector::rectangles($pdf), 'the QR is drawn (vector modules)');
    }

    public function test_the_receipt_qr_does_not_use_the_raster_service(): void
    {
        // A host without GD: the raster QR service must not even be asked.
        $this->app->instance(QrImages::class, new class implements QrImages
        {
            public function png(string $payload): string
            {
                throw new \RuntimeException('the PDF must draw its QR itself');
            }
        });
        [$store, $order] = $this->ksaOrder();
        $pdf = app(OrderReceiptPdf::class)->render($store, $order);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(100, PdfInspector::rectangles($pdf));
    }

    public function test_the_drawn_qr_encodes_exactly_the_fiscal_payload(): void
    {
        [$store, $order] = $this->ksaOrder();
        $header = app(FiscalReceipts::class)->receiptHeader($store, OrderFiscalContext::for($store, $order));
        $payload = $header['qr'];
        $this->assertNotEmpty($payload);

        // 1. the payload that reaches the drawing code is the fiscal profile's QR
        $build = new \ReflectionMethod(OrderReceiptPdf::class, 'build');
        $this->assertSame($payload, $build->invoke(app(OrderReceiptPdf::class), $store, $order)[3]);

        // 2. the QR drawn on the finished page is exactly that code (read back from the page's own rectangles)
        $expected = app(QrRenderService::class)->matrix($payload, 'M');
        $drawn = PdfInspector::drawnQrMatrix(app(OrderReceiptPdf::class)->render($store, $order));
        $this->assertSame(array_map(fn ($row) => array_map(fn ($v) => $v ? 1 : 0, $row), $expected), $drawn);
        $this->assertNotSame($drawn, array_map(fn ($row) => array_map(fn ($v) => $v ? 1 : 0, $row), app(QrRenderService::class)->matrix($payload.'x', 'M')));
    }

    public function test_the_qr_keeps_a_quiet_zone_clear_of_the_totals(): void
    {
        [$store, $order] = $this->ksaOrder();
        $pdf = app(OrderReceiptPdf::class)->render($store, $order);
        $box = PdfInspector::rectangleBounds($pdf);
        $this->assertNotNull($box);
        // The code is drawn inside a 36 mm square whose outer 4 modules stay white: its modules start well inside it.
        $side = ($box[2] - $box[0]) / 72 * 25.4;
        $this->assertLessThan(33, $side, 'modules must not fill the whole box');
        $this->assertGreaterThan(24, $side);
    }

    public function test_a_long_zatca_payload_gets_a_box_big_enough_to_scan_and_is_drawn_white_backed_in_runs(): void
    {
        // A live 9-tag ZATCA TLV (with the 64-byte-class hash, signature and key) is a few hundred bytes: ~89 modules.
        $payload = base64_encode(str_repeat('Hope Co 300000000000003 2026-10-27T11:00:00Z 23.00 3.00 ', 5).random_bytes(70));
        $modules = count(app(QrRenderService::class)->matrix($payload, 'M'));
        $this->assertGreaterThanOrEqual(77, $modules, 'a big code');

        $size = PdfDocument::qrSize($payload, 36.0);
        $this->assertGreaterThanOrEqual(($modules + 8) * 0.4, $size);
        $this->assertLessThan(70, $size, 'still fits the 80 mm receipt');
        $this->assertSame(36.0, PdfDocument::qrSize('short', 36.0), 'a small code keeps the default box');

        $pdf = PdfDocument::make([80, 297], [5, 6, 5, 6], 't');
        $pdf->AddPage();
        PdfDocument::drawQr($pdf, $payload, (80 - $size) / 2, 10, $size);
        $out = $pdf->Output('t.pdf', 'S');

        $expected = array_map(fn ($row) => array_map(fn ($v) => $v ? 1 : 0, $row), app(QrRenderService::class)->matrix($payload, 'M'));
        $this->assertSame($expected, PdfInspector::drawnQrMatrix($out));
        $box = PdfInspector::rectangleBounds($out);
        $moduleMm = (($box[2] - $box[0]) / 72 * 25.4) / $modules;
        $this->assertGreaterThanOrEqual(0.39, $moduleMm, 'every module is at least 0.4 mm');

        $dark = array_sum(array_map('array_sum', $expected));
        $this->assertLessThan($dark, PdfInspector::rectangles($out) - 1, 'horizontal runs are merged into one rectangle');
        $streams = '';
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $out, $m);
        foreach ($m[1] as $raw) {
            $streams .= (string) @gzuncompress($raw);
        }
        $this->assertStringContainsString('1.000000 1.000000 1.000000 rg', $streams, 'a white square is painted behind the modules');
    }

    public function test_a_tcpdf_error_throws_instead_of_dying(): void
    {
        $this->assertTrue(K_TCPDF_THROW_EXCEPTION_ERROR);
        $pdf = PdfDocument::make('A4', [10, 10, 10, 10], 't');
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('TCPDF ERROR');
        $pdf->SetFont('no-such-font-family');
    }

    /** A 3-decimal currency (OMR, with its VAT columns) with big amounts and long item names must not split numbers or headers mid-word. */
    public function test_three_decimal_receipt_with_large_amounts_renders(): void
    {
        [$store] = $this->ksaOrder('OMR', 'OM');
        $order = Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'new', 'track_token' => Str::lower(Str::random(32)), 'created_at' => now()]);
        $m = Menu::create(['store_id' => $store->id, 'name' => 'Mixed grill platter for the whole family with extra rice and salad', 'price' => 10, 'active' => '1']);
        $m2 = Menu::create(['store_id' => $store->id, 'name' => 'مشاوي مشكلة للعائلة مع أرز إضافي وسلطة', 'price' => 10, 'active' => '1']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $m->id, 'quantity' => 12, 'unit_price' => 1234.567]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $m2->id, 'quantity' => 3, 'unit_price' => 9876.543]);
        $order = $order->fresh();

        $pdf = app(OrderReceiptPdf::class)->render($store, $order);
        $this->dump($pdf, 'receipt-omr-large');
        $text = preg_replace('/\s+/u', ' ', (new Parser)->parseContent($pdf)->getText());
        $ctx = OrderFiscalContext::for($store, $order);
        $this->assertSame('OMR', $ctx->currency);
        $this->assertStringContainsString(number_format($ctx->total, 3, '.', ','), $text, 'the grand total is one unbroken number');
        foreach ($ctx->lines as $l) {
            $this->assertStringContainsString(number_format((float) $l->amount, 3, '.', ','), $text);
        }
    }

    public function test_a_receipt_without_a_fiscal_profile_has_no_qr(): void
    {
        [$store, $order] = $this->ksaOrder();
        StoreOption::updateStoreOption($store->id, 'fiscal_country', '');
        config(['settings.admin_allow_fiscalization' => false]);
        $pdf = app(OrderReceiptPdf::class)->render($store, $order->fresh());

        $this->assertLessThan(60, PdfInspector::rectangles($pdf));
    }

    /** @return array<string, array{0: string, 1: list<string>, 2: string}> */
    public static function sheetCases(): array
    {
        return [
            'en' => ['en', ['Table 1', 'Table 2', 'Terrace'], 'Hope Cafe - QR codes'],
            'ar' => ['ar', ['طاولة 1', 'طاولة 2', 'الشرفة'], 'مطعم الأمل — رموز QR'],
        ];
    }

    #[DataProvider('sheetCases')]
    public function test_qr_sheet_pdf_is_valid_with_images_and_labels(string $locale, array $labels, string $title): void
    {
        $cells = array_map(fn ($l) => ['png' => app(QrRenderService::class)->png('https://example.test/t/'.md5($l)), 'label' => $l], $labels);
        $pdf = (new QrSheetPdfService)->sheet($cells, $title);
        $this->dump($pdf, 'qr-sheet-'.$locale);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('%%EOF', $pdf);
        $this->assertStringContainsString('/Image', $pdf);
        $text = preg_replace('/\s+/u', ' ', (new Parser)->parseContent($pdf)->getText());
        if ($locale === 'ar') {
            $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}\x{FB50}-\x{FEFF}]/u', $text);
        } else {
            $this->assertStringContainsString('Terrace', $text);
        }
    }

    private function dump(string $pdf, string $name): void
    {
        $dir = getenv('PDF_VISUAL_DIR');
        if ($dir) {
            @mkdir($dir, 0775, true);
            file_put_contents(rtrim($dir, '/').'/'.$name.'.pdf', $pdf);
        }
    }
}
