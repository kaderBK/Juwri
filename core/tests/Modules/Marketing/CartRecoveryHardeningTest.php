<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Marketing;

use App\Models\Customer;
use App\Models\Order;
use App\Models\StoreOption;
use App\Services\Marketing\CartRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Messaging\Contracts\Sms;
use Tests\TestCase;

/**
 * P6-327: cart-recovery batch hardening — eligible-store filtering, the
 * cart_recovery_checked_at marker, per-order exception isolation and localized
 * message text.
 */
class CartRecoveryHardeningTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{to: string, message: string}> */
    public array $sentSms = [];

    /** @var (\Closure(string, string): void)|null */
    public ?\Closure $smsFault = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.admin_allow_marketing_automation' => true]);
        app()->setLocale('en');
        Mail::fake();

        $this->app->instance(Sms::class, new class($this) implements Sms
        {
            public function __construct(private CartRecoveryHardeningTest $test) {}

            public function send(string $to, string $message, ?int $storeId = null, string $feature = 'general', bool $marketing = false): bool
            {
                return $this->test->handleSms($to, $message);
            }

            public function normalizePhone(string $phone): string
            {
                return $phone;
            }
        });
    }

    public function handleSms(string $to, string $message): bool
    {
        if ($this->smsFault !== null) {
            ($this->smsFault)($to, $message);
        }

        $this->sentSms[] = ['to' => $to, 'message' => $message];

        return true;
    }

    private function storeFixture(array $options = []): Store
    {
        $store = Store::create([
            'user_id' => User::factory()->create(['status' => 1])->id,
            'slug' => Str::slug('R').'-'.Str::random(5), 'color' => '#111', 'title' => 'Pizza', 'sub_title' => 'T',
            'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St',
            'phone' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($options as $key => $value) {
            StoreOption::updateStoreOption($store->id, $key, $value);
        }

        return $store;
    }

    private function customer(Store $store, bool $smsConsent = true): Customer
    {
        return Customer::create([
            'store_id' => $store->id,
            'name' => 'Sam',
            'email' => Str::lower(Str::random(10)).'@x.com',
            'phone' => '+1555'.random_int(1000000, 9999999),
            'marketing_consent' => false,
            'sms_consent' => $smsConsent,
        ]);
    }

    private function order(Store $store, ?Customer $customer = null, string $phone = '+15551234567', ?string $locale = null): Order
    {
        $order = Order::create([
            'store_id' => $store->id,
            'customer_name' => 'Sam',
            'phone_number' => $phone,
            'customer_id' => $customer?->id,
            'status' => 'unpaid',
            'track_token' => Str::random(32),
            'locale' => $locale,
        ]);
        $order->forceFill(['created_at' => now()->subMinutes(40)])->save();

        return $order;
    }

    /** Bulk-insert abandoned orders without firing model events (the batch filler). */
    private function bulkOrders(Store $store, int $count): void
    {
        $createdAt = now()->subMinutes(40);
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'store_id' => $store->id,
                'customer_name' => 'Sam',
                'phone_number' => '+1555'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'status' => 'unpaid',
                'track_token' => Str::random(32),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('orders')->insert($chunk);
        }
    }

    public function test_ineligible_store_orders_do_not_crowd_out_an_eligible_store(): void
    {
        $off = $this->storeFixture();
        $this->bulkOrders($off, 600);

        $on = $this->storeFixture(['customer_cart_sms' => '1']);
        $eligible = $this->order($on, $this->customer($on));

        $nudged = app(CartRecoveryService::class)->run();

        $this->assertSame(1, $nudged);
        $this->assertCount(1, $this->sentSms);
        $this->assertSame('sms', $eligible->fresh()->cart_recovery_channel);
        $this->assertNotNull($eligible->fresh()->wa_cart_recovery_at);
        $this->assertNotNull($eligible->fresh()->cart_recovery_checked_at);
        $this->assertSame(0, Order::where('store_id', $off->id)->whereNotNull('cart_recovery_checked_at')->count());
    }

    public function test_checked_marker_prevents_repicking_orders_that_sent_nothing(): void
    {
        $store = $this->storeFixture(['customer_cart_sms' => '1']);

        // Guest with no phone/customer cannot be nudged; it must still be marked examined.
        $stuck = $this->order($store, null, '');
        $this->assertSame(0, app(CartRecoveryService::class)->run());
        $this->assertCount(0, $this->sentSms);
        $this->assertNotNull($stuck->fresh()->cart_recovery_checked_at);
        $this->assertNull($stuck->fresh()->wa_cart_recovery_at);

        // An otherwise eligible order that was already checked (nothing sent) is not re-picked.
        $checked = $this->order($store, $this->customer($store));
        $checked->forceFill(['cart_recovery_checked_at' => now()])->save();

        $this->assertSame(0, app(CartRecoveryService::class)->run());
        $this->assertCount(0, $this->sentSms);
        $this->assertNull($checked->fresh()->wa_cart_recovery_at);
        $this->assertNotNull($checked->fresh()->cart_recovery_checked_at);
    }

    public function test_one_failing_order_does_not_abort_the_batch_and_is_not_stamped(): void
    {
        $store = $this->storeFixture(['customer_cart_sms' => '1']);
        $first = $this->order($store, $this->customer($store));
        $second = $this->order($store, $this->customer($store));

        $calls = 0;
        $this->smsFault = function (string $to, string $message) use (&$calls): void {
            $calls++;
            if ($calls === 1) {
                throw new \RuntimeException('sms provider down');
            }
        };

        $this->assertSame(1, app(CartRecoveryService::class)->run());

        $this->assertNull($first->fresh()->wa_cart_recovery_at);
        $this->assertNull($first->fresh()->cart_recovery_checked_at, 'a failed order is retried next run');
        $this->assertSame('sms', $second->fresh()->cart_recovery_channel);
        $this->assertNotNull($second->fresh()->wa_cart_recovery_at);
        $this->assertNotNull($second->fresh()->cart_recovery_checked_at);
        $this->assertCount(1, $this->sentSms);
    }

    public function test_body_is_rendered_in_the_current_order_locale(): void
    {
        app()->setLocale('ar');
        $store = $this->storeFixture(['customer_cart_sms' => '1']);
        $this->order($store, $this->customer($store));

        $this->assertSame(1, app(CartRecoveryService::class)->run());

        $this->assertCount(1, $this->sentSms);
        $this->assertStringContainsString('لم يكتمل', $this->sentSms[0]['message']);
        $this->assertStringNotContainsString('you left order', $this->sentSms[0]['message']);
    }
}
