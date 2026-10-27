<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use App\Models\WaitlistEntry;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Http\Middleware\TrackOwnerScreenView;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Support\OwnerPwa;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Messaging\Jobs\SendMobilePushJob;
use Juwri\Modules\Messaging\Jobs\SendPushNotificationJob;
use Juwri\Modules\Messaging\Models\PushSubscription;
use Juwri\Modules\Messaging\Services\MobilePush\MobilePushService;
use Juwri\Modules\Messaging\Services\StaffPush\StaffPushService;
use Juwri\Modules\Messaging\Services\StaffPush\WebPushSender;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Owner dashboard PWA (rev/mobile-app/PWA-SPEC.md): manifest, service worker,
 * offline page, icons, owner web push lane, layout wiring and usage tracking.
 */
class OwnerPwaTest extends TestCase
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
        $this->withoutMiddleware([Installed::class, Locale::class, PreventRequestForgery::class]);
        View::share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => (object) [],
            'languages' => collect(),
            'admin_languages' => collect(),
        ]);
        config(['settings.admin_allow_mobile_app_push' => true, 'webpush.public_key' => 'BPublicVapidKeyForTests']);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]),
        ]);

        return User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
            'username' => 'owner'.Str::lower(Str::random(6)), 'email' => Str::lower(Str::random(8)).'@owner.test',
        ]);
    }

    private function store(User $owner, string $type = 'restaurant'): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R '.Str::random(4), 'sub_title' => 't', 'description' => 't',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'store_type' => $type, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function webSub(string $endpoint = 'https://push.example.com/abc'): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BKey', 'auth' => 'AuthKey']];
    }

    // ── manifest / sw / offline / icons ───────────────────────────────────────

    public function test_manifest_is_installable_json(): void
    {
        $res = $this->get(route('owner.pwa.manifest'));

        $res->assertOk();
        $this->assertStringContainsString('application/manifest+json', (string) $res->headers->get('Content-Type'));
        $m = json_decode((string) $res->getContent(), true);
        $this->assertSame('standalone', $m['display']);
        $this->assertSame('/', $m['scope']);
        $this->assertSame('/dashboard?source=pwa', $m['start_url']);
        $purposes = array_column($m['icons'], 'purpose');
        $this->assertContains('maskable', $purposes);
        $this->assertContains('512x512', array_column($m['icons'], 'sizes'));
    }

    public function test_manifest_adds_store_shortcuts_for_a_signed_in_owner(): void
    {
        $owner = $this->owner();
        $this->store($owner);

        $m = json_decode((string) $this->actingAs($owner)->get(route('owner.pwa.manifest'))->getContent(), true);

        $this->assertNotEmpty($m['shortcuts'] ?? []);
    }

    public function test_icons_are_png_in_every_size(): void
    {
        foreach ([192, 512] as $size) {
            $res = $this->get(route('owner.pwa.icon', ['size' => $size]));
            $res->assertOk()->assertHeader('Content-Type', 'image/png');
            $info = getimagesizefromstring((string) $res->getContent());
            $this->assertSame([$size, $size], [$info[0], $info[1]]);
        }
        $this->get(route('owner.pwa.icon', ['size' => 512, 'maskable' => 1]))->assertOk();
    }

    public function test_service_worker_only_handles_navigations_and_never_caches_pages(): void
    {
        $res = $this->get(route('owner.pwa.sw'));

        $res->assertOk();
        $this->assertStringContainsString('javascript', (string) $res->headers->get('Content-Type'));
        $this->assertSame('/', $res->headers->get('Service-Worker-Allowed'));
        $this->assertStringContainsString('no-cache', (string) $res->headers->get('Cache-Control'));
        $js = (string) $res->getContent();
        $this->assertStringContainsString("event.request.mode !== 'navigate'", $js);
        $this->assertStringContainsString(OwnerPwa::SW_VERSION, $js);
        $this->assertStringContainsString('owner_url', $js);
        // Only the offline page + icon are ever cached (installed, and re-seeded if
        // another worker on the origin cleared the caches).
        $this->assertSame(substr_count($js, 'addAll('), substr_count($js, 'addAll([OFFLINE_URL, ICON])'));
        $this->assertStringNotContainsString('cache.put', $js);
    }

    public function test_offline_page_is_public_and_branded(): void
    {
        $this->get(route('owner.pwa.offline'))->assertOk()->assertSee('You are offline');
    }

    // ── layout wiring ─────────────────────────────────────────────────────────

    public function test_dashboard_links_the_manifest_and_registers_the_worker(): void
    {
        $owner = $this->owner();
        $this->store($owner);

        $html = (string) $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('rel="manifest"', $html);
        $this->assertStringContainsString('name="owner-pwa"', $html);
        $this->assertStringContainsString('owner-ui/pwa.js', $html);
        $this->assertStringContainsString('data-owner-pwa-card', $html);
    }

    public function test_native_app_gets_no_pwa_wiring(): void
    {
        $owner = $this->owner();
        $this->store($owner);

        $html = (string) $this->actingAs($owner)
            ->withHeader('User-Agent', 'JuwriApp/1.0 (ios) Hotwire Native iOS; Mozilla/5.0')
            ->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('rel="manifest"', $html);
        $this->assertStringNotContainsString('owner-ui/pwa.js', $html);
        $this->assertStringNotContainsString('data-owner-pwa-card', $html);
    }

    // ── owner web push ────────────────────────────────────────────────────────

    public function test_owner_subscribes_the_current_store_on_the_owner_lane(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);

        $this->actingAs($owner)->postJson(route('owner.pwa.push.subscribe').'?store_id='.$store->id, [
            'subscription' => $this->webSub(), 'device_name' => 'Chrome · Android',
        ])->assertOk()->assertJsonPath('success', true);

        $sub = PushSubscription::sole();
        $this->assertSame('web', $sub->platform);
        $this->assertSame($owner->id, (int) $sub->user_id);
        $this->assertSame($store->id, (int) $sub->store_id);
        $this->assertNull($sub->staff_id);
        $this->assertTrue((bool) $sub->notify_new_order);
        $this->assertSame('Chrome · Android', $sub->device_name);
    }

    public function test_subscribe_requires_auth_and_a_https_endpoint_and_own_store(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $other = $this->store($this->owner());

        $this->postJson(route('owner.pwa.push.subscribe'), ['subscription' => $this->webSub()])->assertUnauthorized();
        $this->actingAs($owner)->postJson(route('owner.pwa.push.subscribe').'?store_id='.$store->id, [
            'subscription' => $this->webSub('http://insecure.example.com/x'),
        ])->assertStatus(422);
        $this->actingAs($owner)->postJson(route('owner.pwa.push.subscribe').'?store_id='.$other->id, [
            'subscription' => $this->webSub(),
        ])->assertForbidden();
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_unsubscribe_removes_only_the_callers_row(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $intruder = $this->owner();
        PushSubscription::storeWeb($this->webSub(), $store->id, $owner->id);

        $this->actingAs($intruder)->postJson(route('owner.pwa.push.unsubscribe'), ['endpoint' => 'https://push.example.com/abc'])
            ->assertOk()->assertJsonPath('removed', false);
        $this->actingAs($owner)->postJson(route('owner.pwa.push.unsubscribe'), ['endpoint' => 'https://push.example.com/abc'])
            ->assertOk()->assertJsonPath('removed', true);
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_owner_events_reach_owner_web_subs_but_not_staff_web_subs(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        PushSubscription::storeWeb($this->webSub('https://push.example.com/owner'), $store->id, $owner->id);
        PushSubscription::store($this->webSub('https://push.example.com/staff'), $store->id, 99);
        PushSubscription::storeNative('fcm-token', 'fcm', $store->id, null, $owner->id);

        $lane = MobilePushService::ownerLane($store->id)->pluck('endpoint')->all();

        $this->assertContains('https://push.example.com/owner', $lane);
        $this->assertContains('fcm-token', $lane);
        $this->assertNotContains('https://push.example.com/staff', $lane);
        // …and the settings device list shows the owner's browser install.
        $this->assertCount(2, MobilePushService::devices($store->id));
    }

    // ── service worker scope (PWA-SPEC §3.2) ──────────────────────────────────

    public function test_service_worker_answers_dashboard_navigations_only(): void
    {
        $prefixes = OwnerPwa::dashboardPrefixes();
        foreach (['dashboard', 'orders', 'reports', 'settings', 'reservations', 'restaurant'] as $area) {
            $this->assertContains($area, $prefixes);
        }
        $this->assertNotContains('api', $prefixes);
        $this->assertNotContains('admin', $prefixes);

        $js = (string) $this->get(route('owner.pwa.sw'))->getContent();
        $this->assertStringContainsString('const DASHBOARD = new Set(["', $js);
        $this->assertStringContainsString('!isDashboard(event.request.url)', $js);
        // Its own old caches only — the staff app / POS / driver workers share the origin.
        $this->assertStringContainsString("k.startsWith('owner-pwa-')", $js);
        $this->assertStringStartsWith('owner-pwa-', OwnerPwa::SW_VERSION);
        // A preload the worker never consumes would fetch storefront pages twice.
        $this->assertStringContainsString('navigationPreload.disable()', $js);
        $this->assertStringNotContainsString('preloadResponse', $js);
    }

    // ── owner lane: toggles, test push, deep links (PWA-SPEC §3.3) ────────────

    /** @return array<int, string> endpoints the fake sender was called with */
    private function captureSends(): \ArrayObject
    {
        $seen = new \ArrayObject;
        $this->app->instance(WebPushSender::class, new class($seen) implements WebPushSender
        {
            public function __construct(private \ArrayObject $seen) {}

            public function send(PushSubscription $sub, array $payload): bool
            {
                $this->seen[] = ['endpoint' => $sub->endpoint, 'payload' => $payload];

                return true;
            }
        });

        return $seen;
    }

    private function twoLanes(): array
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        PushSubscription::storeWeb($this->webSub('https://push.example.com/owner'), $store->id, $owner->id);
        PushSubscription::store($this->webSub('https://push.example.com/staff'), $store->id, 99);

        return [$owner, $store];
    }

    public function test_new_order_toggle_silences_the_owner_pwa_but_not_staff_browsers(): void
    {
        [, $store] = $this->twoLanes();
        $seen = $this->captureSends();

        StoreOption::updateStoreOption($store->id, 'mobile_push_new_order', '0');
        (new StaffPushService)->sendNewOrder((int) $store->id, ['title' => 'New order #1', 'order_id' => 1]);

        $this->assertSame(['https://push.example.com/staff'], array_column($seen->getArrayCopy(), 'endpoint'));
    }

    public function test_owner_pwa_keeps_new_order_alerts_without_the_app_add_on(): void
    {
        [, $store] = $this->twoLanes();
        $seen = $this->captureSends();
        config(['settings.admin_allow_mobile_app_push' => false]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();

        (new StaffPushService)->sendNewOrder((int) $store->id, ['title' => 'New order #1', 'order_id' => 1]);

        $this->assertEqualsCanonicalizing(
            ['https://push.example.com/owner', 'https://push.example.com/staff'],
            array_column($seen->getArrayCopy(), 'endpoint')
        );
    }

    public function test_status_reservation_and_waitlist_respect_their_toggles_on_the_owner_pwa(): void
    {
        [, $store] = $this->twoLanes();
        $seen = $this->captureSends();

        StoreOption::updateStoreOption($store->id, 'mobile_push_order_status', '0');
        $this->assertSame(0, MobilePushService::deliver((int) $store->id, MobilePushService::EVENT_ORDER_STATUS, ['title' => 'x']));

        $this->assertSame(1, MobilePushService::deliver((int) $store->id, MobilePushService::EVENT_RESERVATION, ['title' => 'x']));
        $this->assertSame(['https://push.example.com/owner'], array_column($seen->getArrayCopy(), 'endpoint'));
    }

    public function test_the_test_push_reaches_the_owner_pwa_even_with_every_toggle_off(): void
    {
        [$owner, $store] = $this->twoLanes();
        $seen = $this->captureSends();
        foreach (MobilePushService::EVENTS as $event) {
            StoreOption::updateStoreOption($store->id, 'mobile_push_'.$event, '0');
        }

        $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])
            ->post(route('stores.mobilepush.test'))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame(['https://push.example.com/owner'], array_column($seen->getArrayCopy(), 'endpoint'));
        $this->assertSame('/dashboard', $seen[0]['payload']['owner_url']);
    }

    public function test_order_status_push_opens_that_order(): void
    {
        [, $store] = $this->twoLanes();
        Queue::fake();
        $order = Order::create([
            'store_id' => $store->id, 'type' => 'takeaway', 'status' => 'new',
            'track_token' => Str::lower(Str::random(32)), 'created_at' => now(),
        ]);

        $order->update(['status' => 'preparing']);

        Queue::assertPushed(SendMobilePushJob::class, function (SendMobilePushJob $job) use ($order) {
            return $job->event === MobilePushService::EVENT_ORDER_STATUS
                && $job->payload['owner_url'] === route('stores.orders.show', ['order' => $order->id], false)
                && $job->payload['url'] === $job->payload['owner_url'];
        });
    }

    public function test_order_pushes_open_the_order_screen_for_every_vertical(): void
    {
        $owner = $this->owner();
        foreach (['restaurant', 'hotel', 'retail'] as $type) {
            $store = $this->store($owner, $type);
            $order = Order::create([
                'store_id' => $store->id, 'type' => 'takeaway', 'status' => 'new',
                'track_token' => Str::lower(Str::random(32)), 'created_at' => now(),
            ]);

            $url = OwnerPwa::orderUrl((int) $store->id, (int) $order->id);

            $this->assertSame(route('stores.orders.show', ['order' => $order->id], false), $url);
            $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])->get($url)->assertOk();
        }

        // Another owner's tap target is never a peek into this order.
        $this->assertContains($this->actingAs($this->owner())->get($url)->status(), [403, 404]);
    }

    public function test_waitlist_push_links_to_the_stores_waitlist_page(): void
    {
        [, $store] = $this->twoLanes();
        Queue::fake();
        $entry = WaitlistEntry::create([
            'store_id' => $store->id, 'name' => 'Sam', 'party_size' => 2, 'status' => 'waiting',
        ]);

        MobilePushService::waitlistNotified($entry);

        $path = route('waitlist.index', ['storeId' => $store->id], false);
        $this->assertStringStartsWith('/restaurant/', $path);
        Queue::assertPushed(SendMobilePushJob::class, fn (SendMobilePushJob $job) => $job->payload['owner_url'] === $path);
    }

    public function test_a_storefront_order_alerts_the_owner_pwa_with_a_link_to_it(): void
    {
        $this->withoutMiddleware([EnsureStaffDashboardPermission::class]);
        Queue::fake();
        [, $store] = $this->twoLanes();
        $store->user->forceFill(['group_id' => (string) TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 5, 'allow_ordering' => '1'],
        ])->id])->save();
        StoreOption::updateStoreOption($store->id, 'restaurant_takeaway_order', '1');
        FeatureAccess::flush();
        $cat = MenuCategory::firstOrCreate(['store_id' => $store->id, 'name' => 'Cat']);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 10, 'active' => 1]);

        $this->postJson(route('store.submitOrder', $store), [
            'name' => 'Guest', 'ordering-type' => 'takeaway', 'phone-number' => '12345678', 'pay_via' => 'cash',
            'items' => json_encode([['id' => $item->id, 'quantity' => 1]]),
        ])->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->firstOrFail();
        // Staff browsers get their own alert; the owner lane gets one linked to the order.
        Queue::assertPushed(SendPushNotificationJob::class);
        Queue::assertPushed(SendMobilePushJob::class, function (SendMobilePushJob $job) use ($order) {
            return $job->event === MobilePushService::EVENT_NEW_ORDER
                && $job->payload['owner_url'] === route('stores.orders.show', ['order' => $order->id], false)
                && $job->payload['order_id'] === (string) $order->id;
        });
    }

    public function test_the_staff_app_push_job_never_addresses_the_owner_lane(): void
    {
        [, $store] = $this->twoLanes();
        PushSubscription::storeNative('fcm-token', 'fcm', $store->id, null, 1);

        $this->assertSame(
            ['https://push.example.com/staff'],
            SendPushNotificationJob::recipients((int) $store->id)->pluck('endpoint')->all()
        );
    }

    public function test_a_staff_resubscribe_takes_the_endpoint_off_the_owner_lane(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        PushSubscription::storeWeb($this->webSub(), $store->id, $owner->id);

        PushSubscription::store($this->webSub(), $store->id, 7);

        $this->assertNull(PushSubscription::firstOrFail()->user_id);
        $this->assertSame(0, MobilePushService::ownerLane($store->id)->count());
    }

    // ── redesign fit (layout, manifest colours, card, offline page) ───────────

    public function test_dashboard_chrome_follows_light_and_dark(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);

        $html = (string) $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])
            ->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('content="'.OwnerPwa::CHROME_LIGHT.'" media="(prefers-color-scheme: light)"', $html);
        $this->assertStringContainsString('content="'.OwnerPwa::CHROME_DARK.'" media="(prefers-color-scheme: dark)"', $html);
        $this->assertStringContainsString('data-store="'.$store->id.'"', $html);
        $this->assertStringContainsString('owner-ui/pwa.css', $html);
        // A browser tab keeps the normal viewport; the kit card is used.
        $this->assertStringNotContainsString('viewport-fit=cover', $html);
        $this->assertMatchesRegularExpression('/class="ui-card opwa-card"\s+data-owner-pwa-card/', $html);
    }

    public function test_installed_app_is_drawn_edge_to_edge(): void
    {
        $owner = $this->owner();
        $this->store($owner);

        $html = (string) $this->actingAs($owner)->get(route('dashboard', ['source' => 'pwa']))->assertOk()->getContent();

        $this->assertStringContainsString('viewport-fit=cover', $html);
    }

    public function test_manifest_uses_the_redesign_surfaces(): void
    {
        config(['owner_ui.skin' => true]);
        $m = json_decode((string) $this->get(route('owner.pwa.manifest'))->getContent(), true);

        $this->assertSame(OwnerPwa::CHROME_LIGHT, $m['theme_color']);
        $this->assertSame(OwnerPwa::BACKGROUND, $m['background_color']);
    }

    public function test_offline_page_uses_the_owner_ui_tokens_in_both_themes(): void
    {
        $html = (string) $this->get(route('owner.pwa.offline'))->assertOk()->getContent();

        $this->assertStringContainsString('--ui-bg: #F4F2ED', $html);
        $this->assertStringContainsString(':root[data-theme="dark"]', $html);
        $this->assertStringContainsString('viewport-fit=cover', $html);
        $this->assertStringNotContainsString('<link rel="stylesheet"', $html);
    }

    // ── usage tracking ────────────────────────────────────────────────────────

    public function test_surface_and_device_classifiers(): void
    {
        $this->assertSame('app', OwnerPwa::surface(Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'JuwriApp/1.0 (android)'])));
        $this->assertSame('pwa', OwnerPwa::surface(Request::create('/', 'GET', [], [OwnerPwa::STANDALONE_COOKIE => '1'])));
        $this->assertSame('pwa', OwnerPwa::surface(Request::create('/dashboard?source=pwa')));
        $this->assertSame('browser', OwnerPwa::surface(Request::create('/')));
        $this->assertSame('phone', OwnerPwa::device('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)'));
        $this->assertSame('phone', OwnerPwa::device('Mozilla/5.0 (Linux; Android 14; Pixel 8) Mobile Safari'));
        $this->assertSame('tablet', OwnerPwa::device('Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X)'));
        $this->assertSame('tablet', OwnerPwa::device('Mozilla/5.0 (Linux; Android 14; SM-X700) Safari'));
        $this->assertSame('desktop', OwnerPwa::device('Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0)'));
    }

    public function test_a_dashboard_page_view_on_a_phone_is_counted_once(): void
    {
        $owner = $this->owner();
        $this->store($owner);
        $phone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Mobile/15E148';

        $this->actingAs($owner)->withHeader('User-Agent', $phone)->get(route('dashboard'))->assertOk();
        $this->actingAs($owner)->withHeader('User-Agent', $phone)->get(route('dashboard'))->assertOk();

        $row = DB::table('owner_screen_views')->where('route_name', 'dashboard')->sole();
        $this->assertSame('phone', $row->device);
        $this->assertSame('browser', $row->surface);
        $this->assertSame(2, (int) $row->views);
        $this->assertSame($owner->id, (int) $row->user_id);
    }

    public function test_json_prefetch_and_frame_requests_are_not_counted(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);
        $ok = new Response('<html></html>', 200, ['Content-Type' => 'text/html']);
        $req = fn (array $server) => tap(Request::create('/dashboard', 'GET', [], [], [], $server), function ($r) {
            $r->setRouteResolver(fn () => new Route('GET', 'dashboard', [])->name('dashboard'));
        });

        $this->assertTrue(TrackOwnerScreenView::countable($req([]), $ok));
        $this->assertFalse(TrackOwnerScreenView::countable($req(['HTTP_ACCEPT' => 'application/json']), $ok));
        $this->assertFalse(TrackOwnerScreenView::countable($req(['HTTP_PURPOSE' => 'prefetch']), $ok));
        $this->assertFalse(TrackOwnerScreenView::countable($req(['HTTP_TURBO_FRAME' => 'list']), $ok));
        $this->assertFalse(TrackOwnerScreenView::countable($req([]), new Response('', 302)));
    }

    public function test_prune_command_keeps_recent_rows(): void
    {
        $row = ['route_name' => 'dashboard', 'surface' => 'browser', 'device' => 'phone', 'user_id' => 1, 'views' => 1];
        DB::table('owner_screen_views')->insert([$row + ['day' => now()->subDays(120)->toDateString()], $row + ['day' => now()->toDateString()]]);

        $this->artisan('owner-pwa:prune-screen-views')->assertSuccessful();

        $this->assertSame(1, DB::table('owner_screen_views')->count());
    }

    public function test_admin_report_ranks_screens_and_exports_csv(): void
    {
        $this->shareLayoutGlobals([], true);
        $admin = User::factory()->create(['user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
        DB::table('owner_screen_views')->insert([
            ['day' => now()->toDateString(), 'route_name' => 'stores.orders', 'surface' => 'pwa', 'device' => 'phone', 'user_id' => 1, 'views' => 5],
            ['day' => now()->toDateString(), 'route_name' => 'stores.orders', 'surface' => 'browser', 'device' => 'phone', 'user_id' => 2, 'views' => 3],
            ['day' => now()->toDateString(), 'route_name' => 'reports.index', 'surface' => 'browser', 'device' => 'phone', 'user_id' => 1, 'views' => 1],
        ]);

        $this->actingAs($admin)->get(route('admin.ownerMobileUsage'))->assertOk()->assertSeeInOrder(['stores.orders', 'reports.index']);
        $csv = $this->actingAs($admin)->get(route('admin.ownerMobileUsage.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('stores.orders,8,2,3,5,0', $csv);

        $owner = $this->owner();
        $this->actingAs($owner)->get(route('admin.ownerMobileUsage'))->assertStatus(302);
    }
}
