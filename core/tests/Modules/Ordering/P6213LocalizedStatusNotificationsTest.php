<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffMember;
use App\Services\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Feature;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Messaging\Contracts\MessageQueue;
use Juwri\Modules\Ordering\Services\Orders\SchedulingService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-213: customer status SMS (StaffAppController + ManagesOrders) and the
 * scheduled-order staff push (SchedulingService::release) used to ship raw
 * English — the customer's "ready" was interpolated as the literal status
 * word, the staff push built its body with config('settings.currency_sign')
 * + number_format(), and neither respected the order's locale.
 *
 * The contract these tests pin:
 *   1. The customer SMS body speaks the ORDER's locale (orders.locale); falls
 *      back to the store's default_language; never the ambient staff locale.
 *   2. The status word is translated through the existing OrderStatus->value
 *      keys (preparing, ready, ...) which already exist in all 11 locales —
 *      so an Arabic store gets "جاهز", not the raw English "ready".
 *   3. The staff push title + body are translated (order-locale-or-store-fallback
 *      wrapping) and the money uses store_money_plain, reading the store's
 *      currency_sign / currency_code (never the platform currency_sign).
 *
 * If anything here regresses, an Arabic store will go back to sending English
 * SMS to its diners and a SAR store will go back to pushing amounts in the
 * platform's default currency sign.
 */
