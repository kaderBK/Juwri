<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\ImageMenu;
use App\Models\StoreOption;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use Juwri\Core\Http\Middleware\SyncDashboardStore;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Models\FolioPageStat;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Folio Polish (FP run) — lane B backend/dashboard.
 *
 * B1 covers the FolioHelper.php read contract (SPEC §10); later items append
 * their panel/editor/endpoint/analytics tests below.
 */
class FolioPolishBackendTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> absolute paths created on disk, removed on teardown */
    private array $tmpFiles = [];

    /** @var array<int, string> absolute dirs created on disk, removed on teardown */
    private array $tmpDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
            SyncDashboardStore::class,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        foreach ($this->tmpDirs as $d) {
            File::deleteDirectory($d);
        }
        parent::tearDown();
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(6), 'description' => 'T', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999],
        ]);

        return User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
        ]);
    }

    private function store(?User $owner = null, ?string $template = 'folio'): Store
    {
        $owner ??= $this->owner();
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '15551234',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($template !== null) {
            StoreOption::updateStoreOption($store->id, 'store_template', $template);
        }

        return $store;
    }

    private function item(Store $store, string $name, float $price, array $extra = []): Menu
    {
        return Menu::create(array_merge([
            'store_id' => $store->id, 'name' => $name, 'price' => $price,
            'image' => 'default.png', 'active' => '1',
        ], $extra));
    }

    // ------------------------------------------------------------- B1: folio_books

    public function test_folio_books_parses_valid_definitions_and_caps_at_six(): void
    {
        $store = $this->store();
        $books = [];
        for ($i = 1; $i <= 8; $i++) {
            $books[] = ['key' => 'b'.$i, 'label' => 'Book '.$i, 'start' => '09:00', 'end' => '17:00'];
        }
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_books', json_encode($books));

        $out = folio_books($store->id);

        $this->assertCount(6, $out);
        $this->assertSame(['key' => 'b1', 'label' => 'Book 1', 'start' => '09:00', 'end' => '17:00'], $out[0]);
    }

    public function test_folio_books_drops_bad_rows_and_duplicate_keys(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_books', json_encode([
            ['key' => 'lunch', 'label' => 'Lunch', 'start' => '11:00', 'end' => '15:00'],
            ['key' => 'lunch', 'label' => 'Dup', 'start' => '00:00', 'end' => '01:00'],   // dupe key
            ['key' => 'bad', 'label' => 'Bad', 'start' => '25:99', 'end' => '10:00'],       // bad HH:MM
            ['key' => '', 'label' => 'NoKey', 'start' => '09:00', 'end' => '10:00'],          // empty key
        ]));

        $out = folio_books($store->id);

        $this->assertCount(1, $out);
        $this->assertSame('lunch', $out[0]['key']);
        $this->assertSame('Lunch', $out[0]['label']);
    }

    public function test_folio_books_returns_empty_on_corrupt_or_unset(): void
    {
        $store = $this->store();
        $this->assertSame([], folio_books($store->id)); // unset
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_books', '{not json');
        $this->assertSame([], folio_books($store->id)); // corrupt
    }

    // ------------------------------------------------------------- B1: folio_active_book

    public function test_folio_active_book_resolves_windows(): void
    {
        config(['app.timezone' => 'UTC']);
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_books', json_encode([
            ['key' => 'lunch', 'label' => 'Lunch', 'start' => '09:00', 'end' => '17:00'],
            ['key' => 'late', 'label' => 'Late night', 'start' => '22:00', 'end' => '02:00'],
        ]));

        Carbon::setTestNow(Carbon::parse('2026-08-18 12:00:00', 'UTC'));
        $this->assertSame('lunch', folio_active_book($store->id));

        Carbon::setTestNow(Carbon::parse('2026-08-18 23:30:00', 'UTC'));
        $this->assertSame('late', folio_active_book($store->id)); // overnight, late evening

        Carbon::setTestNow(Carbon::parse('2026-08-18 01:00:00', 'UTC'));
        $this->assertSame('late', folio_active_book($store->id)); // overnight, early morning

        Carbon::setTestNow(Carbon::parse('2026-08-18 19:00:00', 'UTC'));
        $this->assertNull(folio_active_book($store->id)); // gap between windows
    }

    public function test_folio_active_book_null_without_books(): void
    {
        $store = $this->store();
        $this->assertNull(folio_active_book($store->id));
    }

    // ------------------------------------------------------------- B1: folio_page_book_tags

    public function test_folio_page_book_tags_parses_and_falls_back(): void
    {
        $store = $this->store();
        $this->assertSame([], folio_page_book_tags($store->id));

        StoreOption::updateStoreOption($store->id, 'restaurant_folio_page_books', json_encode([
            '5' => 'lunch', '6' => 'dinner', '0' => 'bad', '9' => '',
        ]));
        $tags = folio_page_book_tags($store->id);
        $this->assertCount(2, $tags);
        $this->assertSame('lunch', $tags[5]);
        $this->assertSame('dinner', $tags[6]);
        $this->assertArrayNotHasKey(0, $tags); // non-positive id dropped
        $this->assertArrayNotHasKey(9, $tags); // empty key dropped
    }

    // ------------------------------------------------------------- B1: folio_hotspots_payload

    public function test_folio_hotspots_payload_builds_spots_and_items(): void
    {
        $store = $this->store();
        $burger = $this->item($store, 'Burger', 9.5);
        $soup = $this->item($store, 'Soup', 4.0, ['is_sold_out' => true]);

        $foreignPost = $this->store();
        $foreign = $this->item($foreignPost, 'Foreign', 3.0);

        StoreOption::updateStoreOption($store->id, 'restaurant_folio_hotspots', json_encode([
            '10' => [
                ['x' => 0.1, 'y' => 0.2, 'w' => 0.3, 'h' => 0.25, 'item' => $burger->id],
                ['x' => 2.0, 'y' => -1.0, 'w' => 0.2, 'h' => 0.2, 'item' => $soup->id],   // clamped
                ['x' => 0.5, 'y' => 0.5, 'w' => 0.2, 'h' => 0.2, 'item' => $foreign->id], // dropped
            ],
        ]));

        $payload = folio_hotspots_payload($store);

        $this->assertArrayHasKey('spots', $payload);
        $this->assertArrayHasKey('items', $payload);
        $this->assertCount(2, $payload['spots'][10]);              // foreign rect dropped
        $this->assertSame(1.0, $payload['spots'][10][1]['x']);    // 2.0 clamped to 1.0
        $this->assertSame(0.0, $payload['spots'][10][1]['y']);    // -1.0 clamped to 0.0
        $this->assertArrayHasKey($burger->id, $payload['items']);
        $this->assertArrayNotHasKey($foreign->id, $payload['items']);
        $this->assertStringContainsString('9.50', $payload['items'][$burger->id]['price']);
        $this->assertTrue($payload['items'][$soup->id]['sold_out']);
    }

    public function test_folio_hotspots_payload_empty_on_corrupt_or_unset(): void
    {
        $store = $this->store();
        $this->assertSame([], folio_hotspots_payload($store));
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_hotspots', 'not-json');
        $this->assertSame([], folio_hotspots_payload($store));
    }

    // ------------------------------------------------------------- B1: folio_cta

    public function test_folio_cta_whatsapp_link_and_hidden(): void
    {
        $store = $this->store();
        $this->assertNull(folio_cta($store)); // hidden default

        StoreOption::updateStoreOption($store->id, 'restaurant_folio_cta', 'whatsapp');
        $wa = folio_cta($store);
        $this->assertSame('whatsapp', $wa['kind']);
        $this->assertNotSame('', $wa['href']);
        $this->assertSame('Order', $wa['label']); // default label

        StoreOption::updateStoreOption($store->id, 'restaurant_folio_cta', 'link');
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_cta_url', 'https://example.com/order');
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_cta_label', 'Order online');
        $link = folio_cta($store);
        $this->assertSame(['kind' => 'link', 'href' => 'https://example.com/order', 'label' => 'Order online'], $link);
    }

    public function test_folio_cta_rejects_non_http_link(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_cta', 'link');
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_cta_url', 'javascript:alert(1)');
        $this->assertNull(folio_cta($store));
    }

    // ------------------------------------------------------------- B1: folio_srcset

    public function test_folio_srcset_only_when_variant_present(): void
    {
        $this->assertNull(folio_srcset('missing.webp', null));

        $variant = public_path('storage/menu/fptest-'.Str::random(6).'-w900.webp');
        File::ensureDirectoryExists(dirname($variant));
        file_put_contents($variant, 'x');
        $this->tmpFiles[] = $variant;

        $image = str_replace('-w900.webp', '.webp', basename($variant));
        $srcset = folio_srcset($image, null);
        $this->assertNotNull($srcset);
        $this->assertStringContainsString('900w', $srcset);
        $this->assertStringContainsString(basename($variant), $srcset);
    }

    // ------------------------------------------------------------- B1: folio_pdf_pages

    public function test_folio_pdf_pages_lists_existing_files(): void
    {
        $store = $this->store();
        $this->assertSame([], folio_pdf_pages($store->id));

        $dir = public_path('storage/folio-pdf-pages/'.$store->id);
        File::ensureDirectoryExists($dir);
        foreach ([1, 2] as $n) {
            $f = $dir.'/p'.$n.'.jpg';
            file_put_contents($f, 'x');
            $this->tmpFiles[] = $f;
        }
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_pdf_pages', '2');

        $pages = folio_pdf_pages($store->id);
        $this->assertCount(2, $pages);
        $this->assertStringContainsString('/p1.jpg', $pages[0]);
        $this->assertStringContainsString('/p2.jpg', $pages[1]);
    }

    // ------------------------------------------------------------- B1: folio_metrics_summary

    public function test_folio_metrics_summary_empty_state(): void
    {
        $store = $this->store();
        $this->assertSame(['total' => 0, 'pages' => [], 'dropoff' => null], folio_metrics_summary($store->id));
    }

    // ------------------------------------------------------------- B2: panel controls

    public function test_b2_save_persists_texture_sound_and_cta(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);

        $this->actingAs($owner)->postJson("/theme-customizer/{$store->id}/folio-options", [
            'texture' => 'linen', 'sound' => '1',
            'cta' => 'link', 'cta_url' => 'https://example.com/order', 'cta_label' => 'Order now',
        ])->assertOk()
            ->assertJsonPath('folio.texture', 'linen')
            ->assertJsonPath('folio.sound', '1')
            ->assertJsonPath('folio.cta', 'link')
            ->assertJsonPath('folio.cta_url', 'https://example.com/order')
            ->assertJsonPath('folio.cta_label', 'Order now');

        $this->assertSame('linen', (string) store_options($store->id, 'restaurant_folio_texture'));
        $this->assertSame('1', (string) store_options($store->id, 'restaurant_folio_sound'));
        $this->assertSame('link', (string) store_options($store->id, 'restaurant_folio_cta'));
        $this->assertSame('https://example.com/order', (string) store_options($store->id, 'restaurant_folio_cta_url'));
        $this->assertSame('Order now', (string) store_options($store->id, 'restaurant_folio_cta_label'));
    }

    public function test_b2_rejects_bad_texture_and_cta(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);

        $this->actingAs($owner)->postJson("/theme-customizer/{$store->id}/folio-options", [
            'texture' => 'velvet', 'cta' => 'sms',
        ])->assertStatus(422);

        $this->assertEmpty(store_options($store->id, 'restaurant_folio_texture'));
    }

    public function test_b2_link_cta_requires_http_url(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);

        // link kind with no URL → required_if fails
        $this->actingAs($owner)->postJson("/theme-customizer/{$store->id}/folio-options", [
            'cta' => 'link', 'cta_url' => '',
        ])->assertStatus(422);

        // non-http(s) scheme rejected
        $this->actingAs($owner)->postJson("/theme-customizer/{$store->id}/folio-options", [
            'cta' => 'link', 'cta_url' => 'ftp://example.com/x',
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------- B3: books editor

    public function test_b3_saves_books_and_page_tags_round_trip(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $page = ImageMenu::create(['store_id' => $store->id, 'name' => 'Lunch page', 'image' => 'p.webp', 'active' => '1']);

        $this->actingAs($owner)->postJson("/theme-customizer/{$store->id}/folio-books", [
            'books' => [
                ['label' => 'Lunch Menu', 'start' => '11:00', 'end' => '15:00'],
                ['label' => 'Dinner', 'start' => '18:00', 'end' => '23:00'],
            ],
            'page_books' => [(string) $page->id => 0],
        ])->assertOk()
            ->assertJsonPath('folio.books.0.key', 'lunch-menu')
            ->assertJsonPath('folio.books.1.key', 'dinner');

        $books = folio_books($store->id);
        $this->assertCount(2, $books);
        $this->assertSame('lunch-menu', $books[0]['key']);
        $this->assertSame('lunch-menu', folio_page_book_tags($store->id)[$page->id]);
    }

    public function test_b3_rejects_foreign_page_id(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $otherPost = $this->store($this->owner());
        $foreign = ImageMenu::create(['store_id' => $otherPost->id, 'name' => 'X', 'image' => 'x.webp', 'active' => '1']);

        $this->actingAs($owner)->postJson("/theme-customizer/{$store->id}/folio-books", [
            'books' => [['label' => 'Lunch', 'start' => '11:00', 'end' => '15:00']],
            'page_books' => [(string) $foreign->id => 0],
        ])->assertStatus(422);
    }

    public function test_b3_rejects_bad_time_format(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);

        $this->actingAs($owner)->postJson("/theme-customizer/{$store->id}/folio-books", [
            'books' => [['label' => 'Lunch', 'start' => '25:00', 'end' => '15:00']],
        ])->assertStatus(422);
    }

    public function test_b3_slugs_duplicate_labels_uniquely(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);

        $this->actingAs($owner)->postJson("/theme-customizer/{$store->id}/folio-books", [
            'books' => [
                ['label' => 'Menu', 'start' => '09:00', 'end' => '12:00'],
                ['label' => 'Menu', 'start' => '12:00', 'end' => '15:00'],
            ],
        ])->assertOk()
            ->assertJsonPath('folio.books.0.key', 'menu')
            ->assertJsonPath('folio.books.1.key', 'menu-2');
    }

    public function test_b3_saved_books_resolve_active_window(): void
    {
        config(['app.timezone' => 'UTC']);
        $owner = $this->owner();
        $store = $this->store($owner);

        $this->actingAs($owner)->postJson("/theme-customizer/{$store->id}/folio-books", [
            'books' => [['label' => 'Late Night', 'start' => '22:00', 'end' => '02:00']],
        ])->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-08-18 01:00:00', 'UTC'));
        $this->assertSame('late-night', folio_active_book($store->id)); // overnight bleed
        Carbon::setTestNow(Carbon::parse('2026-08-18 12:00:00', 'UTC'));
        $this->assertNull(folio_active_book($store->id));
    }

    // ------------------------------------------------------------- B4: hotspot editor

    public function test_b4_editor_renders_hotspots_button_and_endpoint(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        ImageMenu::create(['store_id' => $store->id, 'name' => 'Page 1', 'image' => 'p1.webp', 'active' => '1']);

        $res = $this->actingAs($owner)->get(route('stores.menu', $store->id));
        $res->assertOk();
        $res->assertSee('Hotspots');
        $res->assertSee('Menu hotspots');
        // The save URL is embedded in the Alpine config JSON (Js::from escapes
        // slashes), so assert on the path segment rather than the full URL.
        $res->assertSee('folio-hotspots');
    }

    public function test_b4_saves_valid_hotspot_map(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $page = ImageMenu::create(['store_id' => $store->id, 'name' => 'Page 1', 'image' => 'p1.webp', 'active' => '1']);
        $item = $this->item($store, 'Burger', 9.5);

        $this->actingAs($owner)->postJson("/theme-customizer/{$store->id}/folio-hotspots", [
            'hotspots' => [
                (string) $page->id => [
                    ['x' => 0.1, 'y' => 0.2, 'w' => 0.3, 'h' => 0.25, 'item' => $item->id],
                    ['x' => 0.5, 'y' => 0.5, 'w' => 0.01, 'h' => 0.5, 'item' => $item->id], // too small → dropped
                ],
            ],
        ])->assertOk()->assertJson(['ok' => true]);

        $payload = folio_hotspots_payload($store);
        $this->assertCount(1, $payload['spots'][$page->id]); // tiny rect dropped
        $this->assertSame($item->id, $payload['spots'][$page->id][0]['item']);
    }

    public function test_b4_rejects_oversize_foreign_and_malformed(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $page = ImageMenu::create(['store_id' => $store->id, 'name' => 'Page 1', 'image' => 'p1.webp', 'active' => '1']);
        $item = $this->item($store, 'Burger', 9.5);
        $foreignItem = $this->item($this->store($this->owner()), 'Foreign', 3.0);

        // > 20 rects on one page
        $rects = [];
        for ($i = 0; $i < 21; $i++) {
            $rects[] = ['x' => 0.1, 'y' => 0.1, 'w' => 0.1, 'h' => 0.1, 'item' => $item->id];
        }
        $this->actingAs($owner)->postJson("/theme-customizer/{$store->id}/folio-hotspots", [
            'hotspots' => [(string) $page->id => $rects],
        ])->assertStatus(422);

        // foreign item
        $this->actingAs($owner)->postJson("/theme-customizer/{$store->id}/folio-hotspots", [
            'hotspots' => [(string) $page->id => [['x' => 0.1, 'y' => 0.1, 'w' => 0.2, 'h' => 0.2, 'item' => $foreignItem->id]]],
        ])->assertStatus(422);

        // malformed (missing item)
        $this->actingAs($owner)->postJson("/theme-customizer/{$store->id}/folio-hotspots", [
            'hotspots' => [(string) $page->id => [['x' => 0.1, 'y' => 0.1, 'w' => 0.2, 'h' => 0.2]]],
        ])->assertStatus(422);
    }

    public function test_b4_rejects_foreign_page(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $item = $this->item($store, 'Burger', 9.5);
        $otherPost = $this->store($this->owner());
        $foreignPage = ImageMenu::create(['store_id' => $otherPost->id, 'name' => 'X', 'image' => 'x.webp', 'active' => '1']);

        $this->actingAs($owner)->postJson("/theme-customizer/{$store->id}/folio-hotspots", [
            'hotspots' => [(string) $foreignPage->id => [['x' => 0.1, 'y' => 0.1, 'w' => 0.2, 'h' => 0.2, 'item' => $item->id]]],
        ])->assertStatus(422);
    }

    public function test_b4_is_tenant_scoped(): void
    {
        $victimOwner = $this->owner();
        $victim = $this->store($victimOwner);
        $attacker = $this->owner();

        $this->actingAs($attacker)->postJson("/theme-customizer/{$victim->id}/folio-hotspots", [
            'hotspots' => [],
        ])->assertNotFound();
    }

    // ------------------------------------------------------------- B5: PDF pre-raster

    public function test_b5_stores_pages_fenced_and_sets_count(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $this->tmpDirs[] = public_path('storage/folio-pdf-pages/'.$store->id);

        $this->actingAs($owner)->post("/theme-customizer/{$store->id}/folio-pdf-pages", [
            'pages' => [
                UploadedFile::fake()->image('a.jpg', 40, 40),
                UploadedFile::fake()->image('b.jpg', 40, 40),
            ],
        ])->assertOk()->assertJson(['success' => true, 'count' => 2]);

        $this->assertSame('2', (string) store_options($store->id, 'restaurant_folio_pdf_pages'));
        $this->assertFileExists(public_path('storage/folio-pdf-pages/'.$store->id.'/p1.jpg'));
        $this->assertFileExists(public_path('storage/folio-pdf-pages/'.$store->id.'/p2.jpg'));
        $this->assertCount(2, folio_pdf_pages($store->id));
    }

    public function test_b5_ignores_client_filename_no_traversal(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $this->tmpDirs[] = public_path('storage/folio-pdf-pages/'.$store->id);

        $this->actingAs($owner)->post("/theme-customizer/{$store->id}/folio-pdf-pages", [
            'pages' => [UploadedFile::fake()->image('../../evil.jpg', 40, 40)],
        ])->assertOk();

        // Output is p1.jpg regardless of the malicious client name; nothing escaped.
        $this->assertFileExists(public_path('storage/folio-pdf-pages/'.$store->id.'/p1.jpg'));
        $this->assertFileDoesNotExist(public_path('storage/folio-pdf-pages/evil.jpg'));
    }

    public function test_b5_enforces_count_cap(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);

        $files = [];
        for ($i = 0; $i < 61; $i++) {
            $files[] = UploadedFile::fake()->image('p'.$i.'.jpg', 20, 20);
        }
        $this->actingAs($owner)->post("/theme-customizer/{$store->id}/folio-pdf-pages", [
            'pages' => $files,
        ])->assertStatus(422);
    }

    public function test_b5_rejects_non_image(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);

        $this->actingAs($owner)->post("/theme-customizer/{$store->id}/folio-pdf-pages", [
            'pages' => [UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')],
        ])->assertStatus(422);
    }

    public function test_b5_delete_pdf_wipes_pages_and_option(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $dir = public_path('storage/folio-pdf-pages/'.$store->id);
        $this->tmpDirs[] = $dir;

        // Seed pre-rastered pages + count.
        $this->actingAs($owner)->post("/theme-customizer/{$store->id}/folio-pdf-pages", [
            'pages' => [UploadedFile::fake()->image('a.jpg', 40, 40)],
        ])->assertOk();
        $this->assertFileExists($dir.'/p1.jpg');

        // Removing the PDF wipes the page dir + clears the count option.
        $this->actingAs($owner)->postJson("/theme-customizer/{$store->id}/folio-pdf", [
            'action' => 'remove',
        ])->assertOk();

        $this->assertFileDoesNotExist($dir.'/p1.jpg');
        $this->assertEmpty(store_options($store->id, 'restaurant_folio_pdf_pages'));
        $this->assertSame([], folio_pdf_pages($store->id));
    }

    // ------------------------------------------------------------- B6: responsive variants

    public function test_b6_upload_creates_w900_variant(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);

        $this->actingAs($owner)->post(route('stores.addImageMenuItem', $store->id), [
            'name' => 'Page 1',
            'image' => UploadedFile::fake()->image('page.jpg', 800, 1000),
            'active' => '1',
        ]);

        $row = ImageMenu::where('store_id', $store->id)->firstOrFail();
        $this->assertNotSame('', (string) $row->image);
        $variant = public_path('storage/menu/'.pathinfo($row->image, PATHINFO_FILENAME).'-w900.webp');
        $this->tmpFiles[] = public_path('storage/menu/'.$row->image);
        $this->tmpFiles[] = $variant;

        $this->assertFileExists($variant);
        $this->assertNotNull(folio_srcset($row->image, $store->id));
    }

    public function test_b6_delete_removes_original_and_variant(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        File::ensureDirectoryExists(public_path('storage/menu'));
        $name = 'fpb6-'.Str::random(8).'.webp';
        $variant = pathinfo($name, PATHINFO_FILENAME).'-w900.webp';
        file_put_contents(public_path('storage/menu/'.$name), 'x');
        file_put_contents(public_path('storage/menu/'.$variant), 'x');
        $row = ImageMenu::create(['store_id' => $store->id, 'name' => 'P', 'image' => $name, 'active' => '1']);

        $row->delete();

        $this->assertFileDoesNotExist(public_path('storage/menu/'.$name));
        $this->assertFileDoesNotExist(public_path('storage/menu/'.$variant));
    }

    public function test_b6_command_backfills_missing_variants(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        File::ensureDirectoryExists(public_path('storage/menu'));
        $name = 'fpb6c-'.Str::random(8).'.webp';
        $src = public_path('storage/menu/'.$name);
        $img = Image::canvas(140, 180, '#cc8844');
        file_put_contents($src, (string) $img->encode('webp', 80));
        $variantPath = public_path('storage/menu/'.pathinfo($name, PATHINFO_FILENAME).'-w900.webp');
        $this->tmpFiles[] = $src;
        $this->tmpFiles[] = $variantPath;
        ImageMenu::create(['store_id' => $store->id, 'name' => 'P', 'image' => $name, 'active' => '1']);

        $this->assertFileDoesNotExist($variantPath);
        Artisan::call('folio:variants', ['--post' => $store->id]);
        $this->assertFileExists($variantPath);
    }

    // ------------------------------------------------------------- B7: auto cover

    private function trackCoverFiles(string $image): void
    {
        $this->tmpFiles[] = public_path('storage/menu/'.$image);
        $this->tmpFiles[] = public_path('storage/menu/'.pathinfo($image, PATHINFO_FILENAME).'-w900.webp');
    }

    public function test_b7_generates_cover_row_and_file(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);

        $res = $this->actingAs($owner)->postJson("/theme-customizer/{$store->id}/folio-cover")
            ->assertOk()->assertJson(['success' => true]);
        $image = $res->json('image');
        $this->trackCoverFiles($image);

        $row = ImageMenu::where('store_id', $store->id)->where('name', 'Cover')->firstOrFail();
        $this->assertSame($image, $row->image);
        $this->assertFileExists(public_path('storage/menu/'.$image));
        $this->assertSame((string) $row->id, (string) store_options($store->id, 'restaurant_folio_cover_id'));
    }

    public function test_b7_regenerate_replaces_previous_cover(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);

        $first = $this->actingAs($owner)->postJson("/theme-customizer/{$store->id}/folio-cover")->assertOk()->json('image');
        $this->trackCoverFiles($first);
        $firstRowId = (int) store_options($store->id, 'restaurant_folio_cover_id');

        $second = $this->actingAs($owner)->postJson("/theme-customizer/{$store->id}/folio-cover")->assertOk()->json('image');
        $this->trackCoverFiles($second);

        // One Cover row, old file cleaned, cover_id points at the new row.
        $this->assertSame(1, ImageMenu::where('store_id', $store->id)->where('name', 'Cover')->count());
        $this->assertFileDoesNotExist(public_path('storage/menu/'.$first));
        $this->assertNotSame($firstRowId, (int) store_options($store->id, 'restaurant_folio_cover_id'));
    }

    public function test_b7_composes_without_a_logo(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner); // main_image = default.png (no real logo)

        $res = $this->actingAs($owner)->postJson("/theme-customizer/{$store->id}/folio-cover")
            ->assertOk()->assertJson(['success' => true]);
        $this->trackCoverFiles($res->json('image'));

        $this->assertSame(1, ImageMenu::where('store_id', $store->id)->where('name', 'Cover')->count());
    }

    // ------------------------------------------------------------- B8: analytics

    public function test_b8_beacon_aggregates_into_one_row(): void
    {
        $store = $this->store(); // template = folio

        $this->postJson("/folio-metrics/{$store->id}", ['page' => 2, 'ms' => 1500])->assertNoContent();
        $this->postJson("/folio-metrics/{$store->id}", ['page' => 2, 'ms' => 2500])->assertNoContent();

        $row = FolioPageStat::where('store_id', $store->id)->where('page', 2)->firstOrFail();
        $this->assertSame(2, (int) $row->views);      // two beacons → one row, views = 2
        $this->assertSame(4000, (int) $row->ms_total); // dwell summed
        $this->assertSame(1, FolioPageStat::where('store_id', $store->id)->count());
    }

    public function test_b8_beacon_ignores_non_folio_store(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'crave');

        $this->postJson("/folio-metrics/{$store->id}", ['page' => 1, 'ms' => 100])->assertNoContent();

        $this->assertSame(0, FolioPageStat::where('store_id', $store->id)->count());
    }

    public function test_b8_beacon_validates_page_and_ms(): void
    {
        $store = $this->store();
        $this->postJson("/folio-metrics/{$store->id}", ['page' => 0, 'ms' => 100])->assertStatus(422);
        $this->postJson("/folio-metrics/{$store->id}", ['page' => 3, 'ms' => 999999])->assertStatus(422);
    }

    public function test_b8_metrics_route_is_csrf_exempt(): void
    {
        $except = new \ReflectionClass(PreventRequestForgery::class)
            ->getDefaultProperties()['except'] ?? [];
        $this->assertContains('folio-metrics/*', $except);
    }

    public function test_b8_summary_math_and_dropoff(): void
    {
        $store = $this->store();
        $day = now()->toDateString();
        FolioPageStat::create(['store_id' => $store->id, 'day' => $day, 'page' => 1, 'views' => 10, 'ms_total' => 1000]);
        FolioPageStat::create(['store_id' => $store->id, 'day' => $day, 'page' => 2, 'views' => 3, 'ms_total' => 500]);
        FolioPageStat::create(['store_id' => $store->id, 'day' => $day, 'page' => 3, 'views' => 5, 'ms_total' => 700]);

        $summary = folio_metrics_summary($store->id);

        $this->assertSame(18, $summary['total']);
        $this->assertSame([1 => 10, 2 => 3, 3 => 5], $summary['pages']);
        $this->assertSame(1, $summary['dropoff']); // biggest single drop is page 1 -> 2 (10 -> 3)
    }

    public function test_b8_summary_excludes_rows_outside_window(): void
    {
        $store = $this->store();
        FolioPageStat::create(['store_id' => $store->id, 'day' => now()->toDateString(), 'page' => 1, 'views' => 4, 'ms_total' => 0]);
        FolioPageStat::create(['store_id' => $store->id, 'day' => now()->subDays(45)->toDateString(), 'page' => 1, 'views' => 99, 'ms_total' => 0]);

        $this->assertSame(4, folio_metrics_summary($store->id, 30)['total']);
    }
}
