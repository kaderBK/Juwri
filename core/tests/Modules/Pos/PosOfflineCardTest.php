<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Modules\Payments\Contracts\PaymentTerminal;
use Juwri\Modules\Payments\Services\Terminal\FakeTerminal;
use Juwri\Modules\Pos\Models\PosOfflineCardPayment;
use Juwri\Modules\Pos\Services\Offline\OfflineCardSettlement;
use Tests\TestCase;

/**
 * Offline POS OP-7 (offline card): a card the provider SDK took offline is `pending_offline` — never paid — until the
 * provider confirms; captured → settled as a card sale; declined → `declined_after_offline`, a manager exception the
 * manager resolves by collecting another way. Off unless the platform switch is on AND the provider can do it (owner
 * Q1); caps SAR 250 per payment / 2,500 unforwarded per till (owner Q3), a store may only lower them. No card data kept.
 */
class PosOfflineCardTest extends TestCase
{
    use OfflineTillFixture;
    use RefreshDatabase;

    private FakeTerminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        config(['pos.offline.card_enabled' => true]);
        $this->terminal = new FakeTerminal;
        $this->app->instance(PaymentTerminal::class, $this->terminal);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} sale + its offline card tender */
    private function cardSale(array $t, int $unit, string $ref, int $qty = 1, string $rate = '15'): array
    {
        $sale = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => $unit, 'qty' => $qty]], [], null, null, $rate);

        return [$sale, $this->rec($t, 'tender', ['sale_key' => $sale['key'], 'method' => 'card_offline', 'amount_minor' => $sale['payload']['totals']['total_minor'], 'sdk_reference' => $ref])];
    }

    public function test_pending_until_the_provider_answers_then_captured_and_paid_once(): void
    {
        $t = $this->make();
        [$sale, $tender] = $this->cardSale($t, 1050, 'REF-1');
        $res = $this->replay($t, [$sale, $tender])->assertOk();
        $this->assertSame(['accepted', 'pending_offline'], [$res->json('results.1.status'), $res->json('results.1.result.state')]);
        $order = Order::query()->where('client_uuid', $sale['key'])->first();
        $this->assertFalse((bool) $order->is_paid, 'a pending offline card is never counted as paid');
        $this->assertSame('card_offline', $order->payment_method);

        $settlement = app(OfflineCardSettlement::class);
        $this->assertSame('pending', $settlement->refresh($t['store'], $res->json('results.1.result.card_payment_id')));
        $this->terminal->script('REF-1', ['state' => 'captured', 'masked_pan' => '****1234', 'scheme' => 'mada']);
        $this->artisan('pos:offline-cards-sync')->expectsOutputToContain('captured 1')->assertSuccessful();
        $this->artisan('pos:offline-cards-sync')->expectsOutputToContain('captured 0')->assertSuccessful();

        $order->refresh();
        $this->assertTrue((bool) $order->is_paid);
        $this->assertSame('card', $order->payment_method);
        $card = PosOfflineCardPayment::query()->first();
        $this->assertSame(['captured', '****1234', 'mada'], [$card->state, $card->masked_pan, $card->scheme]);
        $this->assertStringNotContainsString('pan', strtolower(json_encode($tender['payload']) ?: ''), 'the till sends no card data');
    }

    public function test_declined_after_offline_is_a_manager_exception_resolved_another_way(): void
    {
        $t = $this->make();
        [$sale, $tender] = $this->cardSale($t, 1050, 'REF-2');
        $id = $this->replay($t, [$sale, $tender])->json('results.1.result.card_payment_id');
        $this->terminal->script('REF-2', 'declined');
        $this->assertSame('declined', app(OfflineCardSettlement::class)->refresh($t['store'], $id));
        $order = Order::query()->where('client_uuid', $sale['key'])->first();
        $this->assertFalse((bool) $order->fresh()->is_paid);
        $this->assertNotSame('cancelled', $order->fresh()->status, 'the sale stays recorded');

        $cards = $this->asStaff($t['manager'])->getJson(route('pos.offline.exceptions'))->assertOk()->json('cards');
        $this->assertSame([[$id, 'declined_after_offline']], array_map(fn ($c) => [$c['id'], $c['state']], $cards));

        $this->asStaff($t['cashier'])->postJson(route('pos.offline.cards.resolve', $id), ['method' => 'cash'])->assertForbidden();
        $this->asStaff($t['manager'])->postJson(route('pos.offline.cards.resolve', $id), ['method' => 'bitcoin'])->assertStatus(422);
        $this->asStaff($t['manager'])->postJson(route('pos.offline.cards.resolve', $id), ['method' => 'cash'])->assertOk()->assertJson(['state' => 'resolved', 'resolution_method' => 'cash']);
        $this->asStaff($t['manager'])->postJson(route('pos.offline.cards.resolve', $id), ['method' => 'cash'])->assertOk(); // repeated click
        $order->refresh();
        $this->assertTrue((bool) $order->is_paid);
        $this->assertSame('cash', $order->payment_method);
        $card = PosOfflineCardPayment::query()->find($id);
        $this->assertNotNull($card->decided_at, 'the decline is kept');
        $this->assertSame((int) $t['manager']->id, (int) $card->resolved_by_staff_id);

        // a captured or pending one cannot be "resolved"
        [$s2, $t2] = $this->cardSale($t, 1050, 'REF-3');
        $id2 = $this->replay($t, [$s2, $t2])->json('results.1.result.card_payment_id');
        $this->asStaff($t['manager'])->postJson(route('pos.offline.cards.resolve', $id2), ['method' => 'cash'])->assertStatus(422);
    }

    public function test_off_unless_enabled_and_the_provider_can_and_the_snapshot_says_so(): void
    {
        $t = $this->make();
        [$sale, $tender] = $this->cardSale($t, 1050, 'REF-4');
        config(['pos.offline.card_enabled' => false]);
        $this->replay($t, [$sale, $tender])->assertOk()->assertJsonPath('results.1.reason', 'card_offline_unavailable');
        $this->assertFalse($this->asStaff($t['cashier'])->getJson(route('pos.offline.snapshot', ['branch' => $t['branch']->id]))->json('parts.caps.card_offline_enabled'));

        config(['pos.offline.card_enabled' => true]);
        $this->app->instance(PaymentTerminal::class, new FakeTerminal(false));
        [$s2, $t2] = $this->cardSale($t, 1050, 'REF-5');
        $this->replay($t, [$s2, $t2])->assertOk()->assertJsonPath('results.1.reason', 'card_offline_unavailable');
        $this->app->instance(PaymentTerminal::class, $this->terminal);
        $this->assertTrue($this->asStaff($t['cashier'])->getJson(route('pos.offline.snapshot', ['branch' => $t['branch']->id]))->json('parts.caps.card_offline_enabled'));
        $this->assertSame(0, PosOfflineCardPayment::query()->count());
    }

    public function test_caps_sar_250_and_2500_a_store_may_only_lower_them_and_a_tender_above_is_flagged(): void
    {
        $t = $this->make(200.0, '0');
        $caps = $this->asStaff($t['cashier'])->getJson(route('pos.offline.snapshot', ['branch' => $t['branch']->id]))->json('parts.caps');
        $this->assertSame([25000, 250000], [$caps['card_max_amount_minor'], $caps['card_max_total_minor']]);
        StoreOption::updateStoreOption($t['store']->id, 'pos_offline_card_max_amount_minor', '99999999');
        StoreOption::updateStoreOption($t['store']->id, 'pos_offline_card_max_total_minor', '40000');
        $caps = $this->asStaff($t['cashier'])->getJson(route('pos.offline.snapshot', ['branch' => $t['branch']->id]))->json('parts.caps');
        $this->assertSame([25000, 40000], [$caps['card_max_amount_minor'], $caps['card_max_total_minor']], 'a store can lower, never raise');
        $t['version'] = $this->asStaff($t['cashier'])->getJson(route('pos.offline.snapshot', ['branch' => $t['branch']->id]))->json('version');

        [$a, $ta] = $this->cardSale($t, 20000, 'REF-A', 1, '0');            // 200.00: inside both caps
        [$b, $tb] = $this->cardSale($t, 20000, 'REF-B', 1, '0');            // total 400.00: at the store's 400 cap
        [$c, $tc] = $this->cardSale($t, 30000, 'REF-C', 1, '0');            // 300.00 > 250 per payment, and over the total
        $res = $this->replay($t, [$a, $ta, $b, $tb, $c, $tc])->assertOk();
        $this->assertNull($res->json('results.1.result.flags'));
        $this->assertNull($res->json('results.3.result.flags'));
        $this->assertSame(['amount_minor' => 30000, 'unforwarded_minor' => 40000, 'cap_amount_minor' => 25000, 'cap_total_minor' => 40000],
            $res->json('results.5.result.flags.cap_exceeded'), (string) json_encode($res->json('results')));
        $this->assertSame('accepted', $res->json('results.5.status'), 'it happened: booked, flagged for review');
        $this->assertSame([25000, 40000], [PosOfflineCardPayment::query()->where('provider_reference', 'REF-C')->value('cap_amount_minor'),
            PosOfflineCardPayment::query()->where('provider_reference', 'REF-C')->value('cap_total_minor')], 'the caps in force are kept as evidence');
    }

    public function test_offline_cards_never_cross_stores(): void
    {
        $a = $this->make();
        $b = $this->make();
        [$sale, $tender] = $this->cardSale($b, 1050, 'REF-B1');
        $idB = $this->replay($b, [$sale, $tender])->json('results.1.result.card_payment_id');
        $this->terminal->script('REF-B1', 'declined');
        app(OfflineCardSettlement::class)->syncStore($b['store']);

        $this->assertSame('pending', app(OfflineCardSettlement::class)->refresh($a['store'], $idB), 'A cannot settle B\'s card');
        $this->asStaff($a['manager'])->postJson(route('pos.offline.cards.resolve', $idB), ['method' => 'cash'])->assertNotFound();
        $this->assertSame([], $this->asStaff($a['manager'])->getJson(route('pos.offline.exceptions'))->json('cards'));
        $this->assertSame('declined_after_offline', PosOfflineCardPayment::query()->withoutGlobalScopes()->find($idB)->state);
        $this->assertSame((int) $b['store']->account_id, (int) PosOfflineCardPayment::query()->withoutGlobalScopes()->find($idB)->account_id);
    }
}
