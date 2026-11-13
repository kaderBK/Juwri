<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Models\AgentConversation;
use Juwri\Modules\Agents\Models\AgentMessage;
use Juwri\Modules\Agents\Models\CallLog;
use Juwri\Modules\Agents\Models\PhoneLine;
use Juwri\Modules\Agents\Services\Telephony\Bridge\TwilioRelayCodec;
use Juwri\Modules\Agents\Services\Telephony\VoiceConsent;
use Juwri\Modules\Agents\Services\Telephony\VoiceGuard;
use Juwri\Modules\Agents\Services\Telephony\VoiceSession;
use Juwri\Modules\Ai\Services\AiService;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Messaging\Contracts\MessageQueue;
use Juwri\Modules\Messaging\Contracts\Sms;
use Juwri\Modules\Messaging\Models\MessageCost;
use Juwri\Modules\Ordering\Contracts\HostedPayments;
use Juwri\Modules\Ordering\Http\Controllers\User\PaymentController;
use Juwri\Modules\Ordering\Services\Payments\HostedOrderPayments;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\GatewayTestHelpers;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-09 V-21 end-to-end guard journeys for the AI phone agent. Each test drives a
 * real call from the provider edge (signed Twilio webhook + ConversationRelay setup/
 * prompt frames, or VoiceSession::turn) through the shared agent tool loop, the pay
 * link, and the money funnel, and pins the safety rails around it:
 *
 *  1. a withheld caller ID places nothing and reaches staff (no order, no SMS);
 *  2. a blocked caller is refused on the first turn and other callers keep working;
 *  3. a line with ordering off hands the call to staff (no order, no SMS);
 *  4. an Arabic line speaks, texts and relays in Arabic (ar-SA ConversationRelay);
 *  5. the pay link is a signed, expiring URL — tampered/expired links are refused and
 *     one store's settlement never touches another store's order.
 *
 * Only the AI, the Sms transport and the telephony edge are faked; the hosted payment
 * open() and the stored routes are real. No test reaches the network.
 */
class PhoneAgentE2eGuardsTest extends TestCase
{
    use GatewayTestHelpers;
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
        // Owner KDS routes and the signed-link checks re-enable middleware per test.
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