class P6213LocalizedStatusNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Match the existing staff-app test pattern: only skip the Installed gate
        // (the app must be reachable from the test runner). Leaving `auth:staff`,
        // `abuse.hold`, etc. active is fine because `actingAs($staff, 'staff')`
        // satisfies the staff guard; bypassing everything else breaks the staff
        // middleware stack and the staff PWA endpoint returns 403.
        $this->withoutMiddleware(Installed::class);
        // Same memo-flush the existing fix-2 test does: FeatureAccess keeps
        // a static cache of the per-store entitlement verdict across tests.
        FeatureAccess::flush();
        app()->setLocale('en');
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
        ]);

        return User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
        ]);
    }

    private function storeFixture(User $owner, string $title = 'Diner'): Store
    {
        return Store::create([
            'user_id' => $owner->id,
            'slug' => 'r-'.Str::lower(Str::random(6)),
            'color' => '#111',
            'title' => $title,
            'sub_title' => 't', 'description' => 't',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function staff(User $owner, Store $store, string $role = 'manager'): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $owner->id,
            'store_id' => $store->id,
            'name' => 'Staff',
            'role' => $role,
            'email' => 'staff'.Str::random(5).'@ex.com',
            'password' => Hash::make('secret'),
            'active' => 1,
        ]);
    }

    /**
     * Drop a category + menu in the store so Order::computeMoney() has a price
     * to derive the order total from. Orders have no `total` column — the
     * accessor always re-derives from `itemsSubtotal()` × quantity — so a bare
     * Order::create() leaves $order->total at 0 and the staff push body says
     * "0.00" instead of the fixture amount. The price unit is the major amount
     * (Menu has `price` → `price_minor`); 12.5 is rendered "12.50" by
     * store_money_plain() (SAR has 2 decimals, ISO 4217).
     */
    private function menuItem(Store $store, string $name, float $price): Menu
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'C-'.Str::random(4)]);

        return Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id,
            'name' => $name, 'price' => $price, 'active' => 1,
        ]);
    }

    // ----------------------------------------------------------- staff app SMS

    /**
     * StaffAppController::updateStatus → customer SMS body speaks the order's
     * locale. An order whose `locale = 'ar'` flips the body to Arabic, and the
     * status word "ready" becomes the translated "جاهز" — never the raw string.
     */
    public function test_staffapp_status_sms_uses_order_locale_and_translated_status_word(): void
    {
        $owner = $this->owner();
        $store = $this->storeFixture($owner, 'مطعم البيت'); // Arabic store name (sanitised)
        $staff = $this->staff($owner, $store);

        StoreSettings::set($store->id, 'customer_status_sms', '1');
        StoreSettings::set($store->id, 'default_language', 'en'); // order.locale wins

        $order = Order::create([
            'store_id' => $store->id, 'phone_number' => '+966500000001',
            'status' => 'preparing', 'locale' => 'ar', 'type' => 'takeaway',
        ]);

        $sent = null;
        $mq = $this->mock(MessageQueue::class);
        $mq->shouldReceive('sms')->once()->andReturnUsing(function ($to, $msg) use (&$sent) {
            $sent = ['to' => $to, 'msg' => $msg];
        });

        // Drive the staff-pwa updateStatus endpoint, not the owner route.
        $this->actingAs($staff, 'staff')
            ->postJson("/staff-app/orders/{$order->id}/status", ['status' => 'ready'])
            ->assertOk();

        $this->assertNotNull($sent, 'expected an SMS to be dispatched');
        $this->assertSame('+966500000001', $sent['to']);
        $this->assertStringContainsString('جاهز', $sent['msg'], 'status word must be Arabic ("جاهز"), not the raw "ready"');
        $this->assertStringContainsString("#{$order->id}", $sent['msg']);
        $this->assertStringNotContainsString(' ready.', $sent['msg'], 'raw English status must not appear alongside the translation');
        $this->assertStringNotContainsString(' is now ready.', $sent['msg'], 'old English literal must be gone');
    }

    /**
     * When orders.locale is NULL the SMS body falls back to the store's
     * default_language. An Arabic-default store still gets Arabic even when
     * the customer placed the order before the locale column was added.
     */
    public function test_staffapp_status_sms_falls_back_to_store_default_language(): void
    {
        $owner = $this->owner();
        $store = $this->storeFixture($owner);
        $staff = $this->staff($owner, $store);

        StoreSettings::set($store->id, 'customer_status_sms', '1');
        StoreSettings::set($store->id, 'default_language', 'ar');

        $order = Order::create([
            'store_id' => $store->id, 'phone_number' => '+966500000002',
            'status' => 'preparing', 'locale' => null, 'type' => 'takeaway',
        ]);

        $sent = null;
        $mq = $this->mock(MessageQueue::class);
        $mq->shouldReceive('sms')->once()->andReturnUsing(function ($to, $msg) use (&$sent) {
            $sent = $msg;
        });

        $this->actingAs($staff, 'staff')
            ->postJson("/staff-app/orders/{$order->id}/status", ['status' => 'ready'])
            ->assertOk();

        $this->assertNotNull($sent);
        $this->assertStringContainsString('جاهز', $sent, 'store default_language=ar must localise the status word');
    }

    /**
     * Owner's owner-route updateOrderStatus (ManagesOrders) — same contract as
     * the staff PWA: order.locale wins, store default_language is the fallback,
     * and the status word is translated.
     */
    public function test_owner_route_status_sms_uses_order_locale_and_translated_status_word(): void
    {
        $owner = $this->owner();
        $store = $this->storeFixture($owner);
        StoreSettings::set($store->id, 'customer_status_sms', '1');
        StoreSettings::set($store->id, 'default_language', 'en');

        $order = Order::create([
            'store_id' => $store->id, 'phone_number' => '+966500000003',
            'status' => 'preparing', 'locale' => 'ar', 'type' => 'takeaway',
        ]);

        $sent = null;
        $mq = $this->mock(MessageQueue::class);
        $mq->shouldReceive('sms')->once()->andReturnUsing(function ($to, $msg) use (&$sent) {
            $sent = $msg;
        });

        $this->actingAs($owner)
            ->postJson(route('stores.updateOrderStatus', $order->id), ['status' => 'ready'])
            ->assertOk();

        $this->assertNotNull($sent);
        $this->assertStringContainsString('جاهز', $sent);
        $this->assertStringContainsString("#{$order->id}", $sent);
        $this->assertStringNotContainsString(' is now ready.', $sent);
    }

    // ------------------------------------------------------- scheduled push

    /**
     * SchedulingService::release() fires a staff push whose title and body
     * were English-only and used the platform currency_sign. The body now
     * renders in the order's locale with the store's own money format.
     */
    public function test_scheduled_release_staff_push_is_translated_and_uses_store_money(): void
    {
        Feature::updateOrCreate(
            ['key' => 'scheduled_orders'],
            ['label' => 'Scheduled Orders', 'plan_gated' => true]
        );
        config(['settings.admin_allow_scheduled_orders' => 1]);
        app()->forgetInstance('features.plan_gated');
        FeatureAccess::flush();

        $owner = $this->owner();
        $store = $this->storeFixture($owner);

        // Pin the platform default to a different currency + locale so the
        // test would fail loudly if either leaked through.
        config([
            'settings.currency_sign' => '$',
            'settings.currency_code' => 'USD',
            'app.locale' => 'en',
        ]);

        // Store is Saudi: own currency (SAR) + Arabic default. Order locale
        // missing — the fix falls back to default_language.
        StoreSettings::set($store->id, 'currency_code', 'SAR');
        StoreSettings::set($store->id, 'currency_sign', 'ر.س');
        StoreSettings::set($store->id, 'currency_pos', '0'); // sign AFTER
        StoreSettings::set($store->id, 'default_language', 'ar');
        StoreSettings::set($store->id, 'allow_order_scheduling', '1');

        $order = Order::create([
            'store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway',
            'table_number' => '5',
            'scheduled_at' => now()->addHour(), 'fire_at' => now()->subMinute(),
        ]);
        // Orders have no `total` column — the accessor derives from items.
        // 12.5 renders as "12.50" (SAR has 2 decimals).
        $item = $this->menuItem($store, 'Burger', 12.5);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 1, 'variation' => 0, 'status' => 'queued']);

        $push = null;
        $mq = $this->mock(MessageQueue::class);
        $mq->shouldReceive('staffPush')->once()->andReturnUsing(function (...$args) use (&$push) {
            $push = ['store_id' => $args[0], 'title' => $args[1], 'body' => $args[2], 'url' => $args[3]];
        });

        $this->assertTrue(app(SchedulingService::class)->release($order));

        $this->assertNotNull($push, 'release() must dispatch a staff push');
        $this->assertSame((int) $store->id, $push['store_id']);

        // Title in Arabic, with the order id.
        $this->assertStringContainsString('موعد الطلب المجدول', $push['title']);
        $this->assertStringContainsString("#{$order->id}", $push['title']);
        $this->assertStringNotContainsString('Scheduled order due', $push['title'], 'raw English title must be gone');

        // Body: Arabic "Table 5" (طاولة 5), then the store's own SAR money.
        // store_money_plain() routes the sign through currency_sign_safe()
        // (it must never be the platform '$') — if the SAR glyph is drawable
        // in this PHP build's iconv it stays "ر.س"; otherwise it falls back
        // to the ISO code "SAR". Either way the platform '$' never appears.
        $this->assertStringContainsString('طاولة', $push['body']);
        $this->assertStringContainsString('5', $push['body']);
        $this->assertStringNotContainsString('$', $push['body'], 'platform currency sign must never appear in the staff push');
        $hasSarSign = str_contains($push['body'], 'ر.س');
        $hasSarCode = str_contains($push['body'], 'SAR');
        $this->assertTrue($hasSarSign || $hasSarCode, 'body must carry the SAR sign "ر.س" OR its ISO code "SAR" — never the platform "$"');
        $this->assertMatchesRegularExpression('/12\.50(?!\d)\s*(ر\.س|SAR)/u', $push['body'], 'the SAR amount (2 decimals); sign/code must be AFTER the number (currency_pos=0)');
        $this->assertStringNotContainsString('Table', $push['body'], 'raw English "Table" must be gone');
    }

    /**
     * When the order has no table_number (e.g. takeaway), the body label is
     * the translated order type, not the raw ucfirst(type).
     */
    public function test_scheduled_release_staff_push_uses_translated_order_type_when_no_table(): void
    {
        Feature::updateOrCreate(
            ['key' => 'scheduled_orders'],
            ['label' => 'Scheduled Orders', 'plan_gated' => true]
        );
        config(['settings.admin_allow_scheduled_orders' => 1]);
        app()->forgetInstance('features.plan_gated');
        FeatureAccess::flush();

        $owner = $this->owner();
        $store = $this->storeFixture($owner);

        config(['app.locale' => 'en']);
        StoreSettings::set($store->id, 'default_language', 'ar');
        StoreSettings::set($store->id, 'allow_order_scheduling', '1');

        $order = Order::create([
            'store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway',
            'table_number' => null,
            'scheduled_at' => now()->addHour(), 'fire_at' => now()->subMinute(),
        ]);
        // Orders have no `total` column — the accessor derives from items.
        $item = $this->menuItem($store, 'Sandwich', 7.0);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 1, 'variation' => 0, 'status' => 'queued']);

        $push = null;
        $mq = $this->mock(MessageQueue::class);
        $mq->shouldReceive('staffPush')->once()->andReturnUsing(function (...$args) use (&$push) {
            $push = ['title' => $args[1], 'body' => $args[2]];
        });

        app(SchedulingService::class)->release($order);

        $this->assertNotNull($push);
        // The 'takeaway' key translates to "سفري" in Arabic (lang/ar/lang.php).
        $this->assertStringContainsString('سفري', $push['body']);
        $this->assertStringNotContainsString('Takeaway', $push['body']);
    }

    // ------------------------------------------------------- lang completeness

    /**
     * Pinned so a future translator that drops any of the new keys to English
     * (or removes a placeholder) trips the test instead of breaking in
     * production. lang_check enforces translations on the kit lang files; this
     * guards the CORE lang files we just extended for P6-213.
     */
    public function test_new_p6_213_keys_exist_in_every_locale_with_no_english_copies(): void
    {
        $locales = ['en', 'ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'];
        $keys = [
            'call_bill',
            'name_your_order_id_is_now_status',
            'scheduled_order_due_id',
            'scheduled_table_number',
            'scheduled_order_body',
        ];
        // The previous dirname(__DIR__, 2) walked tests/Modules → tests, then
        // appended `lang/...` and looked at a non-existent tests/lang/ directory.
        // base_path() is the canonical resolver — it points at core/lang for a
        // core test, which is where the production lang files actually live.
        $en = include base_path('lang/en/lang.php');
        foreach ($keys as $k) {
            $this->assertArrayHasKey($k, $en, "en lang must define {$k}");
            $this->assertNotSame('', trim((string) $en[$k]), "en.{$k} must be non-empty");
        }
        foreach ($locales as $locale) {
            $lines = include base_path("lang/{$locale}/lang.php");
            foreach ($keys as $k) {
                $this->assertArrayHasKey($k, $lines, "{$locale} lang must define {$k}");
                $this->assertNotSame('', trim((string) $lines[$k]), "{$locale}.{$k} must be non-empty");
            }
            // Non-English locales: the SMS body and the push title must NOT be
            // English copies (call_bill / scheduled_table_number can be
            // single words; the 2 templates and the body have placeholders).
            if ($locale === 'en') {
                continue;
            }
            $this->assertNotSame(
                $en['name_your_order_id_is_now_status'],
                $lines['name_your_order_id_is_now_status'],
                "{$locale}.name_your_order_id_is_now_status is an English copy"
            );
            $this->assertNotSame(
                $en['scheduled_order_due_id'],
                $lines['scheduled_order_due_id'],
                "{$locale}.scheduled_order_due_id is an English copy"
            );
            // Both placeholders must survive every translation.
            foreach (['name_your_order_id_is_now_status' => [':name', ':id', ':status'], 'scheduled_order_due_id' => [':id'], 'scheduled_table_number' => [':number'], 'scheduled_order_body' => [':label', ':total']] as $k => $placeholders) {
                foreach ($placeholders as $ph) {
                    $this->assertStringContainsString(
                        $ph,
                        $lines[$k],
                        "{$locale}.{$k} lost placeholder {$ph}"
                    );
                }
            }
        }
    }
}
