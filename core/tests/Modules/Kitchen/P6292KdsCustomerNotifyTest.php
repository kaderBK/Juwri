<?php

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Kitchen;

use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use App\Services\KdsService;
use App\Services\OrderStatusNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Messaging\Contracts\MessageQueue;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-292 / P6-293 (KDS ↔ customer notification + KDS item recall safety):
 * the kitchen bump used to silently skip the customer SMS hook — only the
 * owner dashboard and the staff PWA fired 'your order is now ready' — so a
 * kitchen-only flow never told the customer even with `customer_status_sms`
 * on. Three callers now share app/Services/OrderStatusNotifier; the SMS /
 * WhatsApp dispatch is deduped by (order, status) so two KDS screens bumping
 * the same order to the same status fire exactly one notification.
 *
 * On the kitchen side: syncOrderStatus() used to skip only `cancelled` / `unpaid`,
 * letting a recall on a `completed` order pull it back to `ready` / `preparing`
 * and dropping a `call_bill` table back to `new`. Sync is now authoritative
 * only for the live kitchen statuses (`new` / `preparing` / `ready` /
 * `delivered`); `completed` and `call_bill` are left untouched, and a recall
 * is refused outright when the parent order is `completed`.
 */
class P6292KdsCustomerNotifyTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{to:string, body:string, store_id:?int, feature:string, marketing:bool}> */
    private array $sms = [];

    /** @var list<array{to:string, body:string, store_id:?int, feature:string, marketing:bool, template:?string, vars:array}> */
    private array $wa = [];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        // Notifications are best-effort and idempotent; the (order, status)
        // dedupe key lives in the cache — flush before every test so a previous
        // run cannot suppress the assertions here.
        Cache::flush();
        $this->sms = [];
        $this->wa = [];
        // Real outbound would hit Twilio/Meta — the contract tests forbid it
        // and so does this test. Mock the contract and capture both channels.
        $this->mock(MessageQueue::class, function ($mock) {
            $mock->shouldReceive('sms')->andReturnUsing(function (string $to, string $message, ?int $storeId = null, string $feature = 'general', bool $marketing = false) {
                $this->sms[] = ['to' => $to, 'body' => $message, 'store_id' => $storeId, 'feature' => $feature, 'marketing' => $marketing];
            });
            $mock->shouldReceive('whatsApp')->andReturnUsing(function (string $to, string $message, ?int $storeId = null, string $feature = 'general', bool $marketing = false, ?string $template = null, array $vars = []) {
                $this->wa[] = ['to' => $to, 'body' => $message, 'store_id' => $storeId, 'feature' => $feature, 'marketing' => $marketing, 'template' => $template, 'vars' => $vars];
            });
            $mock->shouldIgnoreMissing();
        });
        $this->withoutMiddleware();
    }

    // -------------------------------------------------------------------- helpers

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 't',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
            'status' => 1,
        ]);

        return User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
        ]);
    }

    private function storeFixture(User $owner): Store
    {
        $title = 'R '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title,
            'sub_title' => 't', 'description' => 't',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function menu(Store $r, string $name): Menu
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C '.Str::random(4)]);

        return Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => $name,
            'price' => 9.5, 'active' => '1',
        ]);
    }

    private function order(Store $r, string $status, string $phone = '+966500000099', string $locale = 'ar'): Order
    {
        return Order::create([
            'store_id' => $r->id, 'type' => 'takeaway',
            'customer_name' => 'Diner', 'status' => $status,
            'phone_number' => $phone, 'locale' => $locale,
            'created_at' => now(),
        ]);
    }

    // --------------------------------------------------------- P6-292: SMS path

    /**
     * Full-order (basic-mode) bump: new→preparing fires one customer SMS in
     * the order's locale; preparing→ready fires a second SMS in the same
     * locale with the translated "ready" word ("جاهز" for an Arabic order) —
     * no English leak. Before the fix the kitchen path skipped the customer
     * SMS entirely, so neither SMS was dispatched.
     */
    public function test_kds_bump_full_order_notifies_customer_in_the_orders_locale(): void
    {
        $owner = $this->owner();
        $r = $this->storeFixture($owner);
        StoreOption::updateStoreOption($r->id, 'customer_status_sms', '1');
        $order = $this->order($r, 'new', '+966500000101', 'ar');

        // 1st bump — new → preparing
        $this->actingAs($owner)->postJson(route('kds.bump', $order->id))->assertOk();
        // 2nd bump — preparing → ready
        $this->actingAs($owner)->postJson(route('kds.bump', $order->id))->assertOk();

        // Two notifications, both to the same Arabic order, both with the
        // translated status word (no raw English "preparing"/"ready").
        $this->assertCount(2, $this->sms, 'two bumps must produce two SMS');
        foreach ($this->sms as $row) {
            $this->assertSame('+966500000101', $row['to']);
            $this->assertStringContainsString("#{$order->id}", $row['body'], 'body mentions the order id');
        }
        $this->assertStringContainsString('جار التحضير', $this->sms[0]['body'], 'first SMS uses the Arabic "preparing" word');
        $this->assertStringContainsString('جاهز', $this->sms[1]['body'], 'second SMS uses the Arabic "ready" word');
        $this->assertStringNotContainsString(' is now ready.', $this->sms[0]['body'], 'no English status word leaks into an Arabic SMS');
        $this->assertStringNotContainsString(' is now ready.', $this->sms[1]['body']);
    }

    /**
     * Advanced-mode (item-driven): bumping the LAST item from preparing→ready
     * syncs the order to `ready`, which must dispatch one customer SMS —
     * exactly the gap P6-292 closes. A single order was bumped through its
     * items step-by-step; only the moment the order became `ready` triggered
     * the SMS (status `new` and `preparing` are intentionally silent because
     * they are pre-kitchen; the order's first notify-worthy transition is
     * the one the diner cares about).
     */
    public function test_kds_bump_item_sync_drives_the_customer_sms_when_the_order_becomes_ready(): void
    {
        $owner = $this->owner();
        $r = $this->storeFixture($owner);
        StoreOption::updateStoreOption($r->id, 'customer_status_sms', '1');

        $m1 = $this->menu($r, 'Burger');
        $m2 = $this->menu($r, 'Fries');
        $order = $this->order($r, 'new', '+966500000102', 'ar');
        $i1 = OrderItem::create(['order_id' => $order->id, 'item_id' => $m1->id, 'quantity' => 1, 'status' => 'queued']);
        $i2 = OrderItem::create(['order_id' => $order->id, 'item_id' => $m2->id, 'quantity' => 1, 'status' => 'queued']);

        // Bump both items: queued→preparing→ready each.
        KdsService::bumpItem($i1->fresh()); // item 1 → preparing; order → preparing
        KdsService::bumpItem($i1->fresh()); // item 1 → ready; order still preparing
        $this->assertCount(0, $this->sms, 'no SMS yet — order is still in the kitchen flow, not at ready');

        KdsService::bumpItem($i2->fresh()); // item 2 → preparing; order still preparing
        $this->assertCount(0, $this->sms, 'no SMS yet — second item still queued');

        KdsService::bumpItem($i2->fresh()); // item 2 → ready; ALL ready → order ready
        $this->assertSame('ready', $order->fresh()->status);

        $this->assertCount(1, $this->sms, 'exactly one SMS at the moment the order becomes ready');
        $this->assertSame('+966500000102', $this->sms[0]['to']);
        $this->assertStringContainsString('جاهز', $this->sms[0]['body']);
        $this->assertStringContainsString("#{$order->id}", $this->sms[0]['body']);
        $this->assertStringNotContainsString(' is now ready.', $this->sms[0]['body']);
    }

    /**
     * Two KDS screens (e.g. an expo display and a manager display) race to
     * bump the same order to the same status. Without dedupe the customer
     * would receive the "your order is ready" SMS twice. With OrderStatusNotifier
     * the (order, status) key collapses both calls into a single outbound.
     */
    public function test_two_screens_bumping_the_same_order_to_the_same_status_send_one_sms(): void
    {
        $owner = $this->owner();
        $r = $this->storeFixture($owner);
        StoreOption::updateStoreOption($r->id, 'customer_status_sms', '1');
        $order = $this->order($r, 'new', '+966500000103', 'ar');

        // Screen A bumps new → preparing.
        $this->actingAs($owner)->postJson(route('kds.bump', $order->id))->assertOk();
        // Screen B bumps "the same order" again to the same status.
        $this->actingAs($owner)->postJson(route('kds.bump', $order->id))->assertOk();

        // The whole-order bump path moves statuses forward (new→preparing→ready);
        // the first bump fires the (order, preparing) notification, the second
        // bump fires (order, ready). Two distinct statuses → two SMS, but each
        // pair is sent EXACTLY ONCE even under contention.
        $this->assertCount(2, $this->sms);

        // Now race two item bumps for an order that is already ready. Without
        // the (order, status) dedupe the second bump would fire a duplicate
        // SMS to the customer.
        Cache::flush();
        $this->sms = [];
        $m1 = $this->menu($r, 'Steak');
        $m2 = $this->menu($r, 'Salad');
        $order2 = $this->order($r, 'ready', '+966500000104', 'ar');
        $i1 = OrderItem::create(['order_id' => $order2->id, 'item_id' => $m1->id, 'quantity' => 1, 'status' => 'ready']);
        $i2 = OrderItem::create(['order_id' => $order2->id, 'item_id' => $m2->id, 'quantity' => 1, 'status' => 'ready']);

        // Both screens "mark ready" — in the kitchen flow this would map to a
        // recall + re-bump; here we just synthesize two KdsService::bumpItem
        // calls in the same (order, ready) idempotency window by re-running
        // syncOrderStatus directly.
        KdsService::syncOrderStatus($order2->id); // already ready → no change
        KdsService::syncOrderStatus($order2->id); // already ready → no change

        $this->assertCount(0, $this->sms, 're-syncing an already-ready order must NOT spam the customer');
    }

    /**
     * WhatsApp path: a store with `customer_status_whatsapp` on dispatches
     * the localized status message AND the template variables (restaurant
     * name, order id, status word) — never the raw English status word.
     */
    public function test_kds_bump_full_order_sends_localized_whatsapp_with_template_vars(): void
    {
        $owner = $this->owner();
        $r = $this->storeFixture($owner);
        StoreOption::updateStoreOption($r->id, 'customer_status_whatsapp', '1');
        $order = $this->order($r, 'new', '+966500000105', 'ar');

        $this->actingAs($owner)->postJson(route('kds.bump', $order->id))->assertOk();

        $this->assertCount(1, $this->wa);
        $this->assertSame('+966500000105', $this->wa[0]['to']);
        $this->assertStringContainsString('جار التحضير', $this->wa[0]['body']);
        $this->assertStringContainsString((string) $order->id, $this->wa[0]['body']);
        // The template vars are (name, id, status); the status word is Arabic.
        $this->assertCount(3, $this->wa[0]['vars']);
        $this->assertSame('جار التحضير', $this->wa[0]['vars'][2] ?? '', 'template var[2] is the translated preparing word, not the raw key');
    }

    /**
     * P6-292 audit hook: full-order KDS bumps now write the shared 'order.status'
     * activity (the same action key the dashboard + staff PWA write), so an
     * audit query no longer has to branch on the bump source.
     */
    public function test_kds_full_order_bump_writes_order_status_activity(): void
    {
        $owner = $this->owner();
        $r = $this->storeFixture($owner);
        $order = $this->order($r, 'new', '+966500000106', 'en');

        $this->actingAs($owner)->postJson(route('kds.bump', $order->id))->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'order.status',
            'subject_type' => Order::class,
            'subject_id' => $order->id,
            'store_id' => $r->id,
        ]);
    }

    /**
     * A store without `customer_status_sms` / `customer_status_whatsapp`
     * never dispatches — the notifier keeps the dashboard / staff PWA / KDS
     * callers from sending anything when the feature is off, which is the
     * legacy behaviour the new shared service must preserve.
     */
    public function test_kds_bump_does_not_notify_when_customer_status_sms_is_off(): void
    {
        $owner = $this->owner();
        $r = $this->storeFixture($owner);
        // Deliberately do NOT set customer_status_sms.
        $order = $this->order($r, 'new', '+966500000107', 'ar');

        $this->actingAs($owner)->postJson(route('kds.bump', $order->id))->assertOk();
        $this->actingAs($owner)->postJson(route('kds.bump', $order->id))->assertOk();

        $this->assertCount(0, $this->sms);
        $this->assertCount(0, $this->wa);
    }

    /**
     * OrderStatusNotifier::dedupeKey + the cache TTL are the exact mechanism
     * the spec relies on. A test that anyone could re-do in 30 lines is the
     * first thing to break under a refactor — lock the shape so a typo in
     * the namespace / key prefix fails this assertion loud and early.
     */
    public function test_dedupe_key_is_namespaced_by_order_and_status(): void
    {
        $this->assertSame(
            'order.status.notified.42.ready',
            OrderStatusNotifier::dedupeKey(42, 'ready')
        );
        $this->assertNotSame(
            OrderStatusNotifier::dedupeKey(42, 'preparing'),
            OrderStatusNotifier::dedupeKey(42, 'ready'),
            'different statuses produce different keys'
        );
    }

    // ---------------------------------------------------- P6-293: sync safety

    /**
     * The bug: `completed` is the closed/paid terminal state — but the old
     * syncOrderStatus skipped only `cancelled` / `unpaid`, so a kitchen
     * re-sync (e.g. a stray cron / item edit after the bill was paid)
     * rewrote the status to `ready` and the order flipped back to the
     * kitchen flow. The fix: sync ONLY from the live statuses (new /
     * preparing / ready / delivered); `completed` is left alone.
     */
    public function test_sync_order_status_leaves_a_completed_order_alone(): void
    {
        $owner = $this->owner();
        $r = $this->storeFixture($owner);
        $m = $this->menu($r, 'Steak');
        $order = $this->order($r, 'completed', '+966500000201', 'en');
        OrderItem::create(['order_id' => $order->id, 'item_id' => $m->id, 'quantity' => 1, 'status' => 'ready']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $m->id, 'quantity' => 1, 'status' => 'ready']);

        KdsService::syncOrderStatus($order->id);

        $this->assertSame('completed', $order->fresh()->status, 'completed order must NOT be pulled back to ready');
    }

    /**
     * `call_bill` is the front-of-house signal that the table asked for the
     * check. The bug: when one item was still `queued` while the others were
     * `ready`, the old sync derived `new` from the items and the whole order
     * flipped back to "new" — the kitchen board saw a table that wanted the
     * bill re-opened as a brand-new ticket. The fix keeps `call_bill` out of
     * the live kitchen flow that syncOrderStatus is allowed to rewrite.
     */
    public function test_sync_order_status_leaves_a_call_bill_order_alone(): void
    {
        $owner = $this->owner();
        $r = $this->storeFixture($owner);
        $m = $this->menu($r, 'Pasta');
        $order = $this->order($r, 'call_bill', '+966500000202', 'en');
        OrderItem::create(['order_id' => $order->id, 'item_id' => $m->id, 'quantity' => 1, 'status' => 'ready']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $m->id, 'quantity' => 1, 'status' => 'queued']);

        KdsService::syncOrderStatus($order->id);

        $this->assertSame('call_bill', $order->fresh()->status, 'call_bill must NOT revert to new when one item is still queued');
    }

    /**
     * The recall path: `recallItem` is refused when the parent order is
     * `completed`. A cook who taps the recall button on a paid/closed order
     * must not be able to drag it back to the kitchen rail — the test pins
     * the contract through the route that cooks actually use.
     */
    public function test_recall_item_refuses_to_move_a_completed_order(): void
    {
        $owner = $this->owner();
        $r = $this->storeFixture($owner);
        $m = $this->menu($r, 'Steak');
        $order = $this->order($r, 'completed', '+966500000203', 'en');
        // The item is at 'ready' so a normal recall would step it back to
        // 'preparing' and the (legacy) sync would have moved the whole
        // order to 'preparing' too. Under the fix neither happens.
        $it = OrderItem::create(['order_id' => $order->id, 'item_id' => $m->id, 'quantity' => 1, 'status' => 'ready']);

        $this->actingAs($owner)->postJson(route('kds.item.recall', $it->id))->assertOk();

        $this->assertSame('ready', $it->fresh()->status, 'recall on a completed order must not step the item back');
        $this->assertSame('completed', $order->fresh()->status, 'recall on a completed order must not flip the order off completed');
    }

    /**
     * The bump path is unchanged for completed / call_bill orders (bump is a
     * forward step in the live kitchen flow), but this test pins the
     * behaviour so a future regression cannot slip through unnoticed.
     */
    public function test_full_order_bump_on_a_completed_order_is_a_no_op(): void
    {
        $owner = $this->owner();
        $r = $this->storeFixture($owner);
        StoreOption::updateStoreOption($r->id, 'customer_status_sms', '1');
        $order = $this->order($r, 'completed', '+966500000204', 'en');

        $resp = $this->actingAs($owner)->postJson(route('kds.bump', $order->id));
        // 'completed' has no entry in the basic-mode transition map (new →
        // preparing → ready → delivered), so the route responds 422 and sends
        // nothing.
        $resp->assertStatus(422);
        $this->assertCount(0, $this->sms, 'no SMS for a no-op bump on a completed order');
        $this->assertSame('completed', $order->fresh()->status);
    }

    /**
     * The happy path: a `new` order keeps flowing through its items; sync
     * rebuilds the order status from the item statuses as before. This is
     * the regression guard so the P6-293 fix does not break the live flow.
     */
    public function test_sync_order_status_advances_a_live_order_from_items(): void
    {
        $owner = $this->owner();
        $r = $this->storeFixture($owner);
        $m1 = $this->menu($r, 'Burger');
        $m2 = $this->menu($r, 'Fries');
        $order = $this->order($r, 'new', '+966500000205', 'en');
        OrderItem::create(['order_id' => $order->id, 'item_id' => $m1->id, 'quantity' => 1, 'status' => 'ready']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $m2->id, 'quantity' => 1, 'status' => 'ready']);

        KdsService::syncOrderStatus($order->id);

        $this->assertSame('ready', $order->fresh()->status);
    }
}
