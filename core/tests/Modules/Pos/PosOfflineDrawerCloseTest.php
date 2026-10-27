<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\PosShift;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Branch;
use Juwri\Core\Money\Money;
use Tests\TestCase;

/**
 * P6-223: an offline drawer close closes the shift it was counted for (named by the offline open's shift_key or an
 * online shift_id), never "whatever is open now". A shift that is gone or already closed is a conflict for the
 * manager; a replayed close is a duplicate and closes nothing twice.
 */
class PosOfflineDrawerCloseTest extends TestCase
{
    use OfflineTillFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    public function test_close_after_an_online_close_and_reopen_leaves_the_new_shift_open(): void
    {
        $t = $this->make();
        $open = $this->rec($t, 'drawer', ['action' => 'open', 'opening_float_minor' => 10000]);
        $sale = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 1]]);
        $tender = $this->rec($t, 'tender', ['sale_key' => $sale['key'], 'method' => 'cash', 'amount_minor' => 1208, 'tendered_minor' => 1208, 'shift_key' => $open['key']]);
        $this->replay($t, [$open, $sale, $tender])->assertOk();
        $old = PosShift::query()->where('store_id', $t['store']->id)->firstOrFail();

        PosService::closeShift($old, 112.08);                           // a manager closes it online...
        $this->travel(1)->hours();
        $new = PosService::openShift($t['store'], $t['manager'], $t['branch']->id, 50); // ...and opens the next one

        $close = $this->rec($t, 'drawer', ['action' => 'close', 'counted_minor' => 11000, 'shift_key' => $open['key']], $t['manager']->id);
        $this->replay($t, [$close], $t['manager'])->assertOk()
            ->assertJsonPath('results.0.status', 'conflict')->assertJsonPath('results.0.reason', 'shift_already_closed');
        $this->assertSame('open', $new->fresh()->status);
        $this->assertSame(0, Money::minorFromMajor((string) $old->fresh()->over_short, 'SAR'), 'the online count stands');
    }

    public function test_close_of_an_offline_open_that_lost_to_an_online_shift_is_a_conflict(): void
    {
        $t = $this->make();
        $online = PosService::openShift($t['store'], $t['manager'], $t['branch']->id, 50);
        $open = $this->rec($t, 'drawer', ['action' => 'open', 'opening_float_minor' => 10000]);
        $close = $this->rec($t, 'drawer', ['action' => 'close', 'counted_minor' => 10000, 'shift_key' => $open['key']], $t['manager']->id);
        $res = $this->replay($t, [$open, $close], $t['manager'])->assertOk();
        $this->assertSame(['conflict', 'conflict'], array_column($res->json('results'), 'status'));
        $this->assertSame(['shift_already_open', 'shift_not_found'], array_column($res->json('results'), 'reason'));
        $this->assertSame('open', $online->fresh()->status);
        $this->assertNull($online->fresh()->counted_cash);
    }

    public function test_close_by_shift_key_gives_the_offline_shift_its_own_z_report_and_replays_once(): void
    {
        $t = $this->make();
        $open = $this->rec($t, 'drawer', ['action' => 'open', 'opening_float_minor' => 10000]);
        $sale = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 2]]);
        $tender = $this->rec($t, 'tender', ['sale_key' => $sale['key'], 'method' => 'cash', 'amount_minor' => 2415, 'tendered_minor' => 2415, 'shift_key' => $open['key']]);
        $close = $this->rec($t, 'drawer', ['action' => 'close', 'counted_minor' => 12400, 'shift_key' => $open['key']], $t['manager']->id);
        $res = $this->replay($t, [$open, $sale, $tender, $close], $t['manager'])->assertOk();
        $this->assertSame(['accepted', 'accepted', 'accepted', 'accepted'], array_column($res->json('results'), 'status'));
        $shift = PosShift::query()->findOrFail($res->json('results.0.result.shift_id'));
        $this->assertSame($shift->id, $res->json('results.3.result.shift_id'));
        $this->assertSame('closed', $shift->status);
        $this->assertSame(12415, Money::minorFromMajor((string) $shift->expected_cash, 'SAR'));
        $this->assertSame(-15, Money::minorFromMajor((string) $shift->over_short, 'SAR'));
        $closedAt = (string) $shift->closed_at;

        $this->travel(5)->minutes();
        $this->replay($t, [$close], $t['manager'])->assertOk()->assertJsonPath('results.0.status', 'duplicate');
        $this->assertSame($closedAt, (string) $shift->fresh()->closed_at, 'a replayed close closes nothing again');
    }

    public function test_an_old_client_close_without_a_key_never_closes_a_shift_opened_after_it_counted(): void
    {
        $t = $this->make();
        $this->travel(1)->hours();
        $online = PosService::openShift($t['store'], $t['manager'], $t['branch']->id, 50);
        $close = $this->rec($t, 'drawer', ['action' => 'close', 'counted_minor' => 5000], $t['manager']->id);
        $close['created_at'] = now()->subMinutes(30)->toIso8601ZuluString();
        $this->replay($t, [$close], $t['manager'])->assertOk()
            ->assertJsonPath('results.0.status', 'conflict')->assertJsonPath('results.0.reason', 'shift_changed');
        $this->assertSame('open', $online->fresh()->status);
    }

    public function test_an_old_client_close_of_a_shift_open_before_it_counted_still_closes(): void
    {
        $t = $this->make();
        $online = PosService::openShift($t['store'], $t['manager'], $t['branch']->id, 50);
        $this->travel(1)->hours();
        $close = $this->rec($t, 'drawer', ['action' => 'close', 'counted_minor' => 5000], $t['manager']->id);
        $this->replay($t, [$close], $t['manager'])->assertOk()->assertJsonPath('results.0.status', 'accepted');
        $this->assertSame('closed', $online->fresh()->status);
    }

    public function test_a_shift_id_of_another_branch_or_store_is_not_found(): void
    {
        $t = $this->make();
        $other = $this->make();
        $branch2 = Branch::create(['store_id' => $t['store']->id, 'name' => 'Second', 'active' => 1, 'accepting_orders' => 1, 'timezone' => 'UTC']);
        $there = PosService::openShift($t['store'], $t['manager'], $branch2->id, 50);
        $foreign = PosService::openShift($other['store'], $other['manager'], $other['branch']->id, 50);
        $a = $this->rec($t, 'drawer', ['action' => 'close', 'counted_minor' => 5000, 'shift_id' => $there->id], $t['manager']->id);
        $b = $this->rec($t, 'drawer', ['action' => 'close', 'counted_minor' => 5000, 'shift_id' => $foreign->id], $t['manager']->id);
        $res = $this->replay($t, [$a, $b], $t['manager'])->assertOk();
        $this->assertSame(['shift_not_found', 'shift_not_found'], array_column($res->json('results'), 'reason'));
        $this->assertSame('open', $there->fresh()->status);
        $this->assertSame('open', $foreign->fresh()->status);
    }

    /**
     * P6-343: an offline close that names a shift the manager already closed online is a conflict carrying the FIRST
     * Z-report; the shift row's counted_cash / over_short / closed_at are never overwritten by the replayed attempt.
     */
    public function test_offline_close_by_shift_id_after_online_close_returns_the_first_z_report(): void
    {
        $t = $this->make();
        $open = $this->rec($t, 'drawer', ['action' => 'open', 'opening_float_minor' => 10000]);
        $this->replay($t, [$open])->assertOk()->assertJsonPath('results.0.status', 'accepted');
        $old = PosShift::query()->where('store_id', $t['store']->id)->firstOrFail();

        $count = 110.0; // the FIRST close's count — distinct from the offline attempt's count below
        PosService::closeShift($old, $count);
        $old->refresh();
        $firstCountedMinor = Money::minorFromMajor((string) $old->counted_cash, 'SAR');
        $firstOverShort = (string) $old->over_short;
        $firstClosedAt = (string) $old->closed_at;
        $firstShiftId = (int) $old->id;

        $close = $this->rec($t, 'drawer', ['action' => 'close', 'counted_minor' => 99999, 'shift_id' => $old->id], $t['manager']->id);
        $res = $this->replay($t, [$close], $t['manager'])->assertOk()
            ->assertJsonPath('results.0.status', 'conflict')
            ->assertJsonPath('results.0.reason', 'shift_already_closed')
            ->assertJsonPath('results.0.result.already_closed', true)
            ->assertJsonPath('results.0.result.shift_id', $firstShiftId);

        // the Z-report in the conflict carries the FIRST close's count, NOT the offline attempt's
        $this->assertSame($firstCountedMinor, Money::minorFromMajor((string) $res->json('results.0.result.zreport.counted_cash'), 'SAR'));
        $this->assertEquals((float) $count, (float) $res->json('results.0.result.zreport.counted_cash'));

        $after = $old->fresh();
        $this->assertSame($firstCountedMinor, Money::minorFromMajor((string) $after->counted_cash, 'SAR'), 'shift row counted_cash unchanged');
        $this->assertSame($firstOverShort, (string) $after->over_short, 'shift row over_short unchanged');
        $this->assertSame($firstClosedAt, (string) $after->closed_at, 'shift row closed_at unchanged');
    }

    /**
     * P6-343: the same offline-close record replayed answers with the stored conflict result — no second calculation,
     * no second side-effect; the second reply is identical to the first.
     */
    public function test_a_replayed_offline_close_of_an_online_closed_shift_repeats_the_same_conflict(): void
    {
        $t = $this->make();
        $open = $this->rec($t, 'drawer', ['action' => 'open', 'opening_float_minor' => 10000]);
        $this->replay($t, [$open])->assertOk()->assertJsonPath('results.0.status', 'accepted');
        $old = PosShift::query()->where('store_id', $t['store']->id)->firstOrFail();
        PosService::closeShift($old, 110.0);

        $close = $this->rec($t, 'drawer', ['action' => 'close', 'counted_minor' => 99999, 'shift_id' => $old->id], $t['manager']->id);
        $first = $this->replay($t, [$close], $t['manager'])->assertOk()
            ->assertJsonPath('results.0.status', 'conflict')
            ->assertJsonPath('results.0.reason', 'shift_already_closed');

        $second = $this->replay($t, [$close], $t['manager'])->assertOk()
            ->assertJsonPath('results.0.status', 'conflict')
            ->assertJsonPath('results.0.reason', 'shift_already_closed')
            ->assertJsonPath('results.0.duplicate', true);

        $this->assertEquals($first->json('results.0.result'), $second->json('results.0.result'),
            're-sent offline close repeats the same stored conflict result');
        $this->assertSame('closed', $old->fresh()->status, 'the shift stays closed exactly once');
    }
}
