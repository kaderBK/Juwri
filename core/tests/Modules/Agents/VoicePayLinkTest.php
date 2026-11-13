<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Services\KdsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Models\AgentConversation;
use Juwri\Modules\Agents\Models\AgentMessage;
use Juwri\Modules\Agents\Models\PhoneLine;
use Juwri\Modules\Agents\Services\Telephony\VoiceSession;
use Juwri\Modules\Ai\Services\AiService;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Messaging\Contracts\Sms;
use Juwri\Modules\Ordering\Contracts\HostedPayments;
use Juwri\Modules\Ordering\Services\Payments\HostedOrderPayments;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-09 V-15: a phone order is created `unpaid` + hosted and only reaches the kitchen
 * once its pay link is paid. This suite pins the contract from the money side:
 * the order row, the hosted Transaction, the SMS that carries the link, the staff-KDS
 * gate (unpaid out, phone_paid in), and the two "no pay link possible" handoffs
 * (withheld caller ID, no store gateway) plus a failed SMS.
 */
class VoicePayLinkTest extends TestCase
{
    use RefreshDatabase;

    /** The HostedPayments binding under test: real open(), configurable storeCanTake(). */
    private object $hosted;

    /** The Sms binding under test: records every send, returns a configurable result. */
    private object $sms;

    protected function setUp(): void
    {
        parent::setUp();
        // The V-15 flow under test only runs when pay links are required.
        config(['voice.require_pay_link' => true]);
        // The owner KDS route is behind a feature gate; the flow under test is not that gate.
        $this->withoutMiddleware();

        $this->hosted = new class(app(HostedOrderPayments::class)) implements HostedPayments
        {
            public bool $canTake = true;

            public function __construct(private HostedOrderPayments $real) {}

            public function storeCanTake(Store $store, float $total): bool
            {
                return $this->canTake;
            }

            public function storeCanHold(Store $store, float $amount): bool
            {
                return $this->real->storeCanHold($store, $amount);
            }

            public function open(Store $store, Order $order, ?string $returnUrl, ?string $ip, ?int $holdMinor = null): Transaction
            {
                return $this->real->open($store, $order, $returnUrl, $ip, $holdMinor);
            }

            public function transactionFor(Order $order): ?Transaction
            {
                return $this->real->transactionFor($order);
            }

            public function isOpen(Order $order): bool
            {
                return $this->real->isOpen($order);
            }

            public function expire(Order $order): bool
            {
                return $this->real->expire($order);
            }
        };
        app()->instance(HostedPayments::class, $this->hosted);

        $this->sms = new class implements Sms
        {
            /** @var list<array{to: string, message: string, store: int|null, feature: string}> */
            public array $sent = [];

            public bool $result = true;

            public function send(string $to, string $message, ?int $storeId = null, string $feature = 'general', bool $marketing = false): bool
            {
                $this->sent[] = ['to' => $to, 'message' => $message, 'store' => $storeId, 'feature' => $feature];

                return $this->result;
            }

            public function normalizePhone(string $phone): string
            {
                return (string) preg_replace('/\D+/', '', $phone);
            }
        };
        app()->instance(Sms::class, $this->sms);
    }

    private function fakeAi(array $script): void
    {
        $fake = new class extends AiService
        {
            public array $script = [];

            public int $idx = 0;

            public function __construct() {}

            public function json(array $messages, array $options = [], ?callable $validator = null, ?int $userId = null, ?int $storeId = null, string $feature = 'general'): array
            {
                return $this->script[$this->idx++] ?? ['reply' => 'done'];
            }
        };
        $fake->script = $script;
        app()->instance(AiService::class, $fake);
    }

    private function line(bool $orders = true): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'ai_chatbot' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Pizza', 'price' => 12, 'active' => 1]);
        $line = PhoneLine::create([
            'store_id' => $r->id, 'provider' => 'fake', 'number' => '+1555'.rand(1000, 9999),
            'active' => true, 'orders_enabled' => $orders,
        ]);

