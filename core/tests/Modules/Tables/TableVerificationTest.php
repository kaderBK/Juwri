<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Tables;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use App\Models\StoreTable;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use App\Services\KdsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Messaging\Data\OtpScope;
use Juwri\Modules\Messaging\Models\SmsLog;
use Juwri\Modules\Messaging\Services\SmsOtpService;
use Juwri\Modules\Tables\Models\TableSession;
use Juwri\Modules\Tables\Services\TableSessionService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * PARITY-RUN T3 — verified table QR, end to end.
 *
 * The four modes on the real order-submit route, plus the four regressions that
 * matter more than the happy paths:
 *
 *   • `off` behaves exactly as it did before T3 existed;
 *   • delivery / takeaway / room-service are never gated, in ANY mode;
 *   • a session proven at table A cannot order at table B;
 *   • wrong codes are throttled rather than guessable.
 */
class TableVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        // Placing an order fans out mail/notifications/jobs — none of which this
        // suite is about.
        Mail::fake();
        Notification::fake();
        Queue::fake();

        // The platform kill-switch for table_otp defaults OFF (opt-in per store),
        // so every test that expects verification to run has to turn it on.
        config(['settings.admin_allow_table_otp' => true]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
        RateLimiter::clear('x'); // no-op guard; per-test keys are cleared inline

        /* Storefront templates extend a layout that reads $settings/$activeTheme
           from a view()->composer ViewServiceProvider only registers when
           APP_INSTALLED is set — and phpunit.xml deliberately leaves it unset.
           Same stub StorefrontRoomServiceOptionTest uses. */
        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    // --- fixtures --------------------------------------------------------

    private function store(string $mode = TableSessionService::MODE_OFF): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 5, 'table_qr' => '1', 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St',
            'phone' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]);

        StoreOption::updateStoreOption($store->id, 'restaurant_on_table_order', '1');
        if ($mode !== TableSessionService::MODE_OFF) {
            StoreOption::updateStoreOption($store->id, TableSessionService::OPTION_MODE, $mode);
        }
        FeatureAccess::flush();

        return $store;
    }

    private function table(Store $store, int $no, ?string $code = null): StoreTable
    {
        return StoreTable::create([
            'store_id' => $store->id,
            'table_no' => $no,
            'key' => Str::random(12),
            'verify_code' => $code,
            'verify_code_rotated_at' => $code ? now() : null,
        ]);
    }

    private function item(Store $store): Menu
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);

        return Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id,
            'name' => 'Item', 'price' => 10.00, 'active' => 1,
        ]);
    }

    private function submitOrder(Store $store, Menu $item, array $extra = []): TestResponse
    {
        return $this->postJson(route('store.submitOrder', $store), array_merge([
            'items' => json_encode([['id' => $item->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'name' => 'Test Guest',
            'ordering-type' => 'on-table',
            'table' => '5',
            'pay_via' => 'cash',
            'total' => '10.00',
        ], $extra));
    }

    /**
     * Carry the session the last verification issued into every following
     * request. Laravel's test client does not persist Set-Cookie between calls,
     * so a real browser's behaviour has to be reproduced by hand; the token is
     * read from the row rather than decrypted out of the header because the
     * cookie is httpOnly + encrypted and its VALUE is not what is under test.
     */
    private function carrySession(): self
    {
        // withCredentials() matters: Laravel's json()/postJson() helpers send NO
        // cookies unless it is set, which would silently make every "already
        // verified" assertion below pass for the wrong reason. The storefront's
        // own fetch calls use credentials: 'same-origin', so this mirrors it.
        return $this->withCredentials()->withCookie(
            TableSessionService::COOKIE,
            (string) TableSession::latest('id')->value('token')
        );
    }

    /** Pull the freshly issued OTP out of the simulated SMS log. */
    private function issuedOtp(): string
    {
        $preview = SmsLog::where('feature', 'otp')->latest('id')->value('message_preview');
        preg_match('/(\d{4,6})/', (string) $preview, $m);

        return $m[1] ?? '';
    }

    // =====================================================================
    // mode = off — the regression that matters most
    // =====================================================================

    public function test_mode_off_leaves_dine_in_ordering_completely_untouched(): void
    {
        $store = $this->store(); // no mode option at all
        $item = $this->item($store);
        $table = $this->table($store, 5, '1234');

        $this->submitOrder($store, $item)->assertJson(['success' => true]);

        $this->assertDatabaseHas('orders', ['store_id' => $store->id, 'table_number' => 5]);
        $this->assertSame(0, TableSession::count(), 'mode=off must never create a session row.');
        // And the order goes straight into the normal flow, not the approval park.
        $this->assertNotSame(Order::STATUS_PENDING_APPROVAL, Order::where('store_id', $store->id)->value('status'));
        $this->assertNotNull($table);
    }

    public function test_an_unentitled_store_falls_back_to_off_rather_than_locking_guests_out(): void
    {
        $store = $this->store(TableSessionService::MODE_TABLE_CODE);
        $item = $this->item($store);
        $this->table($store, 5, '1234');

        // Platform kill-switch down: the owner's choice stands, the gate does not.
        config(['settings.admin_allow_table_otp' => false]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();

        $this->submitOrder($store, $item)->assertJson(['success' => true]);
        $this->assertDatabaseHas('orders', ['store_id' => $store->id]);
    }

    // =====================================================================
    // mode = table_code
    // =====================================================================

    public function test_table_code_mode_refuses_an_unverified_dine_in_order(): void
    {
        $store = $this->store(TableSessionService::MODE_TABLE_CODE);
        $item = $this->item($store);
        $this->table($store, 5, '1234');

        $this->submitOrder($store, $item)
            ->assertJson(['success' => false, 'table_verification_required' => true, 'mode' => 'table_code']);

        $this->assertDatabaseMissing('orders', ['store_id' => $store->id]);
    }

    public function test_the_correct_table_code_verifies_and_the_order_then_goes_through(): void
    {
        $store = $this->store(TableSessionService::MODE_TABLE_CODE);
        $item = $this->item($store);
        $table = $this->table($store, 5, '1234');

        $this->postJson(route('table.verify.code', $store->id), ['table' => $table->key, 'code' => '1234'])
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertCookie(TableSessionService::COOKIE);

        $this->assertDatabaseHas('table_sessions', [
            'store_id' => $store->id,
            'table_key' => $table->key,
            'method' => 'table_code',
        ]);
        $this->assertNotNull(TableSession::first()->verified_at);

        $this->carrySession()->submitOrder($store, $item, ['table_key' => $table->key])->assertJson(['success' => true]);
        $this->assertDatabaseHas('orders', ['store_id' => $store->id, 'table_number' => 5]);
    }

    public function test_a_wrong_table_code_neither_verifies_nor_creates_a_usable_session(): void
    {
        $store = $this->store(TableSessionService::MODE_TABLE_CODE);
        $table = $this->table($store, 5, '1234');

        $this->postJson(route('table.verify.code', $store->id), ['table' => $table->key, 'code' => '9999'])
            ->assertOk()
            ->assertJson(['success' => false]);

        $this->assertSame(0, TableSession::count());
        // And it left AbuseGuard evidence (the guard ships warn-only, so the row
        // is the point — the rate limiter is what actually stops a guesser).
        $this->assertDatabaseHas('abuse_events', ['guard' => 'table_verify', 'action' => 'bad_table_code']);
    }

    public function test_wrong_codes_are_throttled(): void
    {
        $store = $this->store(TableSessionService::MODE_TABLE_CODE);
        $table = $this->table($store, 5, '1234');

        for ($i = 0; $i < TableSessionService::MAX_ATTEMPTS; $i++) {
            $this->postJson(route('table.verify.code', $store->id), ['table' => $table->key, 'code' => '0000'])
                ->assertJson(['success' => false]);
        }

        // Budget exhausted: even the CORRECT code is refused until it decays.
        $this->postJson(route('table.verify.code', $store->id), ['table' => $table->key, 'code' => '1234'])
            ->assertStatus(429)
            ->assertJson(['success' => false]);

        $this->assertSame(0, TableSession::count());
    }

    // =====================================================================
    // Cookie scope — the security boundary
    // =====================================================================

    public function test_a_session_verified_at_table_a_cannot_order_at_table_b(): void
    {
        $store = $this->store(TableSessionService::MODE_TABLE_CODE);
        $item = $this->item($store);
        $a = $this->table($store, 1, '1111');
        $b = $this->table($store, 2, '2222');

        $this->postJson(route('table.verify.code', $store->id), ['table' => $a->key, 'code' => '1111'])
            ->assertJson(['success' => true]);
        $this->carrySession();

        // Same browser, same (valid) cookie — a different table.
        $this->submitOrder($store, $item, ['table' => '2', 'table_key' => $b->key])
            ->assertJson(['success' => false, 'table_verification_required' => true]);

        $this->assertDatabaseMissing('orders', ['store_id' => $store->id]);

        // …while its OWN table still works, proving the cookie itself is fine.
        $this->submitOrder($store, $item, ['table' => '1', 'table_key' => $a->key])
            ->assertJson(['success' => true]);

        // The number alone is not a loophole either: no key, wrong number.
        $this->submitOrder($store, $item, ['table' => '2'])
            ->assertJson(['success' => false, 'table_verification_required' => true]);
    }

    public function test_a_session_from_another_store_is_worthless_here(): void
    {
        $a = $this->store(TableSessionService::MODE_TABLE_CODE);
        $b = $this->store(TableSessionService::MODE_TABLE_CODE);
        $itemB = $this->item($b);
        $tableA = $this->table($a, 5, '1234');
        $this->table($b, 5, '4321');

        $this->postJson(route('table.verify.code', $a->id), ['table' => $tableA->key, 'code' => '1234'])
            ->assertJson(['success' => true]);

        $this->submitOrder($b, $itemB)
            ->assertJson(['success' => false, 'table_verification_required' => true]);
    }

    public function test_rotating_a_table_code_kills_the_sessions_proven_with_the_old_one(): void
    {
        $store = $this->store(TableSessionService::MODE_TABLE_CODE);
        $item = $this->item($store);
        $table = $this->table($store, 5, '1234');

        $this->postJson(route('table.verify.code', $store->id), ['table' => $table->key, 'code' => '1234'])
            ->assertJson(['success' => true]);
        $this->assertSame(1, TableSession::count());

        TableSessionService::rotateCode($table->fresh());

        $this->assertSame(0, TableSession::count());
        $this->submitOrder($store, $item, ['table_key' => $table->key])
            ->assertJson(['success' => false, 'table_verification_required' => true]);
    }

    // =====================================================================
    // P6-270 — sessions end with the bill; budgets beyond one IP
    // =====================================================================

    private function verifyAt(Store $store, StoreTable $table, string $code, string $ip = '10.0.0.1', string $ua = 'UA-1'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $ua])
            ->postJson(route('table.verify.code', $store->id), ['table' => $table->key, 'code' => $code]);
    }

    public function test_settling_the_tables_bill_ends_the_session(): void
    {
        $store = $this->store(TableSessionService::MODE_TABLE_CODE);
        $item = $this->item($store);
        $table = $this->table($store, 5, '1234');

        $this->verifyAt($store, $table, '1234')->assertJson(['success' => true]);
        $this->carrySession()->submitOrder($store, $item, ['table_key' => $table->key])->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->firstOrFail();
        $order->is_paid = 1;
        $order->save();

        $this->submitOrder($store, $item, ['table_key' => $table->key])
            ->assertJson(['success' => false, 'table_verification_required' => true]);
        $this->assertSame(1, Order::where('store_id', $store->id)->count());
    }

    public function test_an_unpaid_second_order_on_the_table_keeps_the_session_alive(): void
    {
        $store = $this->store(TableSessionService::MODE_TABLE_CODE);
        $item = $this->item($store);
        $table = $this->table($store, 5, '1234');

        $this->verifyAt($store, $table, '1234')->assertJson(['success' => true]);
        $this->carrySession()->submitOrder($store, $item, ['table_key' => $table->key])->assertJson(['success' => true]);
        $this->submitOrder($store, $item, ['table_key' => $table->key])->assertJson(['success' => true]);

        $first = Order::where('store_id', $store->id)->orderBy('id')->firstOrFail();
        $first->is_paid = 1;
        $first->save();

        $this->submitOrder($store, $item, ['table_key' => $table->key])->assertJson(['success' => true]);
    }

    public function test_clearing_the_table_ends_the_session(): void
    {
        $store = $this->store(TableSessionService::MODE_TABLE_CODE);
        $item = $this->item($store);
        $table = $this->table($store, 5, '1234');
        $table->status = 'seated';
        $table->save();

        $this->verifyAt($store, $table, '1234')->assertJson(['success' => true]);
        $this->carrySession();

        $table->refresh()->status = 'free';
        $table->save();

        $this->submitOrder($store, $item, ['table_key' => $table->key])
            ->assertJson(['success' => false, 'table_verification_required' => true]);
        $this->assertDatabaseMissing('orders', ['store_id' => $store->id]);
    }

    public function test_the_session_token_is_long_and_random(): void
    {
        $store = $this->store(TableSessionService::MODE_TABLE_CODE);
        $table = $this->table($store, 5, '1234');

        $this->verifyAt($store, $table, '1234')->assertJson(['success' => true]);
        $this->verifyAt($store, $table, '1234', '10.0.0.2')->assertJson(['success' => true]);

        $tokens = TableSession::pluck('token')->all();
        $this->assertCount(2, $tokens);
        $this->assertGreaterThanOrEqual(40, strlen($tokens[0]));
        $this->assertNotSame($tokens[0], $tokens[1]);
    }

    public function test_wrong_codes_from_many_ips_lock_the_table_and_rotate_its_code(): void
    {
        $store = $this->store(TableSessionService::MODE_TABLE_CODE);
        $table = $this->table($store, 5, '1234');

        for ($i = 1; $i <= 20; $i++) {
            $this->verifyAt($store, $table, '0000', '10.1.0.'.$i, 'UA-'.$i);
        }

        $rotated = $table->fresh()->verify_code;
        $this->assertNotSame('1234', $rotated, 'The per-table budget must rotate the code.');

        // Locked for everyone: a brand-new IP + device with the NEW correct code is refused.
        $this->verifyAt($store, $table, (string) $rotated, '10.9.9.9', 'Fresh')
            ->assertStatus(429);
        $this->assertSame(0, TableSession::count());
    }

    public function test_one_device_rotating_ips_keeps_one_budget(): void
    {
        $store = $this->store(TableSessionService::MODE_TABLE_CODE);
        $table = $this->table($store, 5, '1234');

        for ($i = 1; $i <= TableSessionService::MAX_ATTEMPTS; $i++) {
            $this->verifyAt($store, $table, '0000', '10.2.0.'.$i, 'SamePhone');
        }

        $this->verifyAt($store, $table, '1234', '10.2.0.99', 'SamePhone')->assertStatus(429);
        $this->assertSame(0, TableSession::count());
    }

    public function test_key_and_number_share_one_budget(): void
    {
        $store = $this->store(TableSessionService::MODE_TABLE_CODE);
        $table = $this->table($store, 5, '1234');

        for ($i = 0; $i < TableSessionService::MAX_ATTEMPTS; $i++) {
            $ref = $i % 2 ? (string) $table->key : '5';
            $this->postJson(route('table.verify.code', $store->id), ['table' => $ref, 'code' => '0000']);
        }

        $this->postJson(route('table.verify.code', $store->id), ['table' => '5', 'code' => '1234'])->assertStatus(429);
    }

    // =====================================================================
    // mode = sms_otp
    // =====================================================================

    public function test_sms_otp_mode_refuses_until_the_phone_is_verified_then_lets_the_order_through(): void
    {
        $store = $this->store(TableSessionService::MODE_SMS_OTP);
        $item = $this->item($store);
        $table = $this->table($store, 5);
        $phone = '+16505551234';

        $this->submitOrder($store, $item, ['table_key' => $table->key])
            ->assertJson(['success' => false, 'table_verification_required' => true, 'mode' => 'sms_otp']);

        $this->postJson(route('table.verify.otp.send', $store->id), ['table' => $table->key, 'phone' => $phone])
            ->assertJson(['success' => true]);

        $this->postJson(route('table.verify.otp.verify', $store->id), [
            'table' => $table->key, 'phone' => $phone, 'code' => $this->issuedOtp(),
        ])->assertJson(['success' => true]);

        $this->assertDatabaseHas('table_sessions', [
            'store_id' => $store->id, 'table_key' => $table->key, 'method' => 'sms_otp',
        ]);

        $this->carrySession()->submitOrder($store, $item, ['table_key' => $table->key])->assertJson(['success' => true]);
        $this->assertDatabaseHas('orders', ['store_id' => $store->id]);
    }

    public function test_a_wrong_sms_code_does_not_verify(): void
    {
        $store = $this->store(TableSessionService::MODE_SMS_OTP);
        $table = $this->table($store, 5);
        $phone = '+16505551234';

        app(SmsOtpService::class)->request($phone, OtpScope::store((int) $store->id));

        $this->postJson(route('table.verify.otp.verify', $store->id), [
            'table' => $table->key, 'phone' => $phone, 'code' => '000000',
        ])->assertJson(['success' => false]);

        $this->assertSame(0, TableSession::count());
        $this->assertDatabaseHas('abuse_events', ['guard' => 'table_verify', 'action' => 'bad_table_otp']);
    }

    // =====================================================================
    // mode = staff_approve
    // =====================================================================

    public function test_staff_approve_accepts_the_order_but_parks_it_before_the_kitchen(): void
    {
        $store = $this->store(TableSessionService::MODE_STAFF_APPROVE);
        $item = $this->item($store);
        $this->table($store, 5);

        // The guest is never challenged — the order is accepted normally.
        $this->submitOrder($store, $item)->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->firstOrFail();
        $this->assertSame(Order::STATUS_PENDING_APPROVAL, $order->status);

        // …and it is invisible to every live staff queue.
        $this->assertSame(0, Order::live()->where('store_id', $store->id)->count());
    }

    public function test_a_parked_order_survives_a_kds_status_resync(): void
    {
        $store = $this->store(TableSessionService::MODE_STAFF_APPROVE);
        $item = $this->item($store);
        $this->table($store, 5);
        $this->submitOrder($store, $item)->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->firstOrFail();

        // syncOrderStatus derives the order status from its items, which are all
        // 'queued' — without the guard this would rewrite it to 'new' and send an
        // unapproved order to the kitchen.
        KdsService::syncOrderStatus((int) $order->id);

        $this->assertSame(Order::STATUS_PENDING_APPROVAL, $order->fresh()->status);
    }

    public function test_staff_approve_only_parks_dine_in_orders(): void
    {
        $store = $this->store(TableSessionService::MODE_STAFF_APPROVE);
        $item = $this->item($store);

        $this->submitOrder($store, $item, [
            'ordering-type' => 'takeaway', 'table' => '', 'phone-number' => '+16505551234',
        ])->assertJson(['success' => true]);

        $this->assertNotSame(
            Order::STATUS_PENDING_APPROVAL,
            Order::where('store_id', $store->id)->value('status')
        );
    }

    // =====================================================================
    // Delivery / pickup are never gated — in ANY mode
    // =====================================================================
    #[DataProvider('gatingModes')]
    public function test_delivery_and_takeaway_are_unaffected_by_every_mode(string $mode): void
    {
        $store = $this->store($mode);
        $item = $this->item($store);
        $this->table($store, 5, '1234');

        $this->submitOrder($store, $item, [
            'ordering-type' => 'takeaway', 'table' => '', 'phone-number' => '+16505551234',
        ])->assertJson(['success' => true]);

        $this->submitOrder($store, $item, [
            'ordering-type' => 'delivery', 'table' => '',
            'phone-number' => '+16505551234', 'address' => '1 Long Street',
        ])->assertJson(['success' => true]);

        $this->assertSame(2, Order::where('store_id', $store->id)->count());
    }

    public static function gatingModes(): array
    {
        return [
            'table_code' => [TableSessionService::MODE_TABLE_CODE],
            'sms_otp' => [TableSessionService::MODE_SMS_OTP],
            'staff_approve' => [TableSessionService::MODE_STAFF_APPROVE],
        ];
    }

    // =====================================================================
    // Storefront surface — the shared partial reaches the themes
    // =====================================================================

    public function test_the_scan_gate_renders_on_a_table_qr_and_not_on_a_walk_in(): void
    {
        $store = $this->store(TableSessionService::MODE_TABLE_CODE);
        $this->item($store);
        $table = $this->table($store, 5, '1234');

        $this->get('/'.$store->slug.'?table='.$table->key)->assertOk()->assertSeeHtml('mbTvBackdrop')->assertSeeHtml('name="table_key" value="'.$table->key.'"');
    }

    public function test_a_store_on_mode_off_renders_no_gate_at_all(): void
    {
        $store = $this->store();
        $this->item($store);
        $table = $this->table($store, 5);

        $this->get('/'.$store->slug.'?table='.$table->key)->assertOk()->assertDontSeeHtml('mbTvBackdrop');
    }

    public function test_a_verified_guest_no_longer_sees_the_gate(): void
    {
        $store = $this->store(TableSessionService::MODE_TABLE_CODE);
        $this->item($store);
        $table = $this->table($store, 5, '1234');

        $this->postJson(route('table.verify.code', $store->id), ['table' => $table->key, 'code' => '1234'])
            ->assertJson(['success' => true]);

        $this->carrySession()
            ->get('/'.$store->slug.'?table='.$table->key)->assertOk()->assertDontSeeHtml('mbTvBackdrop');
    }
}
