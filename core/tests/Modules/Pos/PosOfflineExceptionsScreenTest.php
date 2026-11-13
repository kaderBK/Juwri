<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Contracts\PaymentTerminal;
use Juwri\Modules\Payments\Services\Terminal\FakeTerminal;
use Juwri\Modules\Pos\Models\PosOfflineCardPayment;
use Juwri\Modules\Pos\Models\PosOfflineRecord;
use Juwri\Modules\Pos\Services\Offline\OfflineCardSettlement;
use Juwri\Modules\Pos\Services\Offline\OfflineExceptionKinds;
use Tests\TestCase;

/**
 * OP-10 (part 1): the manager "Offline exceptions" screen. The `pos.offline.exceptions` JSON gains the derived
 * `kinds`/`shift_id` and server-side kind/device/shift filters; a manager-only screen route serves the Blade view;
 * nothing from another store is ever visible or resolvable from here.
 */
class PosOfflineExceptionsScreenTest extends TestCase
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

    public function test_each_of_the_seven_record_kinds_is_mapped(): void
    {
        $row = fn (array $o) => array_merge(['type' => 'sale', 'status' => 'accepted', 'reason' => null, 'flags' => []], $o);

        $this->assertSame(['pricing_mismatch'], OfflineExceptionKinds::for($row(['reason' => 'pricing_mismatch'])));
        $this->assertSame(['old_snapshot_price'], OfflineExceptionKinds::for($row(['flags' => ['priced_on_old_snapshot' => []]])));
        $this->assertSame(['old_snapshot_price'], OfflineExceptionKinds::for($row(['flags' => ['price_not_in_snapshot' => []]])));
        $this->assertSame(['oversell'], OfflineExceptionKinds::for($row(['flags' => ['oversell' => [1]]])));
        $this->assertSame(['oversell'], OfflineExceptionKinds::for($row(['flags' => ['sold_out_since' => [1]]])));
        $this->assertSame(['deleted_item'], OfflineExceptionKinds::for($row(['flags' => ['item_gone' => [1]]])));
        $this->assertSame(['late_tender'], OfflineExceptionKinds::for($row(['flags' => ['late_for_closed_shift' => 4]])));
        $this->assertSame(['void_conflict'], OfflineExceptionKinds::for($row(['type' => 'void', 'status' => 'conflict', 'reason' => 'order_changed'])));
        $this->assertSame(['cap_exceeded'], OfflineExceptionKinds::for($row(['type' => 'tender', 'flags' => ['cap_exceeded' => []]])));
    }

    public function test_kinds_multiple_flags_empty_and_unknown_shapes_never_throw(): void
    {
        $multi = ['type' => 'sale', 'status' => 'accepted', 'reason' => null, 'flags' => ['item_gone' => [1], 'oversell' => [1], 'priced_on_old_snapshot' => []]];
        $this->assertEqualsCanonicalizing(['old_snapshot_price', 'oversell', 'deleted_item'], OfflineExceptionKinds::for($multi));

        $this->assertSame([], OfflineExceptionKinds::for([]));
        $this->assertSame([], OfflineExceptionKinds::for(['type' => 'drawer', 'status' => 'accepted', 'reason' => null, 'flags' => null]));
        $this->assertSame([], OfflineExceptionKinds::for(['type' => null, 'status' => null, 'reason' => 'x', 'flags' => 'not-an-array']));
        $this->assertSame([], OfflineExceptionKinds::for(['type' => 'sale', 'status' => 'accepted', 'reason' => null, 'flags' => ['tax_changed' => []]]), 'unknown flags give no kind');
    }

    public function test_the_screen_route_requires_the_manager_permission(): void
    {
        $t = $this->make();
        $this->asStaff($t['cashier'])->get(route('pos.offline.exceptions.screen'))->assertForbidden();
        $this->asStaff($t['manager'])->get(route('pos.offline.exceptions.screen'))
            ->assertOk()->assertViewIs('staff.pos.exceptions');
    }

    /** An offline record row of the given store, with sensible defaults and a fresh key. */
    private function rawRecord(array $t, array $attrs = []): PosOfflineRecord
    {
        return PosOfflineRecord::create(array_merge([
            'account_id' => $t['store']->account_id,
            'store_id' => $t['store']->id,
            'branch_id' => $t['branch']->id,
            'device_id' => 'till-aaaa-11111111',
            'device_seq' => 1,
            'client_key' => (string) Str::uuid7(),
            'type' => 'sale',
            'payload_hash' => str_repeat('a', 64),
            'status' => 'conflict',
            'reason' => null,
            'order_id' => null,
            'flags' => null,
            'created_at' => now(),
        ], $attrs));
    }

    /**
     * A full offline card that ended `declined_after_offline` in this store. Returns [card, its order].
     *
     * @param  array<string, mixed>  $t
     * @return array{0: PosOfflineCardPayment, 1: Order}
     */
    private function declinedCard(array $t, string $ref, int $unit = 1050): array
    {
        $sale = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => $unit, 'qty' => 1]]);
        $tender = $this->rec($t, 'tender', ['sale_key' => $sale['key'], 'method' => 'card_offline',
            'amount_minor' => $sale['payload']['totals']['total_minor'], 'sdk_reference' => $ref]);
        $id = (int) $this->replay($t, [$sale, $tender])->assertOk()->json('results.1.result.card_payment_id');
        $this->terminal->script($ref, 'declined');
        app(OfflineCardSettlement::class)->refresh($t['store'], $id);
        $card = PosOfflineCardPayment::query()->withoutGlobalScopes()->findOrFail($id);
        $order = Order::query()->where('store_id', $t['store']->id)->where('client_uuid', $sale['key'])->firstOrFail();

        return [$card, $order];
    }

    public function test_json_includes_kinds_and_shift_id_and_filters_apply_server_side(): void
    {
        $t = $this->make();
        $shift = 777;
        $order = Order::create(['store_id' => $t['store']->id, 'branch_id' => $t['branch']->id, 'type' => 'takeaway', 'status' => 'completed']);
        $order->forceFill(['pos_shift_id' => $shift])->save();

        $priced = $this->rawRecord($t, ['type' => 'sale', 'status' => 'accepted', 'flags' => ['priced_on_old_snapshot' => []], 'order_id' => $order->id, 'device_id' => 'till-aaaa-11111111']);
        $pricing = $this->rawRecord($t, ['type' => 'sale', 'status' => 'conflict', 'reason' => 'pricing_mismatch', 'flags' => null, 'device_id' => 'till-bbbb-22222222']);
        $oversell = $this->rawRecord($t, ['type' => 'sale', 'status' => 'accepted', 'flags' => ['oversell' => [1]], 'device_id' => 'till-aaaa-11111111']);

        $all = $this->asStaff($t['manager'])->getJson(route('pos.offline.exceptions'))->assertOk()->json();
        $this->assertEqualsCanonicalizing([$priced->client_key, $pricing->client_key, $oversell->client_key], array_column($all['exceptions'], 'client_key'));
        $this->assertContains($shift, array_map('intval', $all['shifts']));

        $row = collect($all['exceptions'])->firstWhere('client_key', $priced->client_key);
        $this->assertSame(['old_snapshot_price'], $row['kinds']);
        $this->assertSame($shift, $row['shift_id']);
        $this->assertArrayHasKey('kinds', $row);
        $this->assertArrayHasKey('shift_id', $row);
        $this->assertNull(collect($all['exceptions'])->firstWhere('client_key', $pricing->client_key)['shift_id'], 'a record without an order has no shift');

        $kind = fn ($k) => $this->asStaff($t['manager'])->getJson(route('pos.offline.exceptions', ['kind' => $k]))->assertOk();
        $this->assertSame([$pricing->client_key], array_column($kind('pricing_mismatch')->json('exceptions'), 'client_key'));
        $this->assertSame([$priced->client_key], array_column($kind('old_snapshot_price')->json('exceptions'), 'client_key'));
        $this->assertSame([$oversell->client_key], array_column($kind('oversell')->json('exceptions'), 'client_key'));

        $device = $this->asStaff($t['manager'])->getJson(route('pos.offline.exceptions', ['device_id' => 'till-aaaa-11111111']))->assertOk()->json('exceptions');
        $this->assertEqualsCanonicalizing([$priced->client_key, $oversell->client_key], array_column($device, 'client_key'));

        $byShift = $this->asStaff($t['manager'])->getJson(route('pos.offline.exceptions', ['shift_id' => $shift]))->assertOk()->json('exceptions');
        $this->assertSame([$priced->client_key], array_column($byShift, 'client_key'));
    }

    public function test_records_and_cards_never_cross_stores_for_any_filter_combination(): void
    {
        $a = $this->make();
        $b = $this->make();
        $aRec = $this->rawRecord($a, ['type' => 'sale', 'status' => 'accepted', 'flags' => ['oversell' => [1]], 'device_id' => 'till-aaaa-aaaaaaaa']);
        $bRec = $this->rawRecord($b, ['type' => 'sale', 'status' => 'accepted', 'flags' => ['sold_out_since' => [1]], 'device_id' => 'till-bbbb-bbbbbbbb']);
        [$aCard] = $this->declinedCard($a, 'REF-X-A');
        [$bCard] = $this->declinedCard($b, 'REF-X-B');

        $combos = [
            [],
            ['kind' => 'oversell'],
            ['kind' => 'old_snapshot_price'],
            ['kind' => 'declined_after_offline'],
            ['device_id' => 'till-aaaa-aaaaaaaa'],
            ['device_id' => self::DEVICE],
            ['kind' => 'oversell', 'device_id' => 'till-bbbb-bbbbbbbb'],
        ];
        foreach ($combos as $params) {
            $mine = $this->asStaff($a['manager'])->getJson(route('pos.offline.exceptions', $params))->assertOk()->json();
            $this->assertNotContains($bRec->client_key, array_column($mine['exceptions'], 'client_key'), 'store A shows B\'s record');
            $this->assertNotContains((int) $bCard->id, array_map('intval', array_column($mine['cards'], 'id')), 'store A shows B\'s card');

            $theirs = $this->asStaff($b['manager'])->getJson(route('pos.offline.exceptions', $params))->assertOk()->json();
            $this->assertNotContains($aRec->client_key, array_column($theirs['exceptions'], 'client_key'), 'store B shows A\'s record');
            $this->assertNotContains((int) $aCard->id, array_map('intval', array_column($theirs['cards'], 'id')), 'store B shows A\'s card');
        }

        $none = $this->asStaff($a['manager'])->getJson(route('pos.offline.exceptions'))->assertOk()->json();
        $this->assertContains($aRec->client_key, array_column($none['exceptions'], 'client_key'));
        $this->assertContains((int) $aCard->id, array_map('intval', array_column($none['cards'], 'id')));
    }

    public function test_resolving_another_stores_card_is_refused_and_leaves_it_unchanged(): void
    {
        $a = $this->make();
        $b = $this->make();
        [$aCard] = $this->declinedCard($a, 'REF-TN-A');
        [$bCard, $bOrder] = $this->declinedCard($b, 'REF-TN-B');

        $this->asStaff($a['manager'])->postJson(route('pos.offline.cards.resolve', $bCard->id), ['method' => 'cash'])
            ->assertNotFound();

        $card = PosOfflineCardPayment::query()->withoutGlobalScopes()->findOrFail($bCard->id);
        $this->assertSame('declined_after_offline', $card->state, 'B\'s card is unchanged');
        $this->assertFalse((bool) $bOrder->fresh()->is_paid, 'B\'s order is not settled by A');
        $this->assertContains((int) $aCard->id, array_map('intval', array_column(
            $this->asStaff($a['manager'])->getJson(route('pos.offline.exceptions'))->assertOk()->json('cards'), 'id')));
    }

    public function test_resolve_flow_works_for_cash_and_card_and_repeats_are_idempotent(): void
    {
        $t = $this->make();
        [$cashCard, $cashOrder] = $this->declinedCard($t, 'REF-RES-CASH');
        [$cardCard, $cardOrder] = $this->declinedCard($t, 'REF-RES-CARD');

        $this->asStaff($t['manager'])->postJson(route('pos.offline.cards.resolve', $cashCard->id), ['method' => 'cash'])
            ->assertOk()->assertJson(['state' => 'resolved', 'resolution_method' => 'cash']);
        $this->assertTrue((bool) $cashOrder->fresh()->is_paid);
        $this->assertSame('cash', $cashOrder->fresh()->payment_method);

        $this->asStaff($t['manager'])->postJson(route('pos.offline.cards.resolve', $cashCard->id), ['method' => 'cash'])
            ->assertOk()->assertJson(['state' => 'resolved', 'resolution_method' => 'cash']);
        $this->assertSame('resolved', PosOfflineCardPayment::query()->findOrFail($cashCard->id)->state, 'a repeated resolve is a no-op');

        $this->asStaff($t['manager'])->postJson(route('pos.offline.cards.resolve', $cardCard->id), ['method' => 'card'])
            ->assertOk()->assertJson(['state' => 'resolved', 'resolution_method' => 'card']);
        $this->assertTrue((bool) $cardOrder->fresh()->is_paid);
        $this->assertSame('card', $cardOrder->fresh()->payment_method);

        $this->asStaff($t['manager'])->postJson(route('pos.offline.cards.resolve', $cashCard->id), ['method' => 'bitcoin'])
            ->assertStatus(422)->assertJson(['code' => 'not_resolvable']);
    }

    public function test_record_pointing_at_another_stores_order_has_null_shift_and_its_shift_stays_hidden(): void
    {
        $a = $this->make();
        $b = $this->make();
        $bOrder = Order::create(['store_id' => $b['store']->id, 'branch_id' => $b['branch']->id, 'type' => 'takeaway', 'status' => 'completed']);
        $bOrder->forceFill(['pos_shift_id' => 4242])->save();

        $this->rawRecord($a, ['type' => 'sale', 'status' => 'accepted', 'flags' => ['oversell' => [1]], 'order_id' => $bOrder->id]);

        $json = $this->asStaff($a['manager'])->getJson(route('pos.offline.exceptions'))->assertOk()->json();
        $row = collect($json['exceptions'])->first();
        $this->assertNull($row['shift_id'], 'another store\'s shift must never resolve to a shift id');
        $this->assertNotContains(4242, array_map('intval', $json['shifts']), "store B's shift must not appear in A's filter list");
    }

    public function test_shift_and_device_filters_apply_to_cards(): void
    {
        $t = $this->make();
        [$card, $order] = $this->declinedCard($t, 'REF-FLT-CARD');
        $order->forceFill(['pos_shift_id' => 999])->save();

        $byDevice = $this->asStaff($t['manager'])->getJson(route('pos.offline.exceptions', ['device_id' => self::DEVICE]))->assertOk()->json('cards');
        $this->assertContains((int) $card->id, array_map('intval', array_column($byDevice, 'id')));

        $missing = $this->asStaff($t['manager'])->getJson(route('pos.offline.exceptions', ['device_id' => 'till-nope-00000000']))->assertOk()->json('cards');
        $this->assertSame([], $missing);

        $byShift = $this->asStaff($t['manager'])->getJson(route('pos.offline.exceptions', ['shift_id' => 999]))->assertOk()->json();
        $this->assertContains((int) $card->id, array_map('intval', array_column($byShift['cards'], 'id')));

        $notInShift = $this->asStaff($t['manager'])->getJson(route('pos.offline.exceptions', ['shift_id' => 3]))->assertOk()->json('cards');
        $this->assertSame([], $notInShift);
    }

    public function test_kind_declined_after_offline_shows_only_cards(): void
    {
        $t = $this->make();
        [$card] = $this->declinedCard($t, 'REF-KIND-CARD');
        $this->rawRecord($t, ['type' => 'sale', 'status' => 'accepted', 'flags' => ['oversell' => [1]]]);

        $json = $this->asStaff($t['manager'])->getJson(route('pos.offline.exceptions', ['kind' => 'declined_after_offline']))->assertOk()->json();
        $this->assertSame([], $json['exceptions']);
        $this->assertContains((int) $card->id, array_map('intval', array_column($json['cards'], 'id')));
    }

    public function test_a_record_kind_returns_no_cards(): void
    {
        $t = $this->make();
        $this->declinedCard($t, 'REF-KIND-REC');
        $rec = $this->rawRecord($t, ['type' => 'sale', 'status' => 'accepted', 'flags' => ['oversell' => [1]]]);

        $json = $this->asStaff($t['manager'])->getJson(route('pos.offline.exceptions', ['kind' => 'oversell']))->assertOk()->json();
        $this->assertSame([$rec->client_key], array_column($json['exceptions'], 'client_key'));
        $this->assertSame([], $json['cards']);
    }

    public function test_unknown_kind_returns_nothing(): void
    {
        $t = $this->make();
        $this->declinedCard($t, 'REF-KIND-UNKNOWN');
        $this->rawRecord($t, ['type' => 'sale', 'status' => 'accepted', 'flags' => ['oversell' => [1]]]);

        $json = $this->asStaff($t['manager'])->getJson(route('pos.offline.exceptions', ['kind' => 'bogus_kind']))->assertOk()->json();
        $this->assertSame([], $json['exceptions']);
        $this->assertSame([], $json['cards']);
    }

    public function test_non_numeric_shift_id_is_rejected(): void
    {
        $t = $this->make();

        $this->asStaff($t['manager'])->getJson(route('pos.offline.exceptions', ['shift_id' => 'abc']))
            ->assertStatus(422)->assertJson(['code' => 'bad_filter']);
    }

    public function test_device_filter_finds_a_record_beyond_the_newest_two_hundred(): void
    {
        $t = $this->make();
        $target = $this->rawRecord($t, ['type' => 'sale', 'status' => 'accepted', 'flags' => ['oversell' => [1]], 'device_id' => 'till-target-00000000']);
        for ($i = 0; $i < 205; $i++) {
            $this->rawRecord($t, ['type' => 'sale', 'status' => 'accepted', 'flags' => ['oversell' => [1]], 'device_id' => 'till-flood-00000000']);
        }

        $json = $this->asStaff($t['manager'])->getJson(route('pos.offline.exceptions', ['device_id' => 'till-target-00000000']))->assertOk()->json();
        $this->assertContains($target->client_key, array_column($json['exceptions'], 'client_key'));
    }

    public function test_the_rail_link_to_the_exceptions_screen_is_manager_only(): void
    {
        $t = $this->make();
        $href = route('pos.offline.exceptions.screen');

        $managerHtml = $this->asStaff($t['manager'])->get(route('pos.index'))->assertOk()->getContent();
        $this->assertStringContainsString($href, $managerHtml);

        $cashierHtml = $this->asStaff($t['cashier'])->get(route('pos.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString($href, $cashierHtml);
    }

    /**
     * Fixture note: make() ends with a staff request that leaves the staff guard logged in; `auth:staff,web` prefers it
     * and its tenant scope narrows to that cashier's store, so the owner needs a clean session (as asStaff() does).
     */
    private function asOwner(User $owner): static
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($owner, 'web');
    }

    public function test_an_owner_opens_their_own_exceptions_screen_but_never_another_stores(): void
    {
        $mine = $this->make();
        $others = $this->make();
        $owner = User::find($mine['store']->user_id);

        $this->asOwner($owner)->get(route('pos.offline.exceptions.screen', ['store' => $mine['store']->id]))
            ->assertOk()->assertViewIs('staff.pos.exceptions');
        $this->asOwner($owner)->get(route('pos.offline.exceptions.screen', ['store' => $others['store']->id]))
            ->assertNotFound();
        // the data endpoint too: an owner's request for another store's exceptions is refused, never served
        $this->asOwner($owner)->getJson(route('pos.offline.exceptions', ['store' => $others['store']->id]))
            ->assertNotFound();
    }

    /** Regression (found by the OP-10 tenancy review): PIN hand-over then owner password must not leave the owner scoped to the cashier's store. */
    public function test_owner_with_two_stores_regains_both_after_a_pin_handover_and_password_unlock(): void
    {
        $a = $this->make();
        $b = $this->make();
        $owner = User::find($a['store']->user_id);
        $owner->forceFill(['password' => bcrypt('owner-pass-1')])->save();
        // a second store of the SAME owner, with its own data
        $b['store']->forceFill(['user_id' => $owner->id])->save();
        $b['manager']->forceFill(['owner_id' => $owner->id])->save();
        $b['cashier']->forceFill(['owner_id' => $owner->id])->save();
        $b['manager']->forceFill(['pin' => '4321'])->save();

        $this->asOwner($owner);
        $this->postJson(route('pos.unlock', ['store' => $b['store']->id]), ['pin' => '4321'])->assertOk();
        $this->assertSame($b['manager']->id, auth('staff')->id());

        $this->postJson(route('pos.unlock', ['store' => $b['store']->id]), ['password' => 'owner-pass-1'])->assertOk();
        $this->assertNull(auth('staff')->id(), 'the PIN cashier is logged out of the staff guard');

        $this->get(route('pos.offline.exceptions.screen', ['store' => $a['store']->id]))->assertOk();
        $this->get(route('pos.offline.exceptions.screen', ['store' => $b['store']->id]))->assertOk();
    }

    public function test_owner_resolves_a_declined_card_of_their_own_store_but_not_another_stores(): void
    {
        $mine = $this->make();
        $others = $this->make();
        [$myCard, $myOrder] = $this->declinedCard($mine, 'REF-OWN-A');
        [$otherCard, $otherOrder] = $this->declinedCard($others, 'REF-OWN-B');
        $owner = User::find($mine['store']->user_id);

        $this->asOwner($owner)->postJson(route('pos.offline.cards.resolve', ['id' => $otherCard->id, 'store' => $mine['store']->id]), ['method' => 'cash'])
            ->assertNotFound();
        $this->asOwner($owner)->postJson(route('pos.offline.cards.resolve', ['id' => $otherCard->id, 'store' => $others['store']->id]), ['method' => 'cash'])
            ->assertNotFound();
        $this->assertSame('declined_after_offline', PosOfflineCardPayment::query()->withoutGlobalScopes()->findOrFail($otherCard->id)->state);
        $this->assertFalse((bool) $otherOrder->fresh()->is_paid);

        $this->asOwner($owner)->postJson(route('pos.offline.cards.resolve', ['id' => $myCard->id, 'store' => $mine['store']->id]), ['method' => 'cash'])
            ->assertOk()->assertJson(['state' => 'resolved', 'resolution_method' => 'cash']);
        $this->assertTrue((bool) $myOrder->fresh()->is_paid);
    }
}
