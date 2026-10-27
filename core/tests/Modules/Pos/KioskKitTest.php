<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StoreOption;
use App\Models\StoreTable;
use App\Support\KioskContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Database\Seeders\KitDemoSeeder;
use Juwri\Modules\Storefront\Database\Seeders\ThemeShowcaseSeeder;
use Juwri\Modules\Storefront\Http\Middleware\CacheStorefrontResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PinsMiddayClock;
use Tests\Support\TestPlans;
use Tests\Support\ViewModelScenario;
use Tests\TestCase;

/**
 * P3-06: the kiosk in the kit themes (table, brew). The kiosk route draws the theme's own start screen, the store page turns into the
 * kiosk layout (order panel, idle dialog, staff PIN pad), an order is tagged source=kiosk and needs no phone, the done screen replaces the
 * tracking page, the kiosk session cannot leave the storefront (KioskLock) and the staff PIN is verified on the server and throttled.
 * The source-tag tests of the older themes stay in KioskWebModeTest.
 */
class KioskKitTest extends TestCase
{
    use PinsMiddayClock;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_kiosk' => true]);
        $this->shareLayoutGlobals();
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function themes(): array
    {
        return [
            'table (restaurant)' => [ThemeShowcaseSeeder::class, 'crave-demo', 'table'],
            'brew (cafe)' => [KitDemoSeeder::class, 'bloom-coffee-demo', 'brew'],
        ];
    }