    /**
     * A paying store with a menu item and a phone line; a Twilio line also gets its BYOK token.
     *
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $item
     * @return array{0: Store, 1: User, 2: Menu, 3: PhoneLine}
     */
    private function makeStore(array $line = [], array $item = []): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'ai_chatbot' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => 1]);
        $menu = Menu::create(array_merge([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Pizza', 'price' => 12, 'active' => 1,
        ], $item));
        $provider = (string) ($line['provider'] ?? 'fake');
        $phoneLine = PhoneLine::create(array_merge([
            'store_id' => $store->id, 'provider' => $provider, 'number' => '+1555'.rand(1000, 9999),
            'active' => true, 'orders_enabled' => true,
        ], $line));
        if ($provider === 'twilio') {
            Credential::create([
                'store_id' => $store->id, 'feature' => 'twilio', 'provider' => 'twilio',
                'secrets' => ['auth_token' => 'twilio-token'], 'active' => true,
            ]);
        }

        return [$store, $owner, $menu, $phoneLine];
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

    /** Drive one ConversationRelay setup + prompt frame through the codec, returning the agent token. */
    private function converse(PhoneLine $line, string $sid, string $from, string $utterance): string
    {
        $codec = new TwilioRelayCodec($line);
        $codec->decode((string) json_encode([
            'type' => 'setup', 'sessionId' => 'VX1', 'callSid' => $sid, 'from' => $from, 'to' => $line->number,
            'callType' => 'PSTN', 'direction' => 'inbound', 'callStatus' => 'RINGING',
        ]));
        $frame = $codec->decode((string) json_encode(['type' => 'prompt', 'voicePrompt' => $utterance, 'lang' => 'en-US', 'last' => true]));
        $this->assertNotNull($frame);

        return (string) (json_decode($frame, true)['token'] ?? '');
    }

    private function lastToolResult(): array
    {
        return (array) json_decode((string) AgentMessage::where('role', 'tool')->latest('id')->value('content'), true);
    }

    /** Twilio signs base64(HMAC-SHA1(fullUrl + sorted params, token)). */
    private function twilioSig(string $url, array $params, string $token): string
    {
        ksort($params);
        $data = $url;
        foreach ($params as $k => $v) {
            $data .= $k.$v;
        }

        return base64_encode(hash_hmac('sha1', $data, $token, true));
    }

    // ---- (1) withheld caller ID: nothing ordered, staff handoff -----------------------

    public function test_withheld_caller_id_places_no_order_and_hands_off_to_staff(): void
    {
        [$store, , $item, $line] = $this->makeStore();
        $handoff = trans('Let me put you through to a team member.', [], 'en');

        foreach ([['CA-W1', 'anonymous'], ['CA-W1B', '']] as [$sid, $from]) {
            $this->fakeAi([$this->orderStep($item), ['reply' => $handoff]]);
            $reply = $this->converse($line, $sid, $from, 'one pizza');

            $this->assertSame($handoff, $reply, 'the reply tells the caller a team member will help');
            $this->assertStringContainsString('team member', $reply);

            $out = $this->lastToolResult();
            $this->assertSame('no_caller_id', $out['handoff']);
            $this->assertStringContainsString('team member', (string) $out['error']);

            $conversation = AgentConversation::where('store_id', $store->id)->where('channel', 'voice')
                ->where('session_key', substr(hash('sha1', $sid), 0, 40))->firstOrFail();
            $this->assertSame('no_caller_id', ((array) $conversation->meta)['handoff_reason'] ?? null);

            $this->assertSame('handoff', CallLog::where('call_sid', $sid)->firstOrFail()->outcome);
        }

        $this->assertSame(0, Order::where('store_id', $store->id)->count(), 'a withheld caller never places an order');
        $this->assertCount(0, $this->sms->sent, 'a withheld caller has no number to text');
    }

    // BUG: Twilio sends +266696687 ("ANONYMOUS" on a keypad) for withheld caller ID, but VoiceGuard::withheld
    // (core/app/Modules/Agents/Services/Telephony/VoiceGuard.php:30-35) only knows words/empty, so such a caller can order.
    public function test_anonymous_266696687_caller_id_is_also_treated_as_withheld(): void
    {
        // BUG: the nine-key "ANONYMOUS" caller ID (+266696687) is not in VoiceGuard::WITHHELD
        // nor caught by the digit guard, so VoiceGuard::withheld() returns false and this caller
        // can order by phone even though no pay link can reach them. See
        // core/app/Modules/Agents/Services/Telephony/VoiceGuard.php:23 (WITHHELD list) and :30-35 (withheld()).
        [$store, , $item, $line] = $this->makeStore();
        $this->fakeAi($this->confirmed($item, 'placed'));

        $this->orderCall($line, 'CA-W2', '+266696687');

        $this->assertSame(0, Order::where('store_id', $store->id)->count(), 'a withheld caller never places an order');
        $this->assertCount(0, $this->sms->sent, 'a withheld caller has no number to text');

        $out = $this->lastToolResult();
        $this->assertSame('no_caller_id', $out['handoff'] ?? null);
        $this->assertSame('handoff', CallLog::where('call_sid', 'CA-W2')->firstOrFail()->outcome);
    }

    // Telnyx docs do not document withheld values; these are RFC 3323 forms (anonymous@anonymous.invalid) plus keypad pseudo-numbers.
    public function test_telnyx_style_withheld_formats_hand_off(): void
    {
        foreach (['Anonymous', 'sip:anonymous@anonymous.invalid', '+266696687', ' Restricted ', 'PRIVATE'] as $from) {
            $this->assertTrue(VoiceGuard::withheld($from), $from);
        }
        $this->assertFalse(VoiceGuard::withheld('+15551234001'));
    }

    // ---- (2) blocked caller: refused, other caller unaffected ------------------------

    public function test_blocked_caller_is_refused_without_order_or_sms_and_others_still_order(): void
    {
        [$store, , $item, $line] = $this->makeStore(['provider' => 'twilio', 'number' => '+15551234001']);
        $blocked = '+15559990001';
        VoiceGuard::block($store, $blocked);

        // The signed provider webhook still answers with the relay; the block is enforced on the first turn.
        $url = route('voice.incoming', 'twilio');
        $params = ['To' => $line->number, 'From' => $blocked, 'CallSid' => 'CA-BLK-1'];
        $res = $this->call('POST', $url, $params, [], [], ['HTTP_X_TWILIO_SIGNATURE' => $this->twilioSig($url, $params, 'twilio-token')]);
        $res->assertOk();
        $this->assertStringContainsString('ConversationRelay', $res->getContent());

        $this->fakeAi($this->confirmed($item, 'placed'));
        $reply = VoiceSession::turn($line, 'CA-BLK-1', $blocked, 'one pizza');

        $this->assertSame(trans('Sorry, this number cannot place phone orders. Please order online.', [], 'en'), $reply);
        $this->assertSame(0, Order::where('store_id', $store->id)->count());
        $this->assertCount(0, $this->sms->sent);
        $this->assertSame('blocked', CallLog::where('call_sid', 'CA-BLK-1')->firstOrFail()->outcome);
        $this->assertSame(0, MessageCost::where('provider_ref', 'CA-BLK-1')->count(), 'a refused call writes no cost');

        // An unblocked caller on the SAME line still works end to end.
        $this->fakeAi($this->confirmed($item, 'placed'));
        $this->orderCall($line, 'CA-BLK-2', '+15559990002');

        $this->assertSame(1, Order::where('store_id', $store->id)->count());
        $this->assertCount(1, $this->sms->sent);
        $this->assertSame('+15559990002', $this->sms->sent[0]['to']);
    }

    // ---- (3) ordering off: staff handoff, no order, no SMS ---------------------------

    public function test_line_with_ordering_disabled_hands_off_without_order_or_sms(): void
    {
        [$store, , $item, $line] = $this->makeStore(['orders_enabled' => false]);
        $this->mock(MessageQueue::class, fn ($mock) => $mock->shouldReceive('staffPush')->once());
        $this->fakeAi($this->confirmed($item, 'placed'));

        $reply = VoiceSession::turn($line, 'CA-OFF-1', '+15551230002', 'one pizza');

        $this->assertSame(trans('Let me put you through to a team member.', [], 'en'), $reply);
        $this->assertSame(0, Order::where('store_id', $store->id)->count());
        $this->assertCount(0, $this->sms->sent);
        $this->assertSame('handoff', CallLog::where('call_sid', 'CA-OFF-1')->firstOrFail()->outcome);
        $this->assertSame(0, AgentConversation::where('store_id', $store->id)->where('channel', 'voice')->count());
    }

    public function test_ordering_disabled_line_asks_consent_first_and_hands_off_only_afterwards(): void
    {
        [$store, , $item, $line] = $this->makeStore(['orders_enabled' => false, 'recording_enabled' => true, 'recording_region' => 'ksa']);
        $this->mock(MessageQueue::class, fn ($mock) => $mock->shouldReceive('staffPush')->once());
        $this->fakeAi($this->confirmed($item, 'placed'));

        // First turn: the V-10 consent answer comes back, NOT a staff handoff (before consent and before V-11 limits).
        $first = VoiceSession::turn($line, 'CA-OFFC-1', '+15551230004', 'yes');
        $this->assertSame(trans('Thank you. What would you like to order?', [], 'en'), $first);
        $log = CallLog::where('call_sid', 'CA-OFFC-1')->firstOrFail();
        $this->assertSame('yes', $log->consent);
        $this->assertNotSame('handoff', $log->outcome);
        $this->assertNotSame('limit', $log->outcome);

        // Only the next turn, past consent, hands off.
        $second = VoiceSession::turn($line, 'CA-OFFC-1', '+15551230004', 'one pizza');
        $this->assertSame(trans('Let me put you through to a team member.', [], 'en'), $second);
        $this->assertSame('handoff', CallLog::where('call_sid', 'CA-OFFC-1')->firstOrFail()->outcome);
        $this->assertSame(0, Order::where('store_id', $store->id)->count());
        $this->assertCount(0, $this->sms->sent);
    }

    // ---- (4) Arabic line: speaks, texts and relays in Arabic -------------------------

    public function test_arabic_line_orders_in_arabic_texts_an_arabic_signed_link_and_relays_ar_sa(): void
    {
        [$store, , $item, $line] = $this->makeStore(
            ['provider' => 'twilio', 'number' => '+15551230003', 'language' => 'ar', 'recording_enabled' => true, 'recording_region' => 'ksa'],
            ['name' => 'بيتزا'],
        );

        // The signed Twilio webhook hands ConversationRelay the ar-SA STT/TTS + Arabic welcome.
        $expectedGreeting = trans('This call is handled by an AI assistant and may be recorded. Say yes if you agree to recording.', [], 'ar')
            .' '.trans('Hi! How can I help with your order today?', [], 'ar');
        $this->assertSame($expectedGreeting, VoiceConsent::greeting($line));

        $url = route('voice.incoming', 'twilio');
        $params = ['To' => $line->number, 'From' => '+15551239999', 'CallSid' => 'CA-AR-IN'];
        $res = $this->call('POST', $url, $params, [], [], ['HTTP_X_TWILIO_SIGNATURE' => $this->twilioSig($url, $params, 'twilio-token')]);
        $res->assertOk();
        $body = $res->getContent();
        $this->assertStringContainsString('language="ar-SA"', $body);
        $this->assertStringContainsString('transcriptionLanguage="ar-SA"', $body);
        $this->assertStringContainsString('ttsLanguage="ar-SA"', $body);
        $this->assertStringContainsString(htmlspecialchars($expectedGreeting, ENT_QUOTES), $body);

        // The app-generated consent reply is Arabic (the recording question was armed for this line).
        $consent = VoiceSession::turn($line, 'CA-AR-1', '+15551239999', 'نعم');
        $this->assertSame(trans('Thank you. What would you like to order?', [], 'ar'), $consent);
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $consent);

        // Read-back (gated) then an Arabic "yes" places the order and texts the Arabic pay link.
        $placed = 'تم تسجيل طلبك، أرسلنا رابط الدفع عبر رسالة نصية.';
        $this->fakeAi($this->confirmed($item, $placed));
        $this->assertSame('Shall I place it?', VoiceSession::turn($line, 'CA-AR-1', '+15551239999', 'بيتزا واحدة'));
        $reply = VoiceSession::turn($line, 'CA-AR-1', '+15551239999', 'نعم من فضلك');
        $this->assertSame($placed, $reply);
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $reply);

        $orders = Order::where('store_id', $store->id)->get();
        $this->assertCount(1, $orders);
        $order = $orders->first();
        $this->assertSame('unpaid', $order->status);
        $this->assertSame('hosted', $order->payment_mode);
        // The voice flow stores the item's raw (Arabic) menu name — Menu has no voice-locale
        // translation mechanism (TranslatesForStorefront is cookie/storefront-only).
        $this->assertSame('بيتزا', $order->items->first()->displayName());

        $tx = Transaction::where('transaction_method', 'order')->where('product_id', $order->id)->firstOrFail();
        $this->assertCount(1, $this->sms->sent);
        $sms = $this->sms->sent[0];
        $this->assertSame('+15551239999', $sms['to']);
        $this->assertSame((int) $store->id, $sms['store']);
        $this->assertStringStartsWith('ادفع طلبك', $sms['message']);
        $this->assertStringContainsString('/payment/phone/'.$tx->id, $sms['message']);
        $this->assertStringContainsString('signature=', $sms['message']);
        $this->assertStringContainsString('expires=', $sms['message']);
    }

    // ---- (5) signed/expiring pay link + cross-store settlement isolation -------------

    public function test_pay_link_is_signed_tamper_and_expiry_refused_and_settlement_is_store_isolated(): void
    {
        // (a) the money funnel settles only the transaction's OWN store.
        [, $orderA, $txA] = $this->makeOrderTransaction('stripe', 12.0);
        [, $orderB, $txB] = $this->makeOrderTransaction('stripe', 9.0);
        ob_start();
        PaymentController::paySuccess($txA);
        ob_get_clean();

        $this->assertSame(1, (int) $orderA->fresh()->is_paid);
        $this->assertSame(0, (int) $orderB->fresh()->is_paid, 'store B never settles from store A');
        $this->assertNull(Transaction::find($txB->id)->status, 'store B transaction stays pending');
        $this->assertSame(0.0, (float) store_options($orderB->store_id, 'wallet_amount'));

        // (b) a phone order gets one temporary signed URL (never the bare id route).
        [$store, , $item, $line] = $this->makeStore();
        $this->fakeAi($this->confirmed($item, 'placed'));
        $this->orderCall($line, 'CA-LINK-1', '+15551237777');

        $order = Order::where('store_id', $store->id)->firstOrFail();
        $tx = Transaction::where('transaction_method', 'order')->where('product_id', $order->id)->firstOrFail();
        $this->assertSame(1, preg_match('~https?://\S+~', $this->sms->sent[0]['message'], $m));
        $signed = $m[0];
        $this->assertStringContainsString('/payment/phone/'.$tx->id, $signed);
        $this->assertStringContainsString('signature=', $signed);
        $this->assertStringContainsString('expires=', $signed);

        // Real route middleware (bindings + `signed`); setUp() strips every middleware.
        $this->withMiddleware()->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->get($signed)->assertOk();
        $this->get(preg_replace('~signature=[^&]+~', 'signature=0', $signed))->assertForbidden();
        $this->get(route('payment.phone', $tx->id))->assertForbidden();

        $this->travel(31)->minutes();
        $this->get($signed)->assertForbidden();
        $this->assertSame(0, (int) $order->fresh()->is_paid, 'an expired link never pays the order');
    }
}
