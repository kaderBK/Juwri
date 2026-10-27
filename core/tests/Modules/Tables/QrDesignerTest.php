<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Tables;

use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Tables\Models\QrDesign;
use Juwri\Modules\Tables\Services\TableSessionService;
use Smalot\PdfParser\Parser;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * PARITY-RUN T10: the QR Designer page, exports and rotation.
 */
class QrDesignerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        config(['settings.admin_allow_qr_designer' => true]);

        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'd.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'f.png'])
        {
            public function __construct(private array $d) {}

            public function __get($k): mixed
            {
                return $this->d[$k] ?? null;
            }

            public function __set($k, $v): void
            {
                $this->d[$k] = $v;
            }

            public function __isset($k): bool
            {
                return array_key_exists($k, $this->d);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect()]);
    }

    /** @return array{0: User, 1: Store} */
    private function ownerRestaurant(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $store];
    }

    private function tables(Store $store, int $count): array
    {
        $out = [];
        for ($i = 1; $i <= $count; $i++) {
            $out[] = StoreTable::create([
                'store_id' => $store->id, 'table_no' => $i, 'key' => Str::random(13),
            ]);
        }

        return $out;
    }

    private const DESIGN = [
        'fg_color' => '#0f766e', 'bg_color' => '#ffffff', 'eye_color' => '#0f766e',
        'dot_style' => 'rounded', 'eye_style' => 'rounded', 'frame' => 'bottom',
        'frame_text' => 'Scan to order', 'quiet_zone' => 4, 'size' => 512,
    ];

    /* ------------------------------------------------------------------- page */

    public function test_designer_page_renders_with_presets_and_preview(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $this->tables($store, 2);
        $this->withoutMiddleware();

        $res = $this->actingAs($owner)->get(route('qr.designer').'?store_id='.$store->id)->assertOk();

        $res->assertSee('Live preview');
        $res->assertSee('Start from a template');
        $res->assertSee('Table QR codes');
        $res->assertSeeHtml('<svg');
        // The bulk-export controls are the point of the page, so pin them.
        $res->assertSeeHtml(route('qr.designer.sheet'));
        $res->assertSeeHtml(route('qr.designer.zip'));
    }

    public function test_the_page_is_tenant_scoped(): void
    {
        [$owner] = $this->ownerRestaurant();
        [, $foreign] = $this->ownerRestaurant();
        $this->withoutMiddleware();

        $this->actingAs($owner)
            ->get(route('qr.designer').'?store_id='.$foreign->id)
            ->assertForbidden();
    }

    /* ---------------------------------------------------------------- preview */

    public function test_preview_returns_svg_and_the_guard_verdict(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $this->withoutMiddleware();

        $res = $this->actingAs($owner)->getJson(
            route('qr.designer.preview').'?store_id='.$store->id.'&'.http_build_query(self::DESIGN)
        )->assertOk();

        $res->assertJsonPath('ok', true);
        $this->assertStringStartsWith('<svg', $res->json('svg'));
        $this->assertSame('#0f766e', $res->json('applied.fg_color'));
    }

    public function test_preview_reports_a_design_that_will_not_scan(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $this->withoutMiddleware();

        $res = $this->actingAs($owner)->getJson(
            route('qr.designer.preview').'?store_id='.$store->id.'&fg_color=%23cccccc&bg_color=%23ffffff'
        )->assertOk();

        $res->assertJsonPath('ok', false);
        $this->assertNotEmpty($res->json('message'));
    }

    /* ------------------------------------------------------------------- save */

    public function test_saving_stores_the_design_and_every_qr_adopts_it(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $this->withoutMiddleware();

        $this->actingAs($owner)
            ->post(route('qr.designer.save').'?store_id='.$store->id, self::DESIGN)
            ->assertRedirect();

        $design = QrDesign::forStore($store->id);
        $this->assertNotNull($design);
        $this->assertSame('#0f766e', $design->fg_color);
        $this->assertSame('rounded', $design->dot_style);
        $this->assertSame('Scan to order', $design->frame_text);

        // The store-wide helper now hands the branded design to every surface.
        $this->assertStringContainsString('#0f766e', branded_qr_svg($store->id, store_public_qr_url($store)));
    }

    /** The decode guard is a SAVE guard: a bad design must never reach the table. */
    public function test_saving_refuses_a_design_that_fails_the_scan_guard(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('qr.designer.save').'?store_id='.$store->id, [
            'fg_color' => '#cccccc', 'bg_color' => '#ffffff',
        ])->assertRedirect();

        $this->assertNull(QrDesign::forStore($store->id));
    }

    public function test_saving_refuses_an_oversized_logo(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $this->withoutMiddleware();

        $this->actingAs($owner)->postJson(route('qr.designer.save').'?store_id='.$store->id, [
            'fg_color' => '#000000', 'bg_color' => '#ffffff',
            'logo_file' => 'brand.png', 'logo_scale' => 40,
        ])->assertStatus(422);

        $this->assertNull(QrDesign::forStore($store->id));
    }

    public function test_reset_removes_the_design(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        QrDesign::create(['store_id' => $store->id, 'is_default' => true] + self::DESIGN);
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('qr.designer.reset').'?store_id='.$store->id)->assertRedirect();

        $this->assertNull(QrDesign::forStore($store->id));
    }

    public function test_saving_never_unlinks_the_previous_logo_file(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $dir = public_path('storage/menu');
        @mkdir($dir, 0775, true);
        $file = 't10-shared-'.Str::lower(Str::random(6)).'.png';
        file_put_contents($dir.'/'.$file, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));
        QrDesign::create(['store_id' => $store->id, 'is_default' => true, 'logo_file' => $file] + self::DESIGN);
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('qr.designer.save').'?store_id='.$store->id, self::DESIGN + ['logo_file' => '']);

        // storage/menu is a SHARED library — the file must survive the design edit.
        $this->assertFileExists($dir.'/'.$file);
        @unlink($dir.'/'.$file);
    }

    /* -------------------------------------------------------------- downloads */

    public function test_download_serves_png_and_svg(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        [$table] = $this->tables($store, 1);
        $this->withoutMiddleware();

        $png = $this->actingAs($owner)
            ->get(route('qr.designer.download').'?store_id='.$store->id.'&target=table&id='.$table->id)
            ->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($png->getContent(), 0, 8));

        $this->actingAs($owner)
            ->get(route('qr.designer.download').'?store_id='.$store->id.'&format=svg')
            ->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    }

    public function test_download_is_tenant_scoped(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        [, $foreign] = $this->ownerRestaurant();
        [$foreignTable] = $this->tables($foreign, 1);
        $this->withoutMiddleware();

        $this->actingAs($owner)
            ->get(route('qr.designer.download').'?store_id='.$store->id.'&target=table&id='.$foreignTable->id)
            ->assertNotFound();
    }

    /** The A4 sheet must actually contain one code per table. */
    public function test_the_bulk_sheet_contains_one_qr_per_table(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $this->tables($store, 5);
        $this->withoutMiddleware();

        $res = $this->actingAs($owner)
            ->get(route('qr.designer.sheet').'?store_id='.$store->id)
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $pdf = $res->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(5, preg_match_all('#/Subtype\s*/Image#', $pdf), 'One image XObject per table.');
        // P6-264: the sheet is TCPDF (subset fonts) — read the drawn text, not raw PDF strings
        $text = preg_replace('/\s+/u', ' ', (new Parser)->parseContent($pdf)->getText());
        $this->assertStringContainsString('Table 1', $text);
        $this->assertStringContainsString('Table 5', $text);
    }

    public function test_the_bulk_sheet_paginates_past_twelve_tables(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $this->tables($store, 14);
        $this->withoutMiddleware();

        $res = $this->actingAs($owner)->get(route('qr.designer.sheet').'?store_id='.$store->id)->assertOk();
        $pdf = $res->getContent();

        $this->assertSame(14, preg_match_all('#/Subtype\s*/Image#', $pdf));
        $this->assertSame(2, preg_match_all('#/Type\s*/Page\b(?!s)#', $pdf)); // P6-264: TCPDF page objects
    }

    public function test_zip_bundles_every_table_in_both_formats(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ext-zip missing');
        }

        [$owner, $store] = $this->ownerRestaurant();
        $this->tables($store, 3);
        $this->withoutMiddleware();

        $res = $this->actingAs($owner)->get(route('qr.designer.zip').'?store_id='.$store->id)->assertOk();

        // A download is a BinaryFileResponse: the bytes live in a temp file, not
        // in getContent(), which is empty until the response is actually sent.
        $this->assertInstanceOf(BinaryFileResponse::class, $res->baseResponse);
        $path = $res->baseResponse->getFile()->getPathname();

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        // store.svg + store.png + 3 tables × 2 formats
        $this->assertSame(8, $zip->numFiles);
        $this->assertNotFalse($zip->locateName('store.svg'));
        $zip->close();
        @unlink($path);
    }

    /* --------------------------------------------------------------- rotation */

    public function test_rotating_a_table_kills_the_old_code_and_the_new_one_works(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        [$table] = $this->tables($store, 1);
        $old = $table->key;
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('qr.designer.rotate').'?store_id='.$store->id, [
            'table_id' => $table->id,
        ])->assertRedirect();

        $new = $table->fresh()->key;
        $this->assertNotSame($old, $new);

        // This is exactly how the storefront resolves a scanned table.
        $this->assertNull(StoreTable::where('store_id', $store->id)->where('key', $old)->first());
        $this->assertNotNull(StoreTable::where('store_id', $store->id)->where('key', $new)->first());

        // …and the printed URL is a different URL.
        $this->assertNotSame(store_public_qr_url($store, $old), store_public_qr_url($store, $new));
    }

    /**
     * The T3 seam, exercised for real rather than assumed.
     *
     * Both tracks were built in parallel and each wrote a defensive stub, so the
     * two halves disagreed while every suite stayed green: T10 called
     * `Tables\TableSessionService->rotateCodeForTable($tableId, $previousKey)`,
     * T3 shipped `TableSessionService::onTableQrRotated($storeId, $tableKey)`
     * — different namespace, name, shape, and static-vs-instance. Worse, T3
     * resolves the table by its CURRENT key, so passing the pre-rotation key
     * would have matched no row even once the name was fixed, silently leaving a
     * leaked verification code alive on a QR that was just rotated to kill it.
     *
     * This asserts the observable outcome — the code actually changes — so a
     * future rename on either side fails here instead of going quiet.
     */
    public function test_rotating_a_qr_also_rotates_the_tables_verification_code(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        [$table] = $this->tables($store, 1);
        $this->withoutMiddleware();

        $before = TableSessionService::codeFor($table->fresh());
        $this->assertNotNull($before, 'The table should start with a verification code.');

        $this->actingAs($owner)->post(route('qr.designer.rotate').'?store_id='.$store->id, [
            'table_id' => $table->id,
        ])->assertRedirect();

        $after = TableSessionService::codeFor($table->fresh(), false);
        $this->assertNotNull($after, 'The rotated table must still carry a code.');
        $this->assertNotSame($before, $after, 'Rotating the QR must rotate the verification code with it.');
    }

    public function test_rotate_all_rotates_every_table(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $tables = $this->tables($store, 4);
        $before = collect($tables)->pluck('key')->all();
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('qr.designer.rotateAll').'?store_id='.$store->id)->assertRedirect();

        $after = StoreTable::where('store_id', $store->id)->pluck('key')->all();
        $this->assertCount(4, $after);
        $this->assertEmpty(array_intersect($before, $after));
    }

    public function test_rotate_all_can_be_scoped_to_one_branch(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $branch = Branch::create(['store_id' => $store->id, 'name' => 'B1', 'position' => 1]);
        $mine = StoreTable::create(['store_id' => $store->id, 'table_no' => 1, 'key' => Str::random(13), 'branch_id' => $branch->id]);
        $other = StoreTable::create(['store_id' => $store->id, 'table_no' => 2, 'key' => Str::random(13)]);
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('qr.designer.rotateAll').'?store_id='.$store->id, [
            'branch_id' => $branch->id,
        ])->assertRedirect();

        $this->assertNotSame($mine->key, $mine->fresh()->key);
        $this->assertSame($other->key, $other->fresh()->key);
    }

    public function test_rotation_is_tenant_scoped(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        [, $foreign] = $this->ownerRestaurant();
        [$foreignTable] = $this->tables($foreign, 1);
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('qr.designer.rotate').'?store_id='.$store->id, [
            'table_id' => $foreignTable->id,
        ])->assertNotFound();
    }
}
