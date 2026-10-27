<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Inventory;

use App\Models\Ingredient;
use App\Models\StoreOption;
use Illuminate\Bus\Dispatcher;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcherContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Juwri\Core\Jobs\CreateNotificationJob;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Inventory\Mail\LowStockAlertMail;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Messaging\Contracts\MessageQueue;
use Juwri\Modules\Messaging\Jobs\SendWhatsAppJob;
use Tests\TestCase;

/**
 * HPMS E8 — daily low-stock digest (notification + optional WhatsApp), deduped.
 */
class LowStockDigestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    private function store(string $phone = '1'): Store
    {
        $owner = User::factory()->create(['status' => 1]);
        $t = 'S'.Str::random(5);

        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($t).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => $t, 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'x.png', 'cover_image' => 'x.png', 'address' => 'a', 'phone' => $phone]);
    }

    private function lowItem(Store $store): Menu
    {
        return Menu::create(['store_id' => $store->id, 'name' => 'Item'.Str::random(4), 'track_inventory' => 1, 'stock_qty' => 1, 'low_stock_threshold' => 5]);
    }

    private function lowIngredient(Store $store): Ingredient
    {
        return Ingredient::create(['store_id' => $store->id, 'name' => 'Ing'.Str::random(4), 'active' => 1, 'par_level' => 10, 'current_stock' => 2]);
    }

    public function test_digest_notifies_with_low_count(): void
    {
        Bus::fake();
        $store = $this->store();
        $this->lowItem($store);
        $this->lowIngredient($store);

        $this->artisan('inventory:low-stock-digest')->assertExitCode(0);

        Bus::assertDispatched(CreateNotificationJob::class, function ($job) {
            return $job->type === 'low_stock' && str_contains($job->title, '2');
        });
    }

    public function test_dedupe_no_repeat_until_new_crossing(): void
    {
        Bus::fake();
        $store = $this->store();
        $this->lowItem($store);

        $this->artisan('inventory:low-stock-digest');
        Bus::assertDispatchedTimes(CreateNotificationJob::class, 1);

        // Same low set — no new crossing → no second notification.
        $this->artisan('inventory:low-stock-digest');
        Bus::assertDispatchedTimes(CreateNotificationJob::class, 1);

        // A newly-low item crosses → notifies again.
        $this->lowItem($store);
        $this->artisan('inventory:low-stock-digest');
        Bus::assertDispatchedTimes(CreateNotificationJob::class, 2);
    }

    public function test_alerts_toggle_off_sends_nothing(): void
    {
        Bus::fake();
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'low_stock_alerts', '0');
        $this->lowItem($store);

        $this->artisan('inventory:low-stock-digest');
        Bus::assertNotDispatched(CreateNotificationJob::class);
    }

    public function test_whatsapp_only_when_toggle_on(): void
    {
        Bus::fake();
        $store = $this->store('966500000000'); // resolvable WhatsApp number
        StoreOption::updateStoreOption($store->id, 'low_stock_whatsapp', '1');
        $this->lowItem($store);

        $this->artisan('inventory:low-stock-digest');
        Bus::assertDispatched(SendWhatsAppJob::class, fn ($job) => $job->feature === 'low_stock');
    }

    public function test_no_whatsapp_when_toggle_off(): void
    {
        Bus::fake();
        $store = $this->store('966500000000');
        $this->lowItem($store); // low_stock_whatsapp unset → default off

        $this->artisan('inventory:low-stock-digest');
        Bus::assertNotDispatched(SendWhatsAppJob::class);
        Bus::assertDispatched(CreateNotificationJob::class); // notif still fires
    }

    public function test_no_notification_when_nothing_is_low(): void
    {
        Bus::fake();
        $store = $this->store();
        // A tracked item that is ABOVE threshold — not low.
        Menu::create(['store_id' => $store->id, 'name' => 'Full', 'track_inventory' => 1, 'stock_qty' => 50, 'low_stock_threshold' => 5]);

        $this->artisan('inventory:low-stock-digest');
        Bus::assertNotDispatched(CreateNotificationJob::class);
    }

    public function test_the_store_owner_gets_their_own_stores_alert_and_another_owner_gets_nothing(): void
    {
        Bus::fake();
        Mail::fake();
        $a = $this->store();
        $b = $this->store();
        $itemA = $this->lowItem($a);
        $ingA = $this->lowIngredient($a);
        // store B has plenty of stock: nothing low there

        $this->artisan('inventory:low-stock-digest')->assertExitCode(0);

        Mail::assertQueued(LowStockAlertMail::class, 1);
        Mail::assertQueued(LowStockAlertMail::class, function (LowStockAlertMail $m) use ($a, $itemA, $ingA) {
            return $m->hasTo($a->user->email)
                && $m->storeTitle === $a->title
                && $m->count === 2
                && in_array($itemA->name, $m->names, true)
                && in_array($ingA->name, $m->names, true);
        });
        Mail::assertNotQueued(LowStockAlertMail::class, fn (LowStockAlertMail $m) => $m->hasTo($b->user->email));
    }

    public function test_when_both_stores_run_low_each_owner_sees_only_their_own_items(): void
    {
        Bus::fake();
        Mail::fake();
        $a = $this->store();
        $b = $this->store();
        $itemA = $this->lowItem($a);
        $itemB = $this->lowItem($b);

        $this->artisan('inventory:low-stock-digest')->assertExitCode(0);

        Mail::assertQueued(LowStockAlertMail::class, 2);
        Mail::assertQueued(LowStockAlertMail::class, fn (LowStockAlertMail $m) => $m->hasTo($a->user->email) && $m->names === [$itemA->name]);
        Mail::assertQueued(LowStockAlertMail::class, fn (LowStockAlertMail $m) => $m->hasTo($b->user->email) && $m->names === [$itemB->name]);
    }

    public function test_the_owner_mail_dedupes_and_respects_the_alerts_toggle(): void
    {
        Bus::fake();
        Mail::fake();
        $a = $this->store();
        $off = $this->store();
        StoreOption::updateStoreOption($off->id, 'low_stock_alerts', '0');
        $this->lowItem($a);
        $this->lowItem($off);

        $this->artisan('inventory:low-stock-digest');
        $this->artisan('inventory:low-stock-digest'); // same low set: no repeat

        Mail::assertQueued(LowStockAlertMail::class, 1);
        Mail::assertNotQueued(LowStockAlertMail::class, fn (LowStockAlertMail $m) => $m->hasTo($off->user->email));
    }

    public function test_the_owner_mail_renders_in_arabic_with_the_store_and_item_names(): void
    {
        app()->setLocale('ar');
        $html = (new LowStockAlertMail('Nadia', 'Cafe Zed', 3, ['Latte', 'Mocha'], 'https://example.test/inv'))->render();

        $this->assertStringContainsString('تنبيه انخفاض المخزون', $html);
        $this->assertStringContainsString('Cafe Zed', $html);
        $this->assertStringContainsString('Latte', $html);
        $this->assertStringContainsString('و1 أخرى', str_replace(['١'], ['1'], $html));
        $this->assertStringNotContainsString('are at or below their low-stock level', $html);
    }

    /**
     * P6-300: a tracked item whose stock is above its threshold must NOT count
     * as low on the page nor in the digest (items.low_stock_threshold is NOT NULL).
     */
    public function test_stock_above_threshold_is_not_low_on_page_or_in_digest(): void
    {
        Bus::fake();
        $store = $this->store();
        // Tracked, stock 6 above threshold 5.
        Menu::create([
            'store_id' => $store->id,
            'name' => 'NoThr',
            'track_inventory' => 1,
            'stock_qty' => 6,
            'low_stock_threshold' => 5,
        ]);

        // Digest — the above-threshold item is excluded, no notification.
        $this->artisan('inventory:low-stock-digest')->assertExitCode(0);
        Bus::assertNotDispatched(CreateNotificationJob::class);

        // Page — the in-memory equivalent excludes it; the "Low stock" dd is 0.
        $this->withoutMiddleware();
        $body = $this->actingAs($store->user)
            ->get(route('inventory.index', ['store_id' => $store->id]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('<dt>Low stock</dt><dd>0</dd>', $body);
    }

    /**
     * P6-300: stock AT threshold is still low (the rule is `<=`). The same
     * rule must drive the page count and the digest notification.
     */
    public function test_threshold_5_stock_5_is_low_on_page_and_in_digest(): void
    {
        Bus::fake();
        Mail::fake();
        $store = $this->store();
        $item = Menu::create([
            'store_id' => $store->id,
            'name' => 'Edge',
            'track_inventory' => 1,
            'stock_qty' => 5,
            'low_stock_threshold' => 5,
        ]);

        $this->artisan('inventory:low-stock-digest')->assertExitCode(0);
        Bus::assertDispatched(CreateNotificationJob::class);
        Mail::assertQueued(LowStockAlertMail::class, fn (LowStockAlertMail $m) => $m->hasTo($store->user->email));

        // Page — the in-memory equivalent matches the digest; the "Low stock" dd is 1.
        $this->withoutMiddleware();
        $body = $this->actingAs($store->user)
            ->get(route('inventory.index', ['store_id' => $store->id]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('<dt>Low stock</dt><dd>1</dd>', $body);
    }

    /**
     * P6-280: when EVERY attempted channel throws (notification dispatch,
     * owner e-mail, opt-in WhatsApp), the digest state is NOT advanced — the
     * next run sees the same newly-crossed set and re-attempts. Switching one
     * channel to succeed on the next run is enough to confirm re-send.
     */
    public function test_when_all_channels_throw_state_is_not_advanced_and_next_run_re_sends(): void
    {
        $store = $this->store();
        $this->lowItem($store);
        // Opt the store into WhatsApp so the third channel is also attempted.
        StoreOption::updateStoreOption($store->id, 'low_stock_whatsapp', '1');

        // Notification dispatch throws (Bus dispatcher throws on every dispatch).
        $this->app->bind(BusDispatcherContract::class, function () {
            // Real queueing dispatcher (so Bus::fake() can wrap it later) that throws on every dispatch.
            return new class($this->app) extends Dispatcher
            {
                public function dispatch($command): mixed
                {
                    throw new \RuntimeException('notif fail');
                }

                public function dispatchSync($command, $handler = null): mixed
                {
                    throw new \RuntimeException('notif fail');
                }

                public function dispatchNow($command, $handler = null): mixed
                {
                    throw new \RuntimeException('notif fail');
                }
            };
        });

        // Mail fails on every send.
        $this->app->bind('mailer', function () {
            return new class extends Mailer
            {
                public function __construct() {}

                public function send($view, array $data = [], $callback = null)
                {
                    throw new \RuntimeException('mail fail');
                }
            };
        });

        // WhatsApp throws too.
        $this->mock(MessageQueue::class, function ($mock) {
            $mock->shouldReceive('whatsApp')->andThrow(new \RuntimeException('wa fail'));
        });

        // First run — every channel throws, no state advance.
        $this->artisan('inventory:low-stock-digest')->assertExitCode(0);
        $this->assertNull(StoreSettings::legacy($store->id, 'low_stock_digest_state'));

        // Second run — every channel still throws, state still untouched.
        $this->artisan('inventory:low-stock-digest')->assertExitCode(0);
        $this->assertNull(StoreSettings::legacy($store->id, 'low_stock_digest_state'));

        // Re-bind the bus dispatcher to a working fake and re-run — the digest
        // re-attempts (state was never advanced), and now succeeds, advancing
        // the state. Proves the next run really re-sent.
        Bus::fake();

        $this->artisan('inventory:low-stock-digest')->assertExitCode(0);
        Bus::assertDispatched(CreateNotificationJob::class);

        // State is now advanced.
        $this->assertNotNull(StoreSettings::legacy($store->id, 'low_stock_digest_state'));
    }

    /**
     * P6-280: a successful run advances the state and the next run with the
     * same low set does NOT repeat (nothing newly crossed, the dedup's whole
     * point).
     */
    public function test_success_advances_state_and_does_not_repeat_on_next_run(): void
    {
        Bus::fake();
        Mail::fake();
        $store = $this->store();
        $this->lowItem($store);

        // First run — succeeds, state advances.
        $this->artisan('inventory:low-stock-digest')->assertExitCode(0);
        Bus::assertDispatchedTimes(CreateNotificationJob::class, 1);
        Mail::assertQueued(LowStockAlertMail::class, 1);
        $stateAfterFirst = StoreSettings::legacy($store->id, 'low_stock_digest_state');
        $this->assertNotNull($stateAfterFirst);

        // Second run — same low set, nothing newly crossed → no repeat.
        $this->artisan('inventory:low-stock-digest')->assertExitCode(0);
        Bus::assertDispatchedTimes(CreateNotificationJob::class, 1);
        Mail::assertQueued(LowStockAlertMail::class, 1);

        // State persisted (same set) but no new notifications.
        $stateAfterSecond = StoreSettings::legacy($store->id, 'low_stock_digest_state');
        $this->assertSame($stateAfterFirst, $stateAfterSecond);
    }
}
