<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Kitchen;

use App\Models\Order;
use App\Models\StoreOption;
use App\Services\FeatureAccess;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Kitchen\Services\OrderBoard\DailyTokenService;
use Juwri\Modules\Ordering\Models\DailyToken;
use Tests\TestCase;

/**
 * PARITY-RUN T2-1: the daily token counter.
 *
 * Covers the four ways a counter goes wrong: it repeats a number, it fails to
 * reset at the store's midnight, it leaks across branches, or it hands the same
 * number to two simultaneous orders.
 */
class DailyTokenServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_order_board' => true]);
        DailyTokenService::clearMemo();
        FeatureAccess::flush();
    }

    private function storeFixture(array $options = []): Store
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Diner', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($options + ['order_board_enabled' => '1'] as $key => $value) {
            StoreOption::updateStoreOption($store->id, $key, $value);
        }

        return $store;
    }

    public function test_numbers_run_in_sequence_within_a_day(): void
    {
        $r = $this->storeFixture();

        $seen = [];
        for ($i = 0; $i < 25; $i++) {
            $seen[] = DailyTokenService::next((int) $r->id);
        }

        $this->assertSame(range(1, 25), $seen);
        $this->assertSame(25, (int) DailyToken::where('store_id', $r->id)->value('last_seq'));
    }

    public function test_counter_resets_at_store_local_midnight_not_server_midnight(): void
    {
        // Store in Tokyo (UTC+9). At 16:00 UTC it is already the NEXT day there.
        $r = $this->storeFixture(['timezone' => 'Asia/Tokyo']);

        Carbon::setTestNow(Carbon::parse('2026-07-22 14:00:00', 'UTC')); // 23:00 in Tokyo
        DailyTokenService::clearMemo();
        $this->assertSame(1, DailyTokenService::next((int) $r->id));
        $this->assertSame(2, DailyTokenService::next((int) $r->id));
        $this->assertSame('2026-07-22', DailyTokenService::today((int) $r->id));

        Carbon::setTestNow(Carbon::parse('2026-07-22 16:00:00', 'UTC')); // 01:00 next day in Tokyo
        $this->assertSame('2026-07-23', DailyTokenService::today((int) $r->id));
        $this->assertSame(1, DailyTokenService::next((int) $r->id), 'the counter must restart on the store’s new day');

        // The server's own day has NOT changed — proving the reset is store-local.
        $this->assertSame('2026-07-22', Carbon::now('UTC')->format('Y-m-d'));

        Carbon::setTestNow();
    }

    public function test_each_branch_keeps_its_own_sequence(): void
    {
        $r = $this->storeFixture();
        $a = Branch::create(['store_id' => $r->id, 'name' => 'A', 'position' => 1]);
        $b = Branch::create(['store_id' => $r->id, 'name' => 'B', 'position' => 2]);

        $this->assertSame(1, DailyTokenService::next((int) $r->id, (int) $a->id));
        $this->assertSame(2, DailyTokenService::next((int) $r->id, (int) $a->id));
        $this->assertSame(1, DailyTokenService::next((int) $r->id, (int) $b->id));
        // The store-wide counter (no branch) is a third, independent series.
        $this->assertSame(1, DailyTokenService::next((int) $r->id));
    }

    public function test_two_stores_never_share_a_counter(): void
    {
        $a = $this->storeFixture();
        $b = $this->storeFixture();

        DailyTokenService::next((int) $a->id);
        DailyTokenService::next((int) $a->id);

        $this->assertSame(1, DailyTokenService::next((int) $b->id));
    }

    /**
     * The database — not the application — is what makes a double number
     * impossible: a second counter row for the same store/branch/day is rejected.
     * A nullable branch_id would NOT be (MySQL treats NULLs as distinct), which
     * is why the column is NOT NULL with a 0 sentinel.
     */
    public function test_the_unique_index_rejects_a_second_counter_row(): void
    {
        $r = $this->storeFixture();
        DailyTokenService::next((int) $r->id);

        $this->expectException(QueryException::class);
        DailyToken::create([
            'store_id' => $r->id,
            'branch_id' => 0,
            'token_date' => DailyTokenService::today((int) $r->id),
            'last_seq' => 1,
        ]);
    }

    /**
     * Concurrency: when a request loses the write race (unique-index violation
     * on the day's first order, or a lock timeout on any other) the service must
     * RETRY and take the next number — never surface the error to the checkout
     * and never repeat a number.
     *
     * A genuine two-connection race is not reproducible on the in-memory SQLite
     * the suite runs on (a second connection is a second, empty database), so
     * the lost write is injected on the first attempt instead. What is being
     * asserted is the recovery path, which is the part that can regress.
     */
    public function test_next_retries_after_a_lost_write_race(): void
    {
        $r = $this->storeFixture();
        DailyTokenService::next((int) $r->id); // #1 — the day's row now exists

        $failed = false;
        DailyToken::updating(function () use (&$failed) {
            if ($failed) {
                return;
            }
            $failed = true;

            throw new QueryException(
                'sqlite',
                'update "daily_tokens" set "last_seq" = ?',
                [2],
                new \PDOException('UNIQUE constraint failed: daily_tokens.token_date')
            );
        });

        $token = DailyTokenService::next((int) $r->id);

        $this->assertTrue($failed, 'the race was never triggered — the test is not proving anything');
        $this->assertSame(2, $token, 'the retry must take the next number, not skip or repeat one');
        $this->assertSame(2, (int) DailyToken::where('store_id', $r->id)->value('last_seq'));
        $this->assertSame(1, DailyToken::where('store_id', $r->id)->count());
    }

    public function test_no_numbers_at_all_while_the_board_is_off(): void
    {
        $r = $this->storeFixture(['order_board_enabled' => '0']);

        $order = Order::create(['store_id' => $r->id, 'status' => 'pending', 'type' => 'takeaway']);

        $this->assertNull($order->fresh()->token_number);
        $this->assertNull(DailyTokenService::assign($order));
        $this->assertSame(0, DailyToken::count());
    }

    public function test_only_opted_in_order_types_get_a_number(): void
    {
        // Defaults: takeaway + on-table numbered, delivery + room service not.
        $r = $this->storeFixture();

        $takeaway = Order::create(['store_id' => $r->id, 'status' => 'pending', 'type' => 'takeaway']);
        $delivery = Order::create(['store_id' => $r->id, 'status' => 'pending', 'type' => 'delivery']);

        $this->assertSame(1, (int) $takeaway->fresh()->token_number);
        $this->assertNull($delivery->fresh()->token_number);

        // Owner opts delivery in — the next delivery order is numbered.
        StoreOption::updateStoreOption($r->id, 'order_board_type_delivery', '1');
        $delivery2 = Order::create(['store_id' => $r->id, 'status' => 'pending', 'type' => 'delivery']);
        $this->assertSame(2, (int) $delivery2->fresh()->token_number);
    }

    public function test_assigning_is_idempotent(): void
    {
        $r = $this->storeFixture();
        $order = Order::create(['store_id' => $r->id, 'status' => 'pending', 'type' => 'takeaway']);

        $first = (int) $order->fresh()->token_number;
        $this->assertSame($first, DailyTokenService::assign($order->fresh()));
        $this->assertSame(1, (int) DailyToken::where('store_id', $r->id)->value('last_seq'));
    }

    public function test_receipt_line_is_null_without_a_token(): void
    {
        $r = $this->storeFixture(['order_board_enabled' => '0']);
        $order = Order::create(['store_id' => $r->id, 'status' => 'pending', 'type' => 'takeaway']);

        $this->assertNull(DailyTokenService::receiptLine($order->fresh()));

        StoreOption::updateStoreOption($r->id, 'order_board_enabled', '1');
        $numbered = Order::create(['store_id' => $r->id, 'status' => 'pending', 'type' => 'takeaway']);
        $this->assertStringContainsString('1', (string) DailyTokenService::receiptLine($numbered->fresh()));
    }

    public function test_a_broken_store_timezone_falls_back_instead_of_throwing(): void
    {
        $r = $this->storeFixture(['timezone' => 'Not/AZone']);
        DailyTokenService::clearMemo();

        $this->assertSame(config('app.timezone', 'UTC'), DailyTokenService::timezone((int) $r->id));
        $this->assertSame(1, DailyTokenService::next((int) $r->id));
    }
}