        return [$r, $item, $line, $owner];
    }

    private function orderStep(Menu $item): array
    {
        return ['tool' => 'create_order', 'args' => ['items' => [['item_id' => $item->id, 'quantity' => 1]], 'type' => 'takeaway', 'customer_name' => 'Caller']];
    }

    /** V-08: an order takes two turns — read-back (gated create_order), then "yes" (placed). */
    private function confirmed(Menu $item, string $placed): array
    {
        return [$this->orderStep($item), ['reply' => 'Shall I place it?'], $this->orderStep($item), ['reply' => $placed]];
    }

    private function orderCall(PhoneLine $line, string $sid, string $from): void
    {
        VoiceSession::turn($line, $sid, $from, 'one pizza');
        VoiceSession::turn($line, $sid, $from, 'yes please');
    }

    private function lastToolResult(): array
    {
        return (array) json_decode((string) AgentMessage::where('role', 'tool')->latest('id')->value('content'), true);
    }

    // ---- (a) confirmed phone order: unpaid + hosted + one Transaction + one SMS ----

    public function test_confirmed_phone_order_is_unpaid_hosted_and_texts_the_pay_link(): void
    {
        [$r, $item, $line] = $this->line();
        $this->fakeAi($this->confirmed($item, 'placed'));
        $this->orderCall($line, 'CA-PL1', '+15551110000');

        $orders = Order::where('store_id', $r->id)->get();
        $this->assertCount(1, $orders, 'exactly one order');
        $order = $orders->first();
        $this->assertSame('phone', $order->source);
        $this->assertSame('unpaid', $order->status);
        $this->assertSame('hosted', $order->payment_mode);
        $this->assertSame(0, (int) $order->is_paid);
        $this->assertNotNull($order->payment_expires_at);
        $this->assertTrue($order->payment_expires_at->isFuture());
        $this->assertTrue($order->payment_expires_at->lessThanOrEqualTo(now()->addMinutes(31)), 'window is ~30 minutes');

        $tx = Transaction::where('transaction_method', 'order')->where('product_id', $order->id)->get();
        $this->assertCount(1, $tx, 'exactly one hosted Transaction');
        $this->assertSame((int) $r->id, (int) $tx->first()->store_id);

        $this->assertCount(1, $this->sms->sent, 'exactly one SMS');
        $this->assertSame('+15551110000', $this->sms->sent[0]['to']);
        $this->assertStringContainsString('/payment/', $this->sms->sent[0]['message']);

        $out = $this->lastToolResult();
        $this->assertSame('link_required', $out['payment']);
        $this->assertTrue($out['pay_link_sent']);
    }

    public function test_pay_link_is_a_temporary_signed_url_and_the_bare_id_route_is_refused(): void
    {
        [$r, $item, $line] = $this->line();
        $this->fakeAi($this->confirmed($item, 'placed'));
        $this->orderCall($line, 'CA-PLS', '+15551119999');

        $order = Order::where('store_id', $r->id)->firstOrFail();
        $tx = Transaction::where('transaction_method', 'order')->where('product_id', $order->id)->firstOrFail();
        $this->assertSame(1, preg_match('~https?://\S+~', $this->sms->sent[0]['message'], $m));
        $url = $m[0];
        $this->assertStringContainsString('/payment/phone/'.$tx->id, $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);

        // Real route middleware (bindings + `signed`) for this check; setUp() strips every middleware.
        $this->withMiddleware()->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->get($url)->assertOk();
        $this->get(route('payment.index', $tx->id))->assertForbidden();
        // Flip the first signature char to a different hex digit (a fixed '0' left the URL unchanged ~1 in 16 runs).
        $tampered = preg_replace_callback('~signature=(\w)~', fn ($m) => 'signature='.($m[1] === '0' ? '1' : '0'), $url);
        $this->assertNotSame($url, $tampered);
        $this->get($tampered)->assertForbidden();
        $this->get(route('payment.phone', $tx->id))->assertForbidden();
        $this->travel(31)->minutes();
        $this->get($url)->assertForbidden();
    }

    // ---- (b) the pay-link order is gated out of the staff KDS until paid -----------

    public function test_unpaid_phone_order_is_hidden_from_the_kds_until_paid(): void
    {
        [$r, $item, $line, $owner] = $this->line();
        $this->fakeAi($this->confirmed($item, 'placed'));
        $this->orderCall($line, 'CA-PL2', '+15552220000');

        $order = Order::where('store_id', $r->id)->firstOrFail();
        $this->assertSame('unpaid', $order->status);

        $ids = collect($this->actingAs($owner)->getJson(route('kds.poll', ['store_id' => $r->id]))->assertOk()->json())
            ->pluck('id')->all();
        $this->assertNotContains($order->id, $ids, 'an unpaid order never reaches the kitchen');

        $order->forceFill(['is_paid' => 1, 'status' => 'pending'])->save();
        KdsService::syncOrderStatus($order->id);

        $tickets = collect($this->actingAs($owner)->getJson(route('kds.poll', ['store_id' => $r->id]))->assertOk()->json());
        $ticket = $tickets->firstWhere('id', $order->id);
        $this->assertNotNull($ticket, 'the paid phone order appears on the rail');
        $this->assertTrue($ticket['phone_paid']);
    }

    // ---- (c) withheld caller ID: no order at all, staff handoff ---------------------

    public function test_withheld_caller_gets_no_order_and_hands_off(): void
    {
        [$r, $item, $line] = $this->line();
        $this->fakeAi($this->confirmed($item, 'placed'));
        $this->orderCall($line, 'CA-PL3', 'anonymous');

        $this->assertSame(0, Order::where('store_id', $r->id)->count());
        $this->assertCount(0, $this->sms->sent);

        $out = $this->lastToolResult();
        $this->assertSame('no_caller_id', $out['handoff']);

        $conversation = AgentConversation::where('store_id', $r->id)->where('channel', 'voice')->first();
        $this->assertNotNull($conversation);
        $this->assertSame('no_caller_id', ((array) $conversation->meta)['handoff_reason'] ?? null);
    }

    // ---- (d) no store gateway: no order at all, handoff -----------------------------

    public function test_store_that_cannot_take_payment_gets_no_order_and_hands_off(): void
    {
        [$r, $item, $line] = $this->line();
        $this->hosted->canTake = false;
        $this->fakeAi($this->confirmed($item, 'placed'));
        $this->orderCall($line, 'CA-PL4', '+15553330000');

        $this->assertSame(0, Order::where('store_id', $r->id)->count());
        $this->assertCount(0, $this->sms->sent);

        $out = $this->lastToolResult();
        $this->assertSame('no_pay_link', $out['handoff']);
    }

    // ---- (e) SMS failure: the order stays unpaid, the link is not sent -------------

    public function test_failed_pay_link_sms_leaves_the_order_unpaid(): void
    {
        [$r, $item, $line] = $this->line();
        $this->sms->result = false;
        $this->fakeAi($this->confirmed($item, 'placed'));
        $this->orderCall($line, 'CA-PL5', '+15554440000');

        $order = Order::where('store_id', $r->id)->firstOrFail();
        $this->assertSame('unpaid', $order->status);
        $this->assertSame(0, (int) $order->is_paid);
        $this->assertCount(1, $this->sms->sent, 'the send was attempted');

        $out = $this->lastToolResult();
        $this->assertFalse($out['pay_link_sent']);
    }
}
