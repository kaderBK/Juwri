<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pharmacy;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Support\MessageDiscretion;
use Juwri\Modules\Menu\Jobs\SendStockAlertsJob;
use Juwri\Modules\Menu\Mail\BackInStockMail;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\StockAlert;
use Juwri\Modules\Messaging\Contracts\MessageQueue;
use Juwri\Modules\Ordering\Services\Grocery\GroceryNotices;
use Juwri\Modules\Ordering\Services\Payments\PendingPaymentCancelledNotice;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\TestCase;

/**
 * P6-46: a pharmacy with "discreet messages" on (the default) never names a product in a customer SMS, WhatsApp
 * message or email; with it off, or for any other store type, the builders behave as before.
 */
class PharmacyDiscreetMessagesTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{0: string, 1: string, 2: string}> channel, to, text */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->travelTo(Carbon::parse('2026-10-02 06:44:50', 'UTC'));
        $this->mock(MessageQueue::class, function ($mock) {
            $mock->shouldReceive('sms')->andReturnUsing(function (string $to, string $message) {
                $this->sent[] = ['sms', $to, $message];
            });
            $mock->shouldReceive('whatsApp')->andReturnUsing(function (string $to, string $message) {
                $this->sent[] = ['whatsapp', $to, $message];
            });
        });
    }

    private function pharmacy(Store $store, ?bool $discreet = null): Store
    {
        Store::query()->whereKey($store->id)->update(['store_type' => 'pharmacy']);
        if ($discreet !== null) {
            StoreOption::updateStoreOption($store->id, MessageDiscretion::OPTION, $discreet ? '1' : '0');
        }

        return $store->fresh();
    }

    public function test_discretion_is_on_by_default_for_pharmacies_only(): void
    {
        $store = (new ApiFixtures)->freshMart();
        $this->assertFalse(MessageDiscretion::enabled($store));
        $this->assertSame('Bananas', MessageDiscretion::itemLabel($store, 'Bananas'));

        $store = $this->pharmacy($store);
        $this->assertTrue(MessageDiscretion::enabled($store));
        $this->assertTrue(MessageDiscretion::enabled((int) $store->id));
        $this->assertSame('1 item', MessageDiscretion::itemLabel($store, 'Ibuprofen 400mg'));
        $this->assertSame('2 items', MessageDiscretion::itemsLine($store, ['Ibuprofen 400mg', 'Loratadine']));

        $store = $this->pharmacy($store, false);
        $this->assertFalse(MessageDiscretion::enabled($store));
        $this->assertSame('Ibuprofen 400mg, Loratadine', MessageDiscretion::itemsLine($store, ['Ibuprofen 400mg', 'Loratadine']));
    }

    /** @return array{0: Store, 1: Menu, 2: MenuVariant} */
    private function stockAlerts(): array
    {
        $store = (new ApiFixtures)->duneRetail();
        $xs = MenuVariant::query()->where('public_id', '019e8a90-b580-7d21-8841-c8ec7cc5c1e6')->firstOrFail();
        $this->postJson(route('storefront.stockAlerts.store', ['slug' => $store->slug]), [
            'item_id' => ApiFixtures::SHIRT, 'variant_id' => $xs->public_id, 'channel' => 'email', 'contact' => 'noura@example.com', 'marketing_opt_in' => true,
        ])->assertCreated();
        $email = StockAlert::query()->firstOrFail();
        foreach (['sms' => '+966551234567', 'whatsapp' => '+966551234568'] as $channel => $phone) {
            $copy = $email->replicate();
            $copy->forceFill(['channel' => $channel, 'contact' => $phone, 'contact_hash' => hash('sha256', $channel.$phone)])->save();
        }
        MenuVariant::query()->whereKey($xs->id)->update(['stock_qty' => 4]);

        return [$store, Menu::query()->where('public_id', ApiFixtures::SHIRT)->firstOrFail(), $xs];
    }

    public function test_back_in_stock_messages_hide_the_product_name_at_a_discreet_pharmacy(): void
    {
        Mail::fake();
        [$store, $shirt, $xs] = $this->stockAlerts();
        $this->pharmacy($store);

        app()->call([new SendStockAlertsJob((int) $shirt->id, (int) $xs->id), 'handle']);

        $this->assertCount(2, $this->sent);
        foreach ($this->sent as [$channel, , $text]) {
            $this->assertStringNotContainsStringIgnoringCase('linen', $text, $channel);
            $this->assertStringContainsString('1 item', $text, $channel);
        }
        Mail::assertQueued(BackInStockMail::class, function (BackInStockMail $mail) {
            $html = $mail->render();

            return $mail->itemName === '1 item' && ! str_contains(strtolower($html), 'linen');
        });
    }

    public function test_back_in_stock_messages_name_the_product_when_discretion_is_off(): void
    {
        Mail::fake();
        [$store, $shirt, $xs] = $this->stockAlerts();
        $this->pharmacy($store, false);

        app()->call([new SendStockAlertsJob((int) $shirt->id, (int) $xs->id), 'handle']);

        $this->assertCount(2, $this->sent);
        foreach ($this->sent as [$channel, , $text]) {
            $this->assertStringContainsString('Relaxed linen shirt', $text, $channel);
        }
        Mail::assertQueued(BackInStockMail::class, fn (BackInStockMail $mail) => str_contains($mail->itemName, 'Relaxed linen shirt'));
    }

    public function test_order_status_builders_never_carry_product_names(): void
    {
        $fx = new ApiFixtures;
        $store = $fx->freshMart();
        $order = $fx->order($store, 'trk_discreet_00000000000000001', ['phone_number' => '+966551234567', 'status' => 'unpaid']);
        $store = $this->pharmacy($store);
        $name = (string) Menu::query()->where('store_id', $store->id)->value('name');
        $this->assertNotSame('', $name);
        StoreSettings::set((int) $store->id, 'notify_customer_pending_payment_cancelled', true);
        StoreSettings::set((int) $store->id, 'customer_status_sms', true);

        foreach ([true, false] as $discreet) {
            $this->pharmacy($store, $discreet);
            $this->sent = [];
            $this->assertTrue(app(PendingPaymentCancelledNotice::class)->send($order));
            $this->assertTrue(app(GroceryNotices::class)->replacementNeeded($order->fresh()));
            $this->assertTrue(app(GroceryNotices::class)->readyToCollect($order->fresh()));
            Cache::flush();

            $this->assertCount(3, $this->sent);
            foreach ($this->sent as [, , $text]) {
                $this->assertStringNotContainsString($name, $text);
                $this->assertStringContainsString('#'.$order->id, $text);
            }
        }
    }

    public function test_the_owner_can_switch_discreet_messages_off_and_on(): void
    {
        $fx = new ApiFixtures;
        $owner = $fx->owner();
        EntitlementOverride::create(['user_id' => $owner->id, 'feature_key' => 'multi_vertical', 'value' => '1', 'source_type' => 'admin']);
        $store = $this->pharmacy($fx->store($owner, 'care-pharmacy', 'Care Pharmacy', 'retail'));

        $this->actingAs($owner)->get(route('pharmacy.compliance.index', $store))->assertOk()->assertSee('discreet_messages', false);
        $this->actingAs($owner)->put(route('pharmacy.compliance.settings', $store), [])->assertRedirect();
        $this->assertFalse(MessageDiscretion::enabled($store->fresh()));
        $this->actingAs($owner)->put(route('pharmacy.compliance.settings', $store), ['discreet_messages' => '1'])->assertRedirect();
        $this->assertTrue(MessageDiscretion::enabled($store->fresh()));
    }
}
