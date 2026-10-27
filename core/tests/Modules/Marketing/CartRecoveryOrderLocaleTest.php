<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Marketing;

use App\Models\Customer;
use App\Models\Order;
use App\Models\StoreOption;
use App\Services\Marketing\CartRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Messaging\Contracts\Sms;
use Tests\TestCase;

/**
 * BL-008: cart-recovery SMS language must come from the stored orders.locale,
 * not from the ambient locale, and the ambient locale must be restored after
 * each order is rendered.
 */
class CartRecoveryOrderLocaleTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{to: string, message: string}> */
    public array $sentSms = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.admin_allow_marketing_automation' => true]);
        app()->setLocale('en');
        Mail::fake();

        $this->app->instance(Sms::class, new class($this) implements Sms
        {
            public function __construct(private CartRecoveryOrderLocaleTest $test) {}

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
        $this->sentSms[] = ['to' => $to, 'message' => $message];

        return true;
    }

    private function store(array $options = []): Store
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

    public function test_arabic_stored_locale_renders_arabic_while_ambient_is_english(): void
    {
        $this->assertSame('en', app()->getLocale());

        $store = $this->store(['customer_cart_sms' => '1']);
        $this->order($store, $this->customer($store), '+15550000001', 'ar');

        $this->assertSame(1, app(CartRecoveryService::class)->run());

        $this->assertCount(1, $this->sentSms);
        $this->assertStringContainsString('لم يكتمل', $this->sentSms[0]['message']);
        $this->assertStringNotContainsString('you left order', $this->sentSms[0]['message']);
        $this->assertSame('en', app()->getLocale());
    }

    public function test_english_stored_locale_renders_english_while_ambient_is_english(): void
    {
        $this->assertSame('en', app()->getLocale());

        $store = $this->store(['customer_cart_sms' => '1']);
        $this->order($store, $this->customer($store), '+15550000002', 'en');

        $this->assertSame(1, app(CartRecoveryService::class)->run());

        $this->assertCount(1, $this->sentSms);
        $this->assertStringContainsString('you left order', $this->sentSms[0]['message']);
        $this->assertStringNotContainsString('لم يكتمل', $this->sentSms[0]['message']);
    }

    public function test_unsupported_stored_locale_falls_back_to_ambient_english(): void
    {
        $this->assertSame('en', app()->getLocale());

        $store = $this->store(['customer_cart_sms' => '1']);
        $this->order($store, $this->customer($store), '+15550000003', 'xx');

        $this->assertSame(1, app(CartRecoveryService::class)->run());

        $this->assertCount(1, $this->sentSms);
        $this->assertStringContainsString('you left order', $this->sentSms[0]['message']);
        $this->assertStringNotContainsString('لم يكتمل', $this->sentSms[0]['message']);
        $this->assertSame('en', app()->getLocale());
    }

    public function test_ambient_locale_is_restored_between_orders_in_one_run(): void
    {
        $this->assertSame('en', app()->getLocale());

        $store = $this->store(['customer_cart_sms' => '1']);
        $this->order($store, $this->customer($store), '+15550000004', 'ar');
        $this->order($store, $this->customer($store), '+15550000005', 'en');

        $this->assertSame(2, app(CartRecoveryService::class)->run());

        $this->assertCount(2, $this->sentSms);
        $this->assertSame('+15550000004', $this->sentSms[0]['to']);
        $this->assertStringContainsString('لم يكتمل', $this->sentSms[0]['message']);
        $this->assertStringNotContainsString('you left order', $this->sentSms[0]['message']);
        $this->assertSame('+15550000005', $this->sentSms[1]['to']);
        $this->assertStringContainsString('you left order', $this->sentSms[1]['message']);
        $this->assertStringNotContainsString('لم يكتمل', $this->sentSms[1]['message']);
        $this->assertSame('en', app()->getLocale());
    }
}