    /** a kiosk-enabled store on the kit theme, its owner on a plan that orders */
    private function store(string $seeder, string $slug, string $theme, array $options = []): Store
    {
        $this->seed($seeder);
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'العربية', 'direction' => 'rtl', 'active' => 1]);
        $store = Store::where('slug', $slug)->firstOrFail();
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1, 'settings' => ['restaurant_limit' => 999, 'allow_ordering' => '1']]);
        User::whereKey($store->user_id)->update(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        EntitlementOverride::updateOrCreate(['user_id' => $store->user_id, 'feature_key' => 'allow_ordering'], ['value' => '1', 'source_type' => 'admin']);
        foreach ([
            'store_template' => $theme, 'kiosk_enabled' => '1', 'kiosk_exit_pin' => '4321', 'kiosk_idle_seconds' => '45',
            'menu_languages' => json_encode(['en', 'ar']), 'default_language' => 'en',
        ] + $options as $key => $value) {
            StoreOption::updateStoreOption($store->id, $key, $value);
        }

        return $store;
    }

    /** @return list<array{item: string, qty: int, options: list<string>}> */
    private function cart(Store $store, string $theme): array
    {
        return (new ViewModelScenario($store, $theme))->cart();
    }

    private function order(Store $store, string $theme, string $type, array $extra = [], ?array $cart = null)
    {
        return $this->postJson(route('store.submitOrder', $store), array_merge([
            'lines' => $cart ?? $this->cart($store, $theme), 'order_type' => $type, 'pay_via' => 'cash', 'client_order_id' => (string) Str::uuid(),
        ], $extra));
    }

    #[DataProvider('themes')]
    public function test_the_start_screen_is_the_themes_own_and_marks_the_session(string $seeder, string $slug, string $theme): void
    {
        $store = $this->store($seeder, $slug, $theme);

        $res = $this->get('/'.$slug.'/kiosk')->assertOk();
        $res->assertSee('data-theme-slug="'.$theme.'"', false)->assertSee('data-kiosk-start', false)->assertSee('data-kiosk-go', false)
            ->assertSee('data-kiosk-exit', false)->assertSee('data-kiosk-overlay="pin"', false)->assertSee('?kiosk=1', false)
            ->assertDontSee('id="startBtn"', false);
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertTrue(KioskContext::active((int) $store->id), 'the route marks the session on the server');
        // the kiosk page is an ordinary storefront page for the kit's own boot (idle timer, PIN pad, lock): no second shell on top of it
        $res->assertDontSee('id="kioskExit"', false);
    }

    #[DataProvider('themes')]
    public function test_the_language_buttons_stay_on_the_start_screen_and_a_fresh_start_is_the_store_default(string $seeder, string $slug, string $theme): void
    {
        $this->store($seeder, $slug, $theme);

        $en = $this->get('/'.$slug.'/kiosk')->assertOk();
        $en->assertSee('dir="ltr"', false)->assertSee('/'.$slug.'/kiosk?lang=ar', false);

        $ar = $this->get('/'.$slug.'/kiosk?lang=ar')->assertOk();
        $ar->assertSee('dir="rtl"', false)->assertSee('/'.$slug.'/kiosk?lang=en', false);
        $this->assertSame('ar', $ar->getCookie('Quick_user_lang_code', false)?->getValue());

        // the idle return opens the start screen without a language: the store default again
        $back = $this->get('/'.$slug.'/kiosk')->assertOk();
        $back->assertSee('dir="ltr"', false);
        $this->assertSame('en', $back->getCookie('Quick_user_lang_code', false)?->getValue());

        // a code the store does not offer is the default
        $this->get('/'.$slug.'/kiosk?lang=zz')->assertOk()->assertSee('dir="ltr"', false);
    }

    #[DataProvider('themes')]
    public function test_the_store_page_becomes_the_kiosk_layout_only_for_a_kiosk_session(string $seeder, string $slug, string $theme): void
    {
        $this->store($seeder, $slug, $theme);

        // an ordinary visitor: the normal page (also with ?kiosk=1 — the URL alone never turns the kiosk on)
        $plain = $this->get('/'.$slug.'?kiosk=1')->assertOk()->getContent();
        $this->assertStringNotContainsString('c-kiosk__panel', $plain);
        $this->assertStringNotContainsString('data-kiosk-overlay', $plain);
        $this->assertStringContainsString('c-cartbar', $plain);

        $this->get('/'.$slug.'/kiosk')->assertOk();
        $res = $this->get('/'.$slug.'?kiosk=1')->assertOk();
        $html = $res->getContent();
        $this->assertStringContainsString('p-kiosk', $html);
        foreach (['c-main c-kiosk', 'c-kiosk__cats', 'c-kiosk__panel', 'data-kiosk-a11y', 'data-kiosk-overlay="idle"', 'data-kiosk-overlay="pin"', 'data-kiosk-continue', 'data-kiosk-startover', 'data-kiosk-exit', 'data-juwri-open="checkout"'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        // what a shared device does not carry: account, share, the cart bar and drawer, the kitchen note, other pages
        foreach (['c-pill--account', 'c-header__share', 'c-cartbar', 'id="kioskExit"', 'data-kit-share'] as $gone) {
            $this->assertStringNotContainsString($gone, $html, $gone);
        }
        $this->assertStringContainsString('"kiosk":{"on":true', $html, 'the boot data carries the kiosk block for the core JS');
        $this->assertStringContainsString('private', (string) $res->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('max-age', (string) $res->headers->get('Cache-Control'), 'never a shared copy of the kiosk layout');
    }

    #[DataProvider('themes')]
    public function test_the_checkout_dialog_of_the_kiosk_asks_for_an_optional_name_only(string $seeder, string $slug, string $theme): void
    {
        $store = $this->store($seeder, $slug, $theme, ['allow_tips' => '1']);
        $this->get('/'.$slug.'/kiosk')->assertOk();
        $html = $this->get('/'.$slug.'?kiosk=1')->assertOk()->getContent();
        $this->assertStringContainsString('name="name"', $html);
        $this->assertStringContainsString('(optional)', $html);
        $this->assertStringNotContainsString('name="phone-number"', $html, 'no phone on a shared device');
        $this->assertStringNotContainsString('name="address"', $html);
        $this->assertStringNotContainsString('name="message"', $html);
        $this->assertStringNotContainsString('tip_', $html, 'no tip at the kiosk');
    }

    #[DataProvider('themes')]
    public function test_a_kiosk_order_needs_no_phone_is_tagged_kiosk_and_the_done_screen_replaces_the_tracking_page(string $seeder, string $slug, string $theme): void
    {
        $store = $this->store($seeder, $slug, $theme);
        $vm = (new ViewModelScenario($store, $theme))->build('en', 'home', ['kiosk' => true]);
        $types = $vm['context']['kiosk']['order_types'];
        $this->assertNotSame([], $types);
        $this->assertSame([], array_values(array_intersect($types, ['delivery', 'ship', 'at_home', 'room_service'])), 'only eat in / take away at a kiosk');

        // the same cart from an ordinary browser still needs the name and the phone
        $normal = $this->order($store, $theme, in_array('takeaway', $types, true) ? 'takeaway' : $types[0])->assertOk()->json();
        $this->assertFalse($normal['success'], 'outside the kiosk the contact rules stand');

        $this->get('/'.$slug.'/kiosk')->assertOk();
        $before = Order::where('store_id', $store->id)->count();
        foreach ($types as $i => $type) {
            $this->order($store, $theme, $type)->assertOk()->assertJson(['success' => true]);
            $order = Order::where('store_id', $store->id)->latest('id')->firstOrFail();
            $this->assertSame('kiosk', $order->source, $type);
            $this->assertSame(___('Guest'), $order->customer_name);
            $this->assertEmpty($order->phone_number);
            $this->assertNull($order->table_number);
            $this->assertSame($before + $i + 1, Order::where('store_id', $store->id)->count());
        }

        $done = $this->get(route('store.orderTrack', $order->track_token))->assertOk();
        $html = $done->getContent();
        foreach (['data-kiosk-done', 'c-kdone__no', 'data-kiosk-startnow', 'data-kiosk-left', 'data-seconds="10"'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertMatchesRegularExpression('/c-kdone__no">.*?<bdi[^>]*>[^<]+</s', $html, 'the big order number');
        $this->assertStringNotContainsString('c-topbar', $html, 'the tracking page is not the kiosk\'s done screen');
        $this->assertStringContainsString('/'.$slug.'/kiosk', $html, 'Start now returns to the start screen');
    }

    #[DataProvider('themes')]
    public function test_a_normal_visitor_still_gets_the_tracking_page(string $seeder, string $slug, string $theme): void
    {
        $store = $this->store($seeder, $slug, $theme);
        StoreTable::firstOrCreate(['store_id' => $store->id, 'key' => 'T4'], ['table_no' => 4, 'label' => 'T4', 'seats' => 4, 'status' => 'free', 'area_type' => 'table', 'branch_id' => null]);
        $this->order($store, $theme, 'dine_in', ['name' => 'Sam', 'phone-number' => '+966500000001', 'table' => 'T4'])->assertJson(['success' => true]);
        $order = Order::where('store_id', $store->id)->latest('id')->firstOrFail();
        $this->assertNotSame('kiosk', $order->source);
        $html = $this->get(route('store.orderTrack', $order->track_token))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-kiosk-done', $html);
        $this->assertStringContainsString('c-topbar', $html);
    }

    #[DataProvider('themes')]
    public function test_the_kiosk_session_cannot_leave_the_storefront(string $seeder, string $slug, string $theme): void
    {
        $store = $this->store($seeder, $slug, $theme);
        $this->get('/'.$slug.'/kiosk')->assertOk();

        // dashboard, login, the marketing site: back to the start screen
        foreach (['/login', '/pricing', '/'] as $path) {
            $this->get($path)->assertRedirect('/'.$slug.'/kiosk');
        }
        // the dashboard asks for a sign-in first (that redirect is the auth middleware's); the login page then goes back to the start screen
        $this->followingRedirects()->get('/dashboard')->assertSee('data-kiosk-start', false);
        // a script request gets a 403, never a page
        $this->getJson('/pricing')->assertForbidden()->assertJsonPath('ok', false);
        $this->postJson('/newsletter', ['email' => 'a@b.test'])->assertForbidden();

        // another store, even though it is a storefront
        $other = Store::where('slug', '!=', $slug)->first();
        if ($other !== null) {
            $this->get('/'.$other->slug)->assertRedirect('/'.$slug.'/kiosk');
        }

        // its own storefront keeps working: the page, the quote, the order, tracking, the language switch
        $this->get('/'.$slug.'?kiosk=1')->assertOk();
        $cart = $this->cart($store, $theme);
        $this->postJson('/'.$slug.'/quote', ['lines' => $cart, 'order_type' => 'dine_in'])->assertOk();
        $vm = (new ViewModelScenario($store, $theme))->build('en', 'home', ['kiosk' => true]);
        $this->order($store, $theme, $vm['context']['kiosk']['order_types'][0])->assertJson(['success' => true]);
        $order = Order::where('store_id', $store->id)->latest('id')->firstOrFail();
        $this->get(route('store.orderTrack', $order->track_token))->assertOk();
        $this->get('/'.$slug.'/kiosk?lang=ar')->assertOk();
    }

    public function test_an_order_of_another_store_cannot_be_tracked_from_a_kiosk_session(): void
    {
        $a = $this->store(ThemeShowcaseSeeder::class, 'crave-demo', 'table');
        $b = $this->store(KitDemoSeeder::class, 'bloom-coffee-demo', 'brew');
        StoreTable::firstOrCreate(['store_id' => $b->id, 'key' => 'T4'], ['table_no' => 4, 'label' => 'T4', 'seats' => 4, 'status' => 'free', 'area_type' => 'table', 'branch_id' => null]);
        $this->order($b, 'brew', 'dine_in', ['name' => 'Sam', 'phone-number' => '+966500000001', 'table' => 'T4'])->assertJson(['success' => true]);
        $foreign = Order::where('store_id', $b->id)->latest('id')->firstOrFail();

        $this->get('/crave-demo/kiosk')->assertOk();
        $this->get(route('store.orderTrack', $foreign->track_token))->assertRedirect('/crave-demo/kiosk');
        $this->assertNotSame($a->id, $b->id);
    }

    #[DataProvider('themes')]
    public function test_the_staff_exit_ends_the_lock_and_a_signed_in_owner_is_never_locked(string $seeder, string $slug, string $theme): void
    {
        $store = $this->store($seeder, $slug, $theme);
        $this->get('/'.$slug.'/kiosk')->assertOk();
        $this->get('/login')->assertRedirect('/'.$slug.'/kiosk');

        $this->postJson('/'.$slug.'/kiosk/exit', ['pin' => '4321'])->assertOk()->assertJson(['ok' => true]);
        $this->assertFalse(KioskContext::active((int) $store->id));
        $this->get('/login')->assertOk();

        // the owner previewing the kiosk page in their own browser
        $this->actingAs(User::findOrFail($store->user_id));
        $this->get('/'.$slug.'/kiosk')->assertOk();
        $this->assertNotSame(url('/'.$slug.'/kiosk'), $this->get('/dashboard')->headers->get('Location'), 'a signed-in user is never sent to the kiosk');
    }

    #[DataProvider('themes')]
    public function test_there_is_no_lock_when_the_owner_switches_the_kiosk_off(string $seeder, string $slug, string $theme): void
    {
        $store = $this->store($seeder, $slug, $theme);
        $this->get('/'.$slug.'/kiosk')->assertOk();
        StoreOption::updateStoreOption($store->id, 'kiosk_enabled', '0');
        $this->get('/login')->assertOk();
        $this->get('/'.$slug)->assertOk()->assertDontSee('c-kiosk__panel', false);
    }

    #[DataProvider('themes')]
    public function test_the_staff_pin_is_throttled_per_device_and_per_store(string $seeder, string $slug, string $theme): void
    {
        $store = $this->store($seeder, $slug, $theme, ['kiosk_setup_pin' => '2468']);
        RateLimiter::clear('kiosk-exit:'.$store->id.':127.0.0.1');
        $this->get('/'.$slug.'/kiosk')->assertOk();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/'.$slug.'/kiosk/exit', ['pin' => '000'.$i])->assertOk()->assertJson(['ok' => false]);
        }
        // the sixth try is refused even with the right PIN, until the window passes
        $this->postJson('/'.$slug.'/kiosk/exit', ['pin' => '4321'])->assertStatus(429)->assertJson(['ok' => false, 'throttled' => true]);
        $this->assertTrue(KioskContext::active((int) $store->id), 'a refused try never leaves the kiosk');

        // the setup PIN has its own count
        $this->postJson('/'.$slug.'/kiosk/setup', ['pin' => '2468'])->assertOk()->assertJson(['ok' => true]);

        RateLimiter::clear('kiosk-exit:'.$store->id.':127.0.0.1');
        $this->postJson('/'.$slug.'/kiosk/exit', ['pin' => '4321'])->assertOk()->assertJson(['ok' => true]);
    }

    public function test_an_empty_exit_pin_never_matches_and_a_pin_is_never_accepted_from_the_query(): void
    {
        $store = $this->store(ThemeShowcaseSeeder::class, 'crave-demo', 'table', ['kiosk_exit_pin' => '']);
        $this->get('/crave-demo/kiosk')->assertOk();
        $this->postJson('/crave-demo/kiosk/exit', ['pin' => ''])->assertOk()->assertJson(['ok' => false]);
        $this->postJson('/crave-demo/kiosk/exit?pin=', [])->assertOk()->assertJson(['ok' => false]);
        $this->assertTrue(KioskContext::active((int) $store->id));
    }

    #[DataProvider('themes')]
    public function test_the_kiosk_never_fills_or_reads_the_guests_page_cache(string $seeder, string $slug, string $theme): void
    {
        $this->store($seeder, $slug, $theme);
        // lift only the test-runner guard of the full-page cache, as StorefrontFullPageCacheTest does
        app()->instance(CacheStorefrontResponse::class, new class extends CacheStorefrontResponse
        {
            protected function disabledForTesting(): bool
            {
                return false;
            }
        });
        config(['perf.storefront_fullpage_cache' => true, 'perf.storefront_fullpage_cache_canary' => $slug]);
        Cache::flush();

        $this->get('/'.$slug.'/kiosk')->assertOk();
        $kiosk = $this->get('/'.$slug)->assertOk();
        $this->assertStringContainsString('c-kiosk__panel', $kiosk->getContent());
        $this->assertNull($kiosk->headers->get('X-Storefront-Cache'));

        // an ordinary browser (a fresh session) after it: the normal page, never the kiosk layout out of the cache
        $this->flushSession();
        $this->app['session']->driver()->flush();
        $normal = $this->get('/'.$slug)->assertOk();
        $this->assertStringNotContainsString('c-kiosk__panel', $normal->getContent());
        $this->assertSame('MISS', $normal->headers->get('X-Storefront-Cache'), 'the guests\' cache was empty: the kiosk page did not fill it');
    }

    #[DataProvider('themes')]
    public function test_the_language_link_of_a_kit_page_switches_the_page(string $seeder, string $slug, string $theme): void
    {
        $this->store($seeder, $slug, $theme);
        $this->get('/'.$slug)->assertOk()->assertSee('dir="ltr"', false);
        $res = $this->get('/'.$slug.'?lang=ar')->assertOk();
        $res->assertSee('dir="rtl"', false);
        $this->assertSame('ar', $res->getCookie('Quick_user_lang_code', false)?->getValue());
        // not a language of the store: ignored
        $this->get('/'.$slug.'?lang=zz')->assertOk()->assertSee('dir="ltr"', false);
    }
}
