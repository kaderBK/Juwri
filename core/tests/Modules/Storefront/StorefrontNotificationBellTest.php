<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ordering\Models\CustomerNotification;
use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Storefront\CommerceRuntime;
use Juwri\Modules\Storefront\Storefront\Drops\CustomerDrop;
use Juwri\Modules\Storefront\Storefront\Drops\SectionDrop;
use Juwri\Modules\Storefront\Storefront\SectionLibrary;
use Juwri\Modules\Storefront\Storefront\ThemeContext;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * The platform's NOTIFICATION BELL — a header affordance every Liquid theme
 * gets for free.
 */
class StorefrontNotificationBellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 999],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Shop', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
    }

    private function customer(Store $store): Customer
    {
        return Customer::create([
            'store_id' => $store->id, 'name' => 'C'.Str::random(4),
            'phone' => '55-'.rand(100000, 999999),
        ]);
    }

    private function notify(Store $store, Customer $customer, bool $read = false): CustomerNotification
    {
        $n = CustomerNotification::create([
            'customer_id' => $customer->id, 'store_id' => $store->id,
            'type' => 'order_status', 'title' => 'Your order is ready', 'body' => 'Come and get it',
        ]);
        if ($read) {
            $n->forceFill(['read_at' => now()])->saveQuietly();
        }

        return $n;
    }

    /**
     * The library's header section, rendered with a diner (or none).
     */
    private function renderHeader(Store $store, ?Customer $customer): string
    {
        $library = SectionLibrary::forVersion('1');
        $source = (string) file_get_contents(resource_path('liquid-library/1/sections/header.liquid'));
        $this->assertNotSame('', $source, 'the library header source is missing');

        $globals = app(ThemeContext::class)->build([
            'store' => $store, 'postOptions' => (object) [], 'total_menus' => [], 'menuBanners' => collect(),
            'branches' => collect(), 'flags' => [], 'extras' => [], 'customer' => $customer,
        ]);
        $globals['section'] = new SectionDrop('header', 'header', [
            'variant' => 'split', 'sticky' => true, 'show_logo' => true, 'show_tagline' => true,
            'show_language' => true, 'show_account' => true, 'show_notifications' => true,
            'show_cart' => true, 'host_runtime' => true,
        ], []);

        $registers = app(ThemeContext::class)->registers([
            'store' => $store, 'postOptions' => (object) [], 'total_menus' => [],
        ]) + ['theme_locale_map' => $library->locales('en')];

        return app(LiquidRenderer::class)->render(
            $source, $globals, 'upload', $registers, $library->snippets()
        );
    }

    public function test_the_header_renders_the_bell_with_the_unread_count(): void
    {
        $store = $this->store();
        $diner = $this->customer($store);
        $this->notify($store, $diner);
        $this->notify($store, $diner);
        $this->notify($store, $diner, read: true);

        $html = $this->renderHeader($store, $diner);

        $this->assertStringContainsString('data-mb-notifications', $html);
        $this->assertStringContainsString('aria-label="Notifications"', $html);
        $this->assertStringContainsString('data-mb-notif-count', $html);
        $this->assertMatchesRegularExpression('/data-mb-notif-count[^>]*>2</', $html);
    }

    public function test_a_guest_storefront_has_no_bell(): void
    {
        $store = $this->store();

        $html = $this->renderHeader($store, null);

        $this->assertStringNotContainsString('data-mb-notifications', $html);
        $this->assertStringNotContainsString('data-mb-notif-count', $html);
    }

    public function test_the_header_setting_can_hide_the_bell(): void
    {
        $store = $this->store();
        $diner = $this->customer($store);

        $library = SectionLibrary::forVersion('1');
        $source = (string) file_get_contents(resource_path('liquid-library/1/sections/header.liquid'));
        $globals = app(ThemeContext::class)->build([
            'store' => $store, 'postOptions' => (object) [], 'total_menus' => [], 'menuBanners' => collect(),
            'branches' => collect(), 'flags' => [], 'extras' => [], 'customer' => $diner,
        ]);
        $globals['section'] = new SectionDrop('header', 'header', [
            'variant' => 'split', 'show_account' => true, 'show_notifications' => false, 'host_runtime' => true,
        ], []);
        $registers = app(ThemeContext::class)->registers([
            'store' => $store, 'postOptions' => (object) [], 'total_menus' => [],
        ]) + ['theme_locale_map' => $library->locales('en')];

        $html = app(LiquidRenderer::class)->render($source, $globals, 'upload', $registers, $library->snippets());

        $this->assertStringNotContainsString('data-mb-notifications', $html);
    }

    public function test_the_drop_counts_only_the_diners_own_unread_rows(): void
    {
        $store = $this->store();
        $diner = $this->customer($store);
        $other = $this->customer($store);

        $this->notify($store, $diner);
        $this->notify($store, $diner);
        $this->notify($store, $other);
        $this->notify($store, $other);
        $this->notify($store, $other);
        $this->notify($store, $diner, read: true);

        $drop = new CustomerDrop($diner);

        $this->assertSame(2, $drop->notifications_unread());
    }

    public function test_the_drop_is_zero_for_a_guest(): void
    {
        $drop = new CustomerDrop(new Customer);

        $this->assertSame(0, $drop->notifications_unread());
    }

    public function test_the_badge_poll_counts_only_the_diners_own_rows(): void
    {
        $store = $this->store();
        $diner = $this->customer($store);
        $other = $this->customer($store);
        $this->notify($store, $diner);
        $this->notify($store, $other);
        $this->notify($store, $other);
        $this->notify($store, $other);

        $this->actingAs($diner, 'customer');

        $res = $this->getJson(route('publicView.notifications.unread', $store->id));

        $res->assertOk();
        $this->assertSame(1, $res->json('unread'));
    }

    public function test_the_badge_poll_answers_a_guest_with_401(): void
    {
        $store = $this->store();

        $this->withoutMiddleware();

        $res = $this->getJson(route('publicView.notifications.unread', $store->id));

        $res->assertStatus(401);
    }

    public function test_the_runtime_config_carries_the_bell_endpoints(): void
    {
        $store = $this->store();
        $store->id = 5;

        $cfg = (new CommerceRuntime)->config([
            'store' => $store, 'postOptions' => (object) [], 'flags' => [], 'total_menus' => [],
        ]);

        $this->assertSame(route('publicView.notifications.unread', 5), $cfg['endpoints']['notificationsUnread']);
        $this->assertSame(route('publicView.notifications.index', 5), $cfg['endpoints']['notifications']);
        $this->assertSame(route('publicView.notifications.read', 5), $cfg['endpoints']['notificationsRead']);
        $this->assertSame(route('store.orderTrack', 'TOKEN'), $cfg['endpoints']['orderTrack']);
        $this->assertArrayHasKey('Mark all read', $cfg['i18n']);
    }

    public function test_the_shipped_bundles_carry_the_bell(): void
    {
        foreach (['v1', 'v2'] as $version) {
            $js = (string) file_get_contents(base_path('../assets/commerce-runtime/'.$version.'/commerce.js'));
            $this->assertStringContainsString('data-mb-notifications', $js, $version.' bundle');
            $this->assertStringContainsString('CFG.endpoints.notificationsUnread', $js, $version.' bundle');
            $this->assertStringContainsString('data-mb-bell-read-all', $js, $version.' bundle');
        }
    }

    public function test_the_library_skin_carries_the_bell_panel(): void
    {
        $css = (string) file_get_contents(base_path('../assets/liquid-library/1/library.css'));

        $this->assertStringContainsString('.mb-bell', $css);
        $this->assertStringContainsString('.mb-bell__list', $css);
    }
}
