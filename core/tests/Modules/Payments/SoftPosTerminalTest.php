<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffMember;
use App\Models\StoreOption;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\Money;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Juwri\Modules\Payments\Contracts\PaymentTerminal;
use Juwri\Modules\Payments\Contracts\TerminalPayments;
use Juwri\Modules\Payments\Models\TerminalAccount;
use Juwri\Modules\Payments\Models\TerminalPayment;
use Juwri\Modules\Payments\Services\Terminal\SoftPos\EdfaPaySoftPos;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P5-21: SoftPOS card payments through each store's own Geidea / EdfaPay account (HTTP faked). Covers the flags, the
 * encrypted per-store credentials, idempotent charge (double charge, reused key, retry after a transport failure),
 * partial refunds with the over-refund guard, void, forged and cross-store callbacks, and offline card staying off.
 */
class SoftPosTerminalTest extends TestCase
{
    use RefreshDatabase;

    private const GEIDEA = 'https://geidea.test';

    private const EDFA = 'https://edfa.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        config(['softpos.geidea.base_url' => self::GEIDEA, 'softpos.edfapay.base_url' => self::EDFA]);
    }

    private function on(): void
    {
        config(['softpos.geidea.enabled' => true, 'softpos.edfapay.enabled' => true]);
    }

    /** @return array{store: Store, owner: User, cashier: StaffMember, manager: StaffMember} */
    private function make(): array
    {
        $plan = TestPlans::create(['name' => 'P '.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $store = Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => $title,
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'SAR');
        $mk = fn (string $role) => StaffMember::create(['owner_id' => $owner->id, 'store_id' => $store->id, 'name' => ucfirst($role).' '.$title,
            'email' => Str::random(8).'@t.test', 'password' => bcrypt('x'), 'role' => $role, 'active' => 1]);

        return ['store' => $store, 'owner' => $owner, 'cashier' => $mk('cashier'), 'manager' => $mk('manager')];
    }

    private function fresh(): static
    {
        $this->flushHeaders();
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this;
    }

    private function asOwner(array $t): static
    {
        return $this->fresh()->actingAs($t['owner'], 'web');
    }

    private function asStaff(StaffMember $s): static
    {
        return $this->fresh()->actingAs($s, 'staff');
    }

    private function geideaAccount(array $t, string $password = 'geidea-secret-A'): void
    {
        $this->asOwner($t)->postJson(route('pos.terminal.accounts.save', ['store' => $t['store']->id]), ['provider' => 'geidea_softpos', 'merchant_id' => 'M-'.$t['store']->id,
            'enabled' => true, 'credentials' => ['public_key' => 'pk-'.$t['store']->id, 'api_password' => $password]])->assertOk();
    }

    private function charge(array $t, string $uuid, int $amount): TestResponse
    {
        return $this->asStaff($t['cashier'])->postJson(route('pos.terminal.charge'), ['client_uuid' => $uuid, 'amount_minor' => $amount]);
    }

    /** @return array<string, mixed> */
    private static function geideaOrder(string $id, string $status, string $ref = 'x'): array
    {
        return ['order' => ['orderId' => $id, 'status' => $status, 'amount' => 10.0, 'currency' => 'SAR', 'merchantReferenceId' => $ref,
            'paymentMethod' => ['maskedCardNumber' => '411111******1111', 'brand' => 'VISA'], 'authorizationCode' => 'A1']];
    }

    /** A Geidea callback signed with the given account password. */
    private static function geideaCallback(string $publicKey, string $password, string $orderId, string $status, string $ref): array
    {
        $ts = '2026-10-12T10:00:00Z';
        $msg = $publicKey.'10.00SAR'.$orderId.$status.$ref.$ts;

        return ['order' => ['orderId' => $orderId, 'amount' => 10, 'currency' => 'SAR', 'status' => $status, 'merchantReferenceId' => $ref], 'timeStamp' => $ts,
            'signature' => base64_encode(hash_hmac('sha256', $msg, $password, true))];
    }

    public function test_every_route_is_404_while_the_providers_are_off_and_offline_card_stays_off(): void
    {
        $t = $this->make();
        Http::fake();
        $this->charge($t, (string) Str::uuid(), 1000)->assertNotFound();
        $this->asOwner($t)->getJson(route('pos.terminal.accounts', ['store' => $t['store']->id]))->assertNotFound();
        $this->fresh()->postJson(route('webhook.softpos', ['provider' => 'geidea_softpos', 'store' => $t['store']->id]), [])->assertNotFound();
        $terminal = app(PaymentTerminal::class);
        $this->assertSame('none', $terminal->key());
        $this->assertFalse($terminal->supportsOffline($t['store']));
        Http::assertNothingSent();

        // a provider on that confirms deferred authorisation is still not enough: the OP-0 switch stays off
        $this->on();
        config(['softpos.geidea.offline_supported' => true]);
        $this->geideaAccount($t);
        $this->assertFalse((bool) config('pos.offline.card_enabled'), 'offline card is off by default (OP-0)');
        $this->assertFalse(app(PaymentTerminal::class)->supportsOffline($t['store']));
        config(['pos.offline.card_enabled' => true]);
        $this->assertTrue(app(PaymentTerminal::class)->supportsOffline($t['store']), 'only all three together turn it on');
    }

    public function test_credentials_are_encrypted_per_store_masked_to_the_owner_and_not_for_staff(): void
    {
        $this->on();
        $t = $this->make();
        $this->geideaAccount($t, 'very-secret-password-1234');
        $raw = (string) DB::table('terminal_accounts')->where('store_id', $t['store']->id)->value('credentials');
        $this->assertStringNotContainsString('very-secret-password', $raw);
        $res = $this->asOwner($t)->getJson(route('pos.terminal.accounts', ['store' => $t['store']->id]))->assertOk();
        $this->assertSame('••••••1234', $res->json('accounts.0.credentials.api_password'));
        $this->assertStringNotContainsString('very-secret', $res->getContent());
        // an empty value keeps the stored secret
        $this->asOwner($t)->postJson(route('pos.terminal.accounts.save', ['store' => $t['store']->id]), ['provider' => 'geidea_softpos', 'merchant_id' => 'M2',
            'enabled' => true, 'credentials' => ['public_key' => '', 'api_password' => '']])->assertOk();
        $this->assertSame('very-secret-password-1234', TerminalAccount::query()->where('store_id', $t['store']->id)->first()->credentials['api_password']);
        $this->asStaff($t['manager'])->getJson(route('pos.terminal.accounts'))->assertForbidden();
        $this->asStaff($t['manager'])->postJson(route('pos.terminal.accounts.save'), ['provider' => 'geidea_softpos', 'merchant_id' => 'x', 'enabled' => true, 'credentials' => []])->assertForbidden();
    }

    public function test_a_double_charge_calls_the_provider_once_and_a_reused_key_is_refused(): void
    {
        $this->on();
        $t = $this->make();
        $this->geideaAccount($t);
        Http::fake([self::GEIDEA.'/*' => Http::response(self::geideaOrder('G-1', 'Captured'))]);
        $uuid = (string) Str::uuid();
        $this->charge($t, $uuid, 1000)->assertOk()->assertJsonPath('state', 'captured')->assertJsonPath('reference', 'G-1')->assertJsonPath('masked_pan', '411111******1111');
        $this->charge($t, $uuid, 1000)->assertOk()->assertJsonPath('state', 'captured');
        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => $r['merchantReferenceId'] === $uuid && $r['amount'] === '10.00' && $r['currency'] === 'SAR'
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('pk-'.$t['store']->id.':geidea-secret-A')));
        $this->charge($t, $uuid, 1500)->assertStatus(409)->assertJsonPath('code', 'key_reused');
        $this->assertSame(1, TerminalPayment::query()->count());
    }

    public function test_a_transport_failure_is_retried_with_the_same_merchant_reference(): void
    {
        $this->on();
        $t = $this->make();
        $this->geideaAccount($t);
        Http::fakeSequence(self::GEIDEA.'/*')->push('down', 503)->push(self::geideaOrder('G-2', 'Captured'));
        $uuid = (string) Str::uuid();
        $this->charge($t, $uuid, 1000)->assertStatus(502)->assertJsonPath('code', 'provider_unavailable');
        $this->assertSame('creating', TerminalPayment::query()->value('state'));
        $this->charge($t, $uuid, 1000)->assertOk()->assertJsonPath('state', 'captured');
        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $r) => $r['merchantReferenceId'] === $uuid);
    }

    public function test_partial_refunds_never_exceed_the_payment_and_need_a_manager(): void
    {
        $this->on();
        $t = $this->make();
        $this->geideaAccount($t);
        Http::fake([self::GEIDEA.'/softpos/v1/payments' => Http::response(self::geideaOrder('G-3', 'Captured')),
            self::GEIDEA.'/softpos/v1/payments/G-3/refund' => Http::response(self::geideaOrder('G-3', 'Captured'))]);
        $charge = (string) Str::uuid();
        $this->charge($t, $charge, 1000)->assertOk();
        $refund = fn (string $uuid, int $amount, ?StaffMember $as = null) => $this->asStaff($as ?? $t['manager'])
            ->postJson(route('pos.terminal.refund', ['uuid' => $charge]), ['client_uuid' => $uuid, 'amount_minor' => $amount]);

        $refund((string) Str::uuid(), 100, $t['cashier'])->assertForbidden();
        $r1 = (string) Str::uuid();
        $refund($r1, 400)->assertOk()->assertJsonPath('state', 'refunded')->assertJsonPath('amount_minor', 400);
        $refund($r1, 400)->assertOk(); // resend: no second provider call
        $refund($r1, 300)->assertStatus(409)->assertJsonPath('code', 'key_reused');
        $refund((string) Str::uuid(), 700)->assertStatus(422)->assertJsonPath('code', 'over_refund');
        $refund((string) Str::uuid(), 600)->assertOk();
        $this->assertSame(1000, (int) TerminalPayment::query()->where('client_uuid', $charge)->value('refunded_minor'));
        $this->assertSame('refunded', TerminalPayment::query()->where('client_uuid', $charge)->value('state'));
        $refund((string) Str::uuid(), 1)->assertStatus(422)->assertJsonPath('code', 'over_refund');
        Http::assertSentCount(3); // charge + two refunds
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/refund') && $r['amount'] === '4.00');
    }

    public function test_a_refund_the_provider_refuses_releases_its_amount(): void
    {
        $this->on();
        $t = $this->make();
        $this->geideaAccount($t);
        Http::fake([self::GEIDEA.'/softpos/v1/payments' => Http::response(self::geideaOrder('G-4', 'Captured')),
            self::GEIDEA.'/softpos/v1/payments/G-4/refund' => Http::response(['responseMessage' => 'refused'], 400)]);
        $charge = (string) Str::uuid();
        $this->charge($t, $charge, 1000)->assertOk();
        $this->asStaff($t['manager'])->postJson(route('pos.terminal.refund', ['uuid' => $charge]), ['client_uuid' => (string) Str::uuid(), 'amount_minor' => 1000])
            ->assertOk()->assertJsonPath('state', 'failed');
        $this->assertSame(0, (int) TerminalPayment::query()->where('client_uuid', $charge)->value('refunded_minor'));
        $this->assertSame('captured', TerminalPayment::query()->where('client_uuid', $charge)->value('state'));
    }

    public function test_void_reverses_the_payment_and_it_can_no_longer_be_refunded(): void
    {
        $this->on();
        $t = $this->make();
        $this->geideaAccount($t);
        Http::fake([self::GEIDEA.'/softpos/v1/payments' => Http::response(self::geideaOrder('G-5', 'Captured')),
            self::GEIDEA.'/softpos/v1/payments/G-5/void' => Http::response(self::geideaOrder('G-5', 'Voided'))]);
        $charge = (string) Str::uuid();
        $this->charge($t, $charge, 1000)->assertOk();
        $this->asStaff($t['cashier'])->postJson(route('pos.terminal.void', ['uuid' => $charge]), ['client_uuid' => (string) Str::uuid()])->assertForbidden();
        $v = (string) Str::uuid();
        $this->asStaff($t['manager'])->postJson(route('pos.terminal.void', ['uuid' => $charge]), ['client_uuid' => $v])->assertOk()->assertJsonPath('state', 'voided');
        $this->asStaff($t['manager'])->postJson(route('pos.terminal.void', ['uuid' => $charge]), ['client_uuid' => $v])->assertOk();
        $this->assertSame('voided', TerminalPayment::query()->where('client_uuid', $charge)->value('state'));
        $this->asStaff($t['manager'])->postJson(route('pos.terminal.refund', ['uuid' => $charge]), ['client_uuid' => (string) Str::uuid(), 'amount_minor' => 100])
            ->assertStatus(422)->assertJsonPath('code', 'not_refundable');
        Http::assertSentCount(2);
    }

    public function test_another_store_cannot_see_or_refund_a_payment(): void
    {
        $this->on();
        $a = $this->make();
        $b = $this->make();
        $this->geideaAccount($a);
        $this->geideaAccount($b, 'geidea-secret-B');
        Http::fake([self::GEIDEA.'/*' => Http::response(self::geideaOrder('G-6', 'Captured'))]);
        $charge = (string) Str::uuid();
        $this->charge($a, $charge, 1000)->assertOk();
        $this->asStaff($b['manager'])->getJson(route('pos.terminal.status', ['uuid' => $charge]))->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->asStaff($b['manager'])->postJson(route('pos.terminal.refund', ['uuid' => $charge]), ['client_uuid' => (string) Str::uuid(), 'amount_minor' => 100])->assertNotFound();
        // the same client UUID on store B is B's own payment, made with B's own credentials
        $this->charge($b, $charge, 1000)->assertOk();
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('pk-'.$b['store']->id.':geidea-secret-B')));
    }

    public function test_geidea_callbacks_are_verified_with_the_stores_own_secret_processed_once_and_re_read(): void
    {
        $this->on();
        $a = $this->make();
        $b = $this->make();
        $this->geideaAccount($a);
        $this->geideaAccount($b, 'geidea-secret-B');
        Http::fakeSequence(self::GEIDEA.'/*')->push(self::geideaOrder('G-7', 'Initiated'))->push(self::geideaOrder('G-7', 'Captured'));
        $charge = (string) Str::uuid();
        $this->charge($a, $charge, 1000)->assertOk()->assertJsonPath('state', 'pending');
        $url = fn (array $t) => route('webhook.softpos', ['provider' => 'geidea_softpos', 'store' => $t['store']->id]);
        $pkA = 'pk-'.$a['store']->id;

        // forged: signed with a guessed secret; the body even claims Captured
        $this->fresh()->postJson($url($a), self::geideaCallback($pkA, 'guess', 'G-7', 'Captured', $charge))->assertStatus(401);
        // cross-store: store A's genuine callback replayed at store B's URL fails B's secret
        $genuine = self::geideaCallback($pkA, 'geidea-secret-A', 'G-7', 'Captured', $charge);
        $this->fresh()->postJson($url($b), $genuine)->assertStatus(401);
        Http::assertSentCount(1);
        $this->assertSame('pending', TerminalPayment::query()->where('client_uuid', $charge)->value('state'));

        $this->fresh()->postJson($url($a), $genuine)->assertOk();
        Http::assertSentCount(2); // the state is re-read from Geidea, not taken from the callback
        $this->assertSame('captured', TerminalPayment::query()->where('client_uuid', $charge)->value('state'));
        $this->fresh()->postJson($url($a), $genuine)->assertOk();
        Http::assertSentCount(2); // replay: processed once
    }

    public function test_edfapay_charges_and_its_callbacks_are_signed_over_the_raw_body(): void
    {
        config(['softpos.edfapay.enabled' => true]);
        $t = $this->make();
        $this->asOwner($t)->postJson(route('pos.terminal.accounts.save', ['store' => $t['store']->id]), ['provider' => 'edfapay_softpos', 'merchant_id' => 'E1',
            'enabled' => true, 'credentials' => ['api_key' => 'edfa-key', 'callback_secret' => 'edfa-cb-secret']])->assertOk();
        Http::fakeSequence(self::EDFA.'/*')->push(['trans_id' => 'E-1', 'status' => 'PENDING'])->push(['trans_id' => 'E-1', 'status' => 'SUCCESS', 'card' => '5123****0008', 'card_brand' => 'MASTERCARD']);
        $charge = (string) Str::uuid();
        $this->charge($t, $charge, 2550)->assertOk()->assertJsonPath('state', 'pending')->assertJsonPath('provider', 'edfapay_softpos');
        Http::assertSent(fn (HttpRequest $r) => $r['order_id'] === $charge && $r['order_amount'] === '25.50' && $r->hasHeader('Authorization', 'Bearer edfa-key'));

        $body = json_encode(['trans_id' => 'E-1', 'status' => 'SUCCESS', 'order_id' => $charge]);
        $url = route('webhook.softpos', ['provider' => 'edfapay_softpos', 'store' => $t['store']->id]);
        $send = fn (string $sig) => $this->fresh()->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_'.strtoupper(str_replace('-', '_', EdfaPaySoftPos::SIGNATURE_HEADER)) => $sig], $body);
        $send(hash_hmac('sha256', $body, 'wrong'))->assertStatus(401);
        $send(hash_hmac('sha256', $body.' ', 'edfa-cb-secret'))->assertStatus(401);
        $send(hash_hmac('sha256', $body, 'edfa-cb-secret'))->assertOk();
        $this->assertSame(['captured', '5123****0008'], [TerminalPayment::query()->value('state'), TerminalPayment::query()->value('masked_pan')]);
    }

    /* ---------------- P5-26: the captured card payment settles its POS order ---------------- */

    /** @return array{0: Order, 1: int} an unpaid POS order and its amount due in minor units */
    private function order(array $t, float $price = 10.0): array
    {
        $menu = Menu::create(['store_id' => $t['store']->id, 'name' => 'Kabsa', 'price' => $price, 'active' => '1']);
        $order = Order::create(['store_id' => $t['store']->id, 'type' => 'takeaway', 'status' => 'pending', 'source' => 'pos']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1, 'variation' => 0]);
        $order = $order->fresh();

        return [$order, Money::minorFromMajor((string) $order->amount_due, 'SAR')];
    }

    private function orderCharge(array $t, string $uuid, int $amount, int $orderId, ?int $tip = null): TestResponse
    {
        return $this->asStaff($t['cashier'])->postJson(route('pos.terminal.charge'), array_filter(['client_uuid' => $uuid, 'amount_minor' => $amount, 'order_id' => $orderId, 'tip_minor' => $tip], fn ($v) => $v !== null));
    }

    public function test_a_captured_card_payment_marks_its_order_paid_once(): void
    {
        $this->on();
        $t = $this->make();
        $this->geideaAccount($t);
        [$order, $due] = $this->order($t);
        Http::fake([self::GEIDEA.'/*' => Http::response(self::geideaOrder('G-10', 'Captured'))]);
        $uuid = (string) Str::uuid();

        $this->orderCharge($t, $uuid, $due + 1, $order->id)->assertStatus(422)->assertJsonPath('code', 'amount_mismatch')->assertJsonPath('due_minor', $due);
        Http::assertNothingSent();
        $this->orderCharge($t, $uuid, $due, $order->id)->assertOk()->assertJsonPath('state', 'captured')->assertJsonPath('settled', true)->assertJsonPath('order_id', $order->id);
        $order->refresh();
        $this->assertTrue((bool) $order->is_paid);
        $this->assertSame('card', $order->payment_method);
        $settledAt = (string) TerminalPayment::query()->where('client_uuid', $uuid)->value('settled_at');

        $this->orderCharge($t, $uuid, $due, $order->id)->assertOk()->assertJsonPath('settled', true); // resend
        $this->orderCharge($t, $uuid, $due, $order->id + 1)->assertStatus(404); // another order id is not this store's
        $this->assertSame($settledAt, (string) TerminalPayment::query()->where('client_uuid', $uuid)->value('settled_at'));
        $p = TerminalPayment::query()->where('client_uuid', $uuid)->firstOrFail();
        $this->assertFalse(app(TerminalPayments::class)->claimSettlement($t['store'], (int) $p->id), 'claimed once');
        Http::assertSentCount(1);
    }

    public function test_a_pending_payment_settles_its_order_when_the_callback_confirms_it_with_the_tip(): void
    {
        $this->on();
        $t = $this->make();
        $this->geideaAccount($t);
        [$order, $due] = $this->order($t);
        Http::fakeSequence(self::GEIDEA.'/*')->push(self::geideaOrder('G-11', 'Initiated'))->push(self::geideaOrder('G-11', 'Captured'));
        $uuid = (string) Str::uuid();
        $this->orderCharge($t, $uuid, $due + 150, $order->id, 150)->assertOk()->assertJsonPath('state', 'pending')->assertJsonPath('settled', false);
        $this->assertFalse((bool) $order->fresh()->is_paid);

        $cb = self::geideaCallback('pk-'.$t['store']->id, 'geidea-secret-A', 'G-11', 'Captured', $uuid);
        $url = route('webhook.softpos', ['provider' => 'geidea_softpos', 'store' => $t['store']->id]);
        $this->fresh()->postJson($url, $cb)->assertOk();
        $this->fresh()->postJson($url, $cb)->assertOk(); // replay
        $order->refresh();
        $this->assertTrue((bool) $order->is_paid);
        $this->assertSame(150, Money::minorFromMajor((string) $order->tip_amount, 'SAR'));
        $this->assertSame($due + 150, Money::minorFromMajor((string) $order->amount_due, 'SAR'));
    }

    public function test_an_order_paid_another_way_is_not_paid_twice(): void
    {
        $this->on();
        $t = $this->make();
        $this->geideaAccount($t);
        [$order, $due] = $this->order($t);
        Http::fakeSequence(self::GEIDEA.'/*')->push(self::geideaOrder('G-12', 'Initiated'))->push(self::geideaOrder('G-12', 'Captured'));
        $uuid = (string) Str::uuid();
        $this->orderCharge($t, $uuid, $due, $order->id)->assertOk();
        PosService::settle($order->fresh(), 'cash', 100.0);
        $this->asStaff($t['cashier'])->getJson(route('pos.terminal.status', ['uuid' => $uuid]))->assertOk()->assertJsonPath('state', 'captured')->assertJsonPath('settled', true);
        $this->assertSame('cash', $order->fresh()->payment_method, 'the cash settlement stands; the double card payment is logged for a refund');
    }

    public function test_card_refunds_and_voids_reverse_the_order_through_the_pos_refund_path_once(): void
    {
        $this->on();
        $t = $this->make();
        $this->geideaAccount($t);
        [$order, $due] = $this->order($t, 20.0);
        Http::fake([self::GEIDEA.'/softpos/v1/payments' => Http::response(self::geideaOrder('G-13', 'Captured')),
            self::GEIDEA.'/softpos/v1/payments/G-13/refund' => Http::response(self::geideaOrder('G-13', 'Captured'))]);
        $charge = (string) Str::uuid();
        $this->orderCharge($t, $charge, $due, $order->id)->assertOk()->assertJsonPath('settled', true);

        $r1 = (string) Str::uuid();
        $refund = fn () => $this->asStaff($t['manager'])->postJson(route('pos.terminal.refund', ['uuid' => $charge]), ['client_uuid' => $r1, 'amount_minor' => 400]);
        $refund()->assertOk()->assertJsonPath('state', 'refunded');
        $refund()->assertOk(); // resend
        $adj = OrderAdjustment::query()->where('order_id', $order->id)->where('type', 'refund')->get();
        $this->assertCount(1, $adj);
        $this->assertSame(['card', 'softpos:'.$r1, 400], [$adj[0]->method, $adj[0]->idempotency_key, Money::minorFromMajor((string) $adj[0]->amount, 'SAR')]);

    }

    public function test_a_card_void_books_the_whole_payment_back_on_the_order_once(): void
    {
        $this->on();
        $t = $this->make();
        $this->geideaAccount($t);
        [$order2, $due2] = $this->order($t, 7.5);
        Http::fake([self::GEIDEA.'/softpos/v1/payments' => Http::response(self::geideaOrder('G-14', 'Captured')),
            self::GEIDEA.'/softpos/v1/payments/G-14/void' => Http::response(self::geideaOrder('G-14', 'Voided'))]);
        $c2 = (string) Str::uuid();
        $this->orderCharge($t, $c2, $due2, $order2->id)->assertOk();
        $v = (string) Str::uuid();
        $this->asStaff($t['manager'])->postJson(route('pos.terminal.void', ['uuid' => $c2]), ['client_uuid' => $v])->assertOk()->assertJsonPath('state', 'voided');
        $this->asStaff($t['manager'])->postJson(route('pos.terminal.void', ['uuid' => $c2]), ['client_uuid' => $v])->assertOk();
        $adj2 = OrderAdjustment::query()->where('order_id', $order2->id)->where('type', 'refund')->get();
        $this->assertCount(1, $adj2);
        $this->assertSame($due2, Money::minorFromMajor((string) $adj2[0]->amount, 'SAR'));
    }

    /** P5-28: a cash refund used up the refundable amount, then the provider refunds the card — never lost. */
    private function cashThenCard(array $t, string $ref): array
    {
        [$order, $due] = $this->order($t, 20.0);
        Http::fake([self::GEIDEA.'/softpos/v1/payments' => Http::response(self::geideaOrder($ref, 'Captured')),
            self::GEIDEA.'/softpos/v1/payments/'.$ref.'/refund' => Http::response(self::geideaOrder($ref, 'Captured'))]);
        $charge = (string) Str::uuid();
        $this->orderCharge($t, $charge, $due, $order->id)->assertOk()->assertJsonPath('settled', true);
        PosService::refund($order->fresh(), $t['manager'], Money::majorFromMinor($due, 'SAR'), 'cash', 'cash back');

        return [$order, $charge];
    }

    public function test_a_card_refund_the_pos_refuses_after_a_cash_refund_becomes_one_manager_exception(): void
    {
        $this->on();
        $t = $this->make();
        $this->geideaAccount($t);
        [$order, $charge] = $this->cashThenCard($t, 'G-20');

        $r1 = (string) Str::uuid();
        $refund = fn () => $this->asStaff($t['manager'])->postJson(route('pos.terminal.refund', ['uuid' => $charge]), ['client_uuid' => $r1, 'amount_minor' => 400]);
        $first = $refund()->assertOk()->assertJsonPath('state', 'refunded')->assertJsonPath('booked', false)->assertJsonPath('code', 'reversal_not_booked')->json();
        $this->assertNotEmpty($first['message']);
        $refund()->assertOk()->assertJsonPath('exception_id', $first['exception_id']); // resend: same exception
        Http::assertSentCount(2); // charge + one provider refund

        $this->assertSame(1, OrderAdjustment::query()->where('order_id', $order->id)->where('type', 'refund')->count(), 'only the cash refund is booked');
        $rows = DB::table('pos_card_reversal_exceptions')->where('store_id', $t['store']->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame([(int) $order->id, 400, 'SAR', 'refund', 'G-20', $r1, $charge, 'open'],
            [(int) $rows[0]->order_id, (int) $rows[0]->amount_minor, $rows[0]->currency_code, $rows[0]->kind, $rows[0]->provider_reference, $rows[0]->client_uuid, $rows[0]->charge_uuid, $rows[0]->state]);
        $this->assertNotSame('', (string) $rows[0]->reason);

        $list = $this->asStaff($t['manager'])->getJson(route('pos.offline.exceptions'))->assertOk()->json('reversals');
        $this->assertSame([$first['exception_id']], array_column($list, 'id'));
        $this->assertCount(1, $this->asStaff($t['manager'])->getJson(route('pos.offline.exceptions', ['kind' => 'card_reversal_not_booked']))->assertOk()->json('reversals'));
        $this->assertSame([], $this->asStaff($t['manager'])->getJson(route('pos.offline.exceptions', ['kind' => 'oversell']))->assertOk()->json('reversals'));
    }

    public function test_a_card_void_the_pos_refuses_is_recorded_too(): void
    {
        $this->on();
        $t = $this->make();
        $this->geideaAccount($t);
        [$order, $due] = $this->order($t, 9.0);
        Http::fake([self::GEIDEA.'/softpos/v1/payments' => Http::response(self::geideaOrder('G-21', 'Captured')),
            self::GEIDEA.'/softpos/v1/payments/G-21/void' => Http::response(self::geideaOrder('G-21', 'Voided'))]);
        $charge = (string) Str::uuid();
        $this->orderCharge($t, $charge, $due, $order->id)->assertOk();
        PosService::refund($order->fresh(), $t['manager'], 1.0, 'cash', 'part cash back');
        $this->asStaff($t['manager'])->postJson(route('pos.terminal.void', ['uuid' => $charge]), ['client_uuid' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('state', 'voided')->assertJsonPath('code', 'reversal_not_booked');
        $this->assertSame(['void', $due], [DB::table('pos_card_reversal_exceptions')->value('kind'), (int) DB::table('pos_card_reversal_exceptions')->value('amount_minor')]);
    }

    public function test_card_reversal_exceptions_are_store_scoped_and_resolved_once_by_a_manager(): void
    {
        $this->on();
        $a = $this->make();
        $b = $this->make();
        $this->geideaAccount($a);
        [, $charge] = $this->cashThenCard($a, 'G-22');
        $id = $this->asStaff($a['manager'])->postJson(route('pos.terminal.refund', ['uuid' => $charge]), ['client_uuid' => (string) Str::uuid(), 'amount_minor' => 300])
            ->assertOk()->json('exception_id');

        $this->assertSame([], $this->asStaff($b['manager'])->getJson(route('pos.offline.exceptions'))->assertOk()->json('reversals'));
        $this->asStaff($b['manager'])->postJson(route('pos.card_reversals.resolve', ['id' => $id]))->assertNotFound();
        $this->asStaff($a['cashier'])->postJson(route('pos.card_reversals.resolve', ['id' => $id]))->assertForbidden();

        $done = $this->asStaff($a['manager'])->postJson(route('pos.card_reversals.resolve', ['id' => $id]), ['note' => 'refunded by hand in the books'])
            ->assertOk()->assertJsonPath('state', 'resolved')->json();
        $again = $this->asStaff($a['manager'])->postJson(route('pos.card_reversals.resolve', ['id' => $id]), ['note' => 'other'])->assertOk()->json();
        $this->assertSame($done['resolved_at'], $again['resolved_at']);
        $row = DB::table('pos_card_reversal_exceptions')->find($id);
        $this->assertSame(['resolved', 'refunded by hand in the books', (int) $a['manager']->id], [$row->state, $row->resolution_note, (int) $row->resolved_by_staff_id]);
        $this->asStaff($a['manager'])->getJson(route('pos.offline.exceptions'))->assertOk()->assertJsonPath('reversals.0.state', 'resolved');
    }
}
