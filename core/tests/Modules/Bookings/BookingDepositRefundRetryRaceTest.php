<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Bookings;

use App\Models\StoreOption;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Modules\Bookings\Models\Booking;
use Juwri\Modules\Bookings\Services\BookingService;
use Juwri\Modules\Bookings\Services\DepositService;
use Juwri\Modules\Bookings\Services\HoldService;
use Juwri\Modules\Messaging\Contracts\MessageQueue;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\Settlement;
use Juwri\Modules\Payments\Services\LedgerService;
use Tests\Modules\Bookings\Concerns\BookingFixtures;
use Tests\Support\CommittedStoreFixtures;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Lane C bug fix — "Retry refund" of a refused booking deposit refund, raced on a real server database (MySQL 8.4 and
 * PostgreSQL 18; skipped on sqlite): separate PHP processes retry the same pending refund at the same instant. The
 * booking row lock + the deposit state check (DepositService::retryRefund → refund) let exactly one through: one
 * gateway refund on the payment, one ledger reversal, the pending refund closed; every other process finds nothing.
 *
 * Fixture rows are COMMITTED (no per-test transaction, the child processes must see them) and deleted in tearDown.
 */
class BookingDepositRefundRetryRaceTest extends TestCase
{
    use BookingFixtures;
    use RefreshDatabase;

    /** @var list<int> */
    private array $stores = [];

    /** @return array<int, string|null> */
    protected function connectionsToTransact(): array
    {
        return $this->usingInMemoryDatabase() ? [config('database.default')] : [];
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)) {
            $this->markTestSkipped('Cross-process race: runs on MySQL and PostgreSQL (the sqlite suite covers the retry in BookingDepositRefundPendingTest).');
        }
        config(['app.e2e_stubs' => true]);
        $this->mock(MessageQueue::class, fn ($mock) => $mock->shouldReceive('sms'));
    }

    protected function tearDown(): void
    {
        $stores = $this->stores;
        $this->stores = [];
        if ($stores !== [] && in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)) {
            $bookingIds = DB::table('bookings')->whereIn('store_id', $stores)->pluck('id')->all();
            $txnIds = DB::table('bookings')->whereIn('store_id', $stores)->whereNotNull('deposit_transaction_id')->pluck('deposit_transaction_id')->all();
            $refs = [];
            foreach ($bookingIds as $id) {
                $refs[] = 'bkdep:refund:'.$id.':clr';
            }
            foreach ($txnIds as $id) {
                $refs[] = 'bkdep:pay:'.$id.':clr';
            }
            DB::table('ledger_entries')->whereIn('ref', $refs)->delete();
            foreach (['booking_pending_refunds', 'booking_slot_cells', 'booking_holds', 'booking_lines', 'bookings', 'booking_closed_days', 'booking_hours', 'booking_staff_services', 'booking_services', 'booking_staff', 'activity_logs'] as $table) {
                DB::table($table)->whereIn('store_id', $stores)->delete();
            }
            DB::table('transaction')->whereIn('id', $txnIds)->delete();
            $owners = DB::table('stores')->whereIn('id', $stores)->pluck('user_id')->all();
            DB::table('ledger_accounts')->where('owner_type', 'merchant')->whereIn('owner_id', $owners)->delete();
            // Every other committed row of these stores (their default branches too), the stores, accounts and owners.
            CommittedStoreFixtures::purge($stores);
            TestPlans::delete(...$this->bookingPlans);
        }
        parent::tearDown();
    }

    /** @return array{0: Booking, 1: Store} a cancelled booking whose 30 SAR Stub deposit refund was refused, now refundable */
    private function pendingRefund(): array
    {
        [$store, $service, $staff] = $this->readyStore('UTC', ['booking_deposit_refunds' => true, 'booking_max_days' => 30], ['deposit_type' => 'amount', 'deposit_value' => 3000]);
        $this->stores[] = (int) $store->id;
        StoreOption::updateStoreOption($store->id, 'auto_refunds_enabled', '1');
        $start = CarbonImmutable::now('UTC')->addDays(3)->setTime(10, 0);
        $made = app(HoldService::class)->hold($store, [$service->id], $staff->id, $start, 'venue', null);
        $booking = app(BookingService::class)->book($store, $made['hold']->public_id, $made['token'], ['name' => 'Noura', 'phone' => '1']);
        $txn = app(DepositService::class)->start($booking, 'stub');
        Settlement::settleForBuyer($txn->fresh(), 'stub', 'pay_race_'.$booking->id, claim: true);
        Transaction::query()->whereKey($txn->id)->update(['status' => Transaction::STATUS_PENDING]);
        $this->assertSame('refund_pending', app(BookingService::class)->cancel($booking->fresh()));
        Transaction::query()->whereKey($txn->id)->update(['status' => Transaction::STATUS_SUCCESS]);

        return [$booking->fresh(), $store];
    }

    /** @return list<string> */
    private function race(int $n, Booking $booking): array
    {
        $conn = config('database.connections.'.config('database.default'));
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => (string) config('database.default'),
            'DB_HOST' => (string) ($conn['host'] ?? '127.0.0.1'),
            'DB_PORT' => (string) ($conn['port'] ?? ''),
            'DB_DATABASE' => (string) $conn['database'],
            'DB_USERNAME' => (string) ($conn['username'] ?? ''),
            'DB_PASSWORD' => (string) ($conn['password'] ?? ''),
            'DB_PREFIX' => (string) ($conn['prefix'] ?? ''),
        ]);
        $go = sprintf('%.6F', microtime(true) + 4.0);
        $procs = [];
        for ($i = 0; $i < $n; $i++) {
            $pipes = [];
            $proc = proc_open([PHP_BINARY, __DIR__.'/Support/refund_retry_child.php', (string) $booking->id, $go], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $this->assertIsResource($proc);
            $procs[] = [$proc, $pipes];
        }
        $out = [];
        foreach ($procs as [$proc, $pipes]) {
            $stdout = trim((string) stream_get_contents($pipes[1]));
            $stderr = trim((string) stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            $out[] = $stdout !== '' ? $stdout : 'error: '.substr($stderr, 0, 500);
        }
        sort($out);

        return $out;
    }

    public function test_concurrent_retries_refund_the_deposit_exactly_once(): void
    {
        [$booking, $store] = $this->pendingRefund();

        $this->assertSame(['nothing', 'nothing', 'refunded'], $this->race(3, $booking));

        $fresh = $booking->fresh();
        $this->assertSame('refunded', $fresh->deposit_status);
        $this->assertSame('done', DB::table('booking_pending_refunds')->where('booking_id', $booking->id)->value('status'));
        $txn = Transaction::query()->findOrFail($fresh->deposit_transaction_id);
        $refunds = array_values(array_filter((array) json_decode((string) json_encode($txn->details?->gateway_refunds ?? []), true)));
        $this->assertCount(1, $refunds, 'one refund reached the gateway');
        $this->assertSame(3000, $refunds[0]['amount_minor']);
        $merchant = LedgerService::balanceFor(LedgerService::accountFor('merchant', (int) $store->user_id, 'merchant', 'SAR'));
        $this->assertEqualsWithDelta(0.0, $merchant, 0.001, 'the ledger reversed the deposit once');
    }
}
