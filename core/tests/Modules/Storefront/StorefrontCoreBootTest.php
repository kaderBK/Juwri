<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Storefront\Database\Seeders\KitDemoSeeder;
use Juwri\Modules\Storefront\Http\Middleware\MarkAnonymousPage;
use Juwri\Modules\Storefront\Services\Storefront\StorefrontBoot;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelBuilder;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelRequest;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * P3-03: what the storefront core JS (assets/storefront/*.js) is handed — the boot data, the menu snapshot, the
 * service worker file, the page partial — and the strings it reads.
 */
class StorefrontCoreBootTest extends TestCase
{
    use RefreshDatabase;

    private const LOCALES = ['en', 'ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->seed(KitDemoSeeder::class);
    }

    private function store(string $slug = 'bloom-coffee-demo'): Store
    {
        return Store::where('slug', $slug)->firstOrFail();
    }

    private function assets(): string
    {
        return dirname(base_path()).'/assets/storefront';
    }

    /** @return array<string, string> key => English text, from every `text('key', 'English')` call in the core JS */
    private function jsTexts(): array
    {
        $out = [];
        foreach (glob($this->assets().'/*.js') as $file) {
            preg_match_all('/text\(\s*\'([a-z0-9_]+)\'\s*,\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")\s*\)/', (string) file_get_contents($file), $m, PREG_SET_ORDER);
            foreach ($m as $hit) {
                $out[$hit[1]] = stripcslashes($hit[2] !== '' ? $hit[2] : ($hit[3] ?? ''));
            }
        }

        return $out;
    }

    public function test_every_string_the_core_js_reads_is_in_the_boot_data_and_translated_in_all_locales(): void
    {
        $texts = $this->jsTexts();
        $this->assertGreaterThan(15, count($texts), 'the JS text calls were found');

        foreach ($texts as $key => $english) {
            $this->assertSame(Str::slug($english, '_'), $key, "the JS key '{$key}' is the slug of its English text");
        }

        foreach (self::LOCALES as $locale) {
            $boot = app(ViewModelBuilder::class)->boot(new ViewModelRequest(store: $this->store(), locale: $locale));
            foreach ($texts as $key => $english) {
                $this->assertArrayHasKey($key, $boot['i18n'], "boot i18n[{$key}] ({$locale})");
                if ($locale !== 'en') {
                    $this->assertNotSame('', trim($boot['i18n'][$key]));
                    $this->assertNotSame($english, $boot['i18n'][$key], "{$locale}: '{$key}' is translated, not English");
                } else {
                    $this->assertSame($english, $boot['i18n'][$key]);
                }
            }
        }
    }

    public function test_the_new_p303_strings_exist_in_every_locale_and_differ_from_english(): void
    {
        foreach ([
            'removed_from_your_order', 'sending_your_order', 'needs_your_confirmation', 'order_expired', 'could_not_place_the_order',
            'offline_prices_may_change', 'some_items_are_no_longer_available', 'offline_order_queue', 'let_storefronts_queue_orders_while_offline',
            'queued_order_lifetime_minutes', 'storefront_offline_ordering', 'let_my_stores_queue_orders_while_offline',
            'offline_ordering_is_switched_off_please_place_the_order_again', 'this_order_waited_too_long_please_place_it_again',
            'one_or_more_selected_items_are_not_available',
        ] as $key) {
            $english = Lang::get("lang.{$key}", [], 'en');
            $this->assertNotSame("lang.{$key}", $english, "en has {$key}");
            foreach (array_diff(self::LOCALES, ['en']) as $locale) {
                $text = Lang::get("lang.{$key}", [], $locale);
                $this->assertNotSame("lang.{$key}", $text, "{$locale} has {$key}");
                $this->assertNotSame($english, $text, "{$locale}: {$key} is translated");
            }
        }
        $this->assertStringContainsString(':n', Lang::get('lang.still_there_starting_over_in_n_seconds', [], 'ar'), 'the placeholder survives translation');
    }

    public function test_boot_data_names_the_endpoints_the_queue_and_the_per_store_worker(): void
    {
        $store = $this->store();
        $boot = app(StorefrontBoot::class)->forStore($store);

        $this->assertSame((string) $store->slug, $boot['store']['slug']);
        $this->assertSame((string) $store->public_id, $boot['store']['public_id']);
        $this->assertSame('/'.$store->slug.'/quote', $boot['endpoints']['quote']);
        $this->assertSame('/'.$store->id.'/send-order', $boot['endpoints']['place'], 'the checkout route binds the store by its id');
        $this->assertSame('/'.$store->slug.'/storefront/snapshot.json?l=en', $boot['endpoints']['snapshot']);
        $this->assertTrue($boot['offline_queue']['enabled']);
        $this->assertSame(30, $boot['offline_queue']['expiry_min']);
        $this->assertNotContains('delivery', $boot['offline_queue']['order_types']);
        $this->assertArrayNotHasKey('catalog', $boot, 'boot data is cheap: no catalogue');

        parse_str((string) parse_url($boot['sw']['url'], PHP_URL_QUERY), $q);
        $this->assertStringStartsWith('/sw-store.js?', $boot['sw']['url']);
        $this->assertSame((string) $store->slug, $q['s']);
        $this->assertSame('1', $q['oq']);
        $this->assertSame((string) config('appinfo.version'), $q['v']);
        $this->assertSame('/'.$store->slug, $boot['sw']['scope'], 'one worker per store address (path form host)');

        StoreSettings::set((int) $store->id, 'offline_queue_enabled', false);
        $off = app(StorefrontBoot::class)->forStore($store);
        $this->assertFalse($off['offline_queue']['enabled']);
        parse_str((string) parse_url($off['sw']['url'], PHP_URL_QUERY), $q2);
        $this->assertSame('0', $q2['oq'], 'a flipped switch changes the worker URL, so the next load installs the new worker');
    }

    public function test_the_snapshot_is_anonymous_versioned_and_revalidates(): void
    {
        $store = $this->store();
        $first = $this->getJson("/{$store->slug}/storefront/snapshot.json");
        $first->assertOk()->assertHeader('Cache-Control', 'no-cache, public');
        $body = $first->json();
        $this->assertSame(16, strlen($body['version']));
        $this->assertSame((string) $store->slug, $body['snapshot']['store']['slug']);
        $this->assertNotEmpty($body['snapshot']['catalog']['items']);
        $this->assertArrayNotHasKey('customer', $body['snapshot']);
        $this->assertArrayNotHasKey('page', $body['snapshot']);

        $etag = $first->headers->get('ETag');
        $this->assertSame('"'.$body['version'].'"', $etag);
        $this->getJson("/{$store->slug}/storefront/snapshot.json", ['If-None-Match' => $etag])->assertStatus(304);

        $other = $this->getJson('/'.$this->store('fresh-mart-demo')->slug.'/storefront/snapshot.json')->json();
        $this->assertNotSame($body['version'], $other['version']);
        $this->assertSame([], array_intersect(array_keys($body['snapshot']['catalog']['items']), array_keys($other['snapshot']['catalog']['items'])), 'no item of another store');

        $this->getJson('/no-such-store-xyz/storefront/snapshot.json')->assertNotFound();
    }

    public function test_the_page_partial_prints_the_boot_block_and_loads_the_module_with_the_nonce(): void
    {
        $html = view('store_templates._partials.storefront-core', ['store' => $this->store()])->render();

        $this->assertMatchesRegularExpression('#<script type="application/json" data-juwri-boot nonce="[^"]+">#', $html);
        $this->assertMatchesRegularExpression('#<script type="module" src="[^"]*/assets/storefront/core\.js\?v=\d*" nonce="[^"]+"></script>#', $html);
        $this->assertStringContainsString('assets/storefront/core.css', $html);

        preg_match('#data-juwri-boot nonce="[^"]+">(.*?)</script>#s', $html, $m);
        $json = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
        $this->assertIsArray($json, 'the block is valid JSON');
        $this->assertSame('bloom-coffee-demo', $json['store']['slug']);
        $this->assertStringNotContainsString('</script', $m[1], 'nothing can close the block early');
    }

    public function test_a_kit_theme_does_not_register_the_shared_menu_worker_other_themes_do(): void
    {
        $layout = (string) file_get_contents(resource_path('views/store_templates/layout.blade.php'));
        $this->assertStringContainsString('KitThemes::isKit(', $layout);
        $this->assertMatchesRegularExpression('/@if\(\$__legacySw\).*?\/sw-menu\.js\?v=.*?@endif/s', $layout);
    }

    public function test_the_service_worker_is_per_store_and_never_caches_private_pages_or_other_scopes(): void
    {
        $sw = (string) file_get_contents(dirname(base_path()).'/sw-store.js');

        $this->assertStringContainsString('new URL(self.registration.scope).pathname', $sw, 'cache names carry the scope');
        $this->assertStringContainsString("if (cc.indexOf('no-store') !== -1) return false;", $sw, 'no-store is never kept');
        $this->assertStringContainsString("if (cc.indexOf('private') === -1) return true;", $sw);
        $this->assertStringContainsString('inScope(req.url)', $sw, 'pages outside the scope are never kept');
        $this->assertStringContainsString("'juwri-sf:' + self.location.host + ':' + SLUG", $sw, 'the queue database is per store and host');
        $this->assertStringContainsString("P.get('oq') !== '0'", $sw, 'the effective kill switch arrives in the worker URL');
        $this->assertStringContainsString("type: 'juwri:queue-dropped'", $sw);
        $this->assertDoesNotMatchRegularExpression('#https?://#', $sw, 'no external host');

        // the shell list names every core module (and nothing that does not exist)
        preg_match('/const CORE = \[(.*?)\]\.map/s', $sw, $m);
        preg_match_all("/'([a-z]+)'/", $m[1], $names);
        $listed = $names[1];
        sort($listed);
        $files = array_map(fn ($f) => basename($f, '.js'), glob($this->assets().'/*.js'));
        sort($files);
        $this->assertSame($files, $listed);
    }

    public function test_no_core_js_file_computes_money_or_writes_html(): void
    {
        foreach (glob($this->assets().'/*.js') as $file) {
            $src = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/innerHTML|insertAdjacentHTML|document\.write|\beval\(/', $src, basename($file));
        }
    }

    public function test_the_three_switches_have_their_screens(): void
    {
        $admin = view('admin.settings.feature-controls', ['settings' => (object) []])->render();
        $this->assertStringContainsString('name="offline_queue_enabled"', $admin);
        $off = view('admin.settings.feature-controls', ['settings' => (object) ['offline_queue_enabled' => '0']])->render();
        $this->assertNotSame($admin, $off);

        $owner = (string) file_get_contents(resource_path('views/owner/stores/edit.blade.php'));
        $this->assertStringContainsString('name="offline_queue_enabled"', $owner);
        $this->assertStringContainsString('name="offline_queue_expiry_min"', $owner);
        $this->assertContains('offline_queue_enabled', $this->allowedStoreSettings());
        $this->assertContains('offline_queue_expiry_min', $this->allowedStoreSettings());
    }

    /** @return list<string> */
    private function allowedStoreSettings(): array
    {
        $src = (string) file_get_contents(app_path('Core/Services/Store/StoreSettingsService.php'));
        preg_match_all("/^\s+'([a-z0-9_]+)',/m", $src, $m);

        return $m[1];
    }

    public function test_a_guest_page_is_vouched_for_so_the_worker_may_keep_it_and_nothing_personal_is(): void
    {
        $page = fn (int $status = 200, string $type = 'text/html; charset=utf-8') => fn () => new Response('<html></html>', $status, ['Content-Type' => $type, 'Cache-Control' => 'no-cache, private']);
        $mw = new MarkAnonymousPage;
        $get = Request::create('/bloom', 'GET');

        $this->assertSame('1', $mw->handle($get, $page())->headers->get('X-Juwri-Anonymous'), 'a guest page');
        $this->assertNull($mw->handle($get, $page(404))->headers->get('X-Juwri-Anonymous'), 'an error page');
        $this->assertNull($mw->handle($get, $page(200, 'application/json'))->headers->get('X-Juwri-Anonymous'), 'JSON');
        $this->assertNull($mw->handle(Request::create('/bloom', 'POST'), $page())->headers->get('X-Juwri-Anonymous'), 'a POST');

        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $this->actingAs($user);
        $this->assertNull($mw->handle($get, $page())->headers->get('X-Juwri-Anonymous'), 'a signed-in owner is never vouched for');
        $this->assertSame('1', $mw->handle($get, $page(), 'own')->headers->get('X-Juwri-Anonymous'), "the shopper's own track page");

        $routes = app('router')->getRoutes();
        $this->assertContains(MarkAnonymousPage::class, $routes->getByName('publicView')->gatherMiddleware());
        $this->assertContains(MarkAnonymousPage::class.':own', $routes->getByName('store.orderTrack')->gatherMiddleware());

        $sw = (string) file_get_contents(dirname(base_path()).'/sw-store.js');
        $this->assertStringContainsString("response.headers.get('X-Juwri-Anonymous') === '1'", $sw);
    }
}
