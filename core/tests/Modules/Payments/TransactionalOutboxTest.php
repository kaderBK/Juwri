<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\Accounting\Jobs\SyncInvoiceJob;
use Juwri\Modules\Ordering\Services\OrderPaidSideEffects;
use Juwri\Modules\Payments\Contracts\Outbox;
use Juwri\Modules\Payments\Jobs\RelayOutboxJob;
use Juwri\Modules\Payments\Services\Gateways\Settlement;
use Juwri\Modules\Payments\Services\Webhooks\TransactionalOutbox;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * J25-7: transactional outbox — a message exists exactly when the transaction that recorded it commits; the relay
 * performs it once (claimed), retries a failing handler with back-off, keeps failed ones; a paid store order's side
 * effects (accounting sync, POS push, GL post, partner webhook) go through it.
 */
class TransactionalOutboxTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    private Outbox $outbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        $this->outbox = app(Outbox::class);
    }

    private function row(int $id): object
    {
        return DB::table('outbox_messages')->find($id);
    }

    public function test_a_message_exists_only_when_its_transaction_commits(): void
    {
        DB::beginTransaction();
        $this->outbox->record('test.effect', ['n' => 1]);
        DB::rollBack();
        $this->assertSame(0, DB::table('outbox_messages')->count());

        DB::transaction(fn () => $this->outbox->record('test.effect', ['n' => 2]));
        $this->assertSame(1, DB::table('outbox_messages')->count());
    }

    public function test_relay_performs_a_message_once_and_dedupe_keys_hold(): void
    {
        $seen = [];
        $this->outbox->handle('test.effect', function (array $p) use (&$seen) {
            $seen[] = $p['n'];
        });

        $id = $this->outbox->record('test.effect', ['n' => 7], 'effect:7');
        $this->assertSame($id, $this->outbox->record('test.effect', ['n' => 7], 'effect:7'), 'one message per intent; still owed → its id');
        $this->assertSame(1, DB::table('outbox_messages')->count());

        $this->assertSame(1, $this->outbox->relay([$id]));
        $this->assertNull($this->outbox->record('test.effect', ['n' => 7], 'effect:7'), 'done → nothing to relay');
        $this->assertSame(0, $this->outbox->relay([$id]), 'a performed message is not performed again');
        $this->assertSame(0, $this->outbox->relay());
        $this->assertSame([7], $seen);
        $this->assertSame('done', $this->row($id)->status);
        $this->assertNotNull($this->row($id)->processed_at);
    }

    /**
     * Regression (int/bc2 MySQL): MySQL counts changed rows, not matched ones — a repeated record() for a message
     * already pending and due in the same second changed nothing and returned null. The owed id is returned on
     * every driver; a failed message is reopened; a message being performed (processing) or done gives null.
     */
    public function test_a_repeated_record_in_the_same_second_returns_the_owed_message_on_every_driver(): void
    {
        $this->travelTo(now()->startOfSecond());

        $id = $this->outbox->record('test.effect', ['n' => 8], 'effect:8');
        $this->assertNotNull($id);
        $this->assertSame($id, $this->outbox->record('test.effect', ['n' => 8], 'effect:8'));
        $this->assertSame($id, $this->outbox->record('test.effect', ['n' => 8], 'effect:8'));

        DB::table('outbox_messages')->where('id', $id)->update(['status' => 'failed', 'available_at' => now()->addHour()]);
        $this->assertSame($id, $this->outbox->record('test.effect', ['n' => 8], 'effect:8'), 'failed → reopened');
        $this->assertSame('pending', $this->row($id)->status);

        DB::table('outbox_messages')->where('id', $id)->update(['status' => 'processing', 'claimed_at' => now()]);
        $this->assertNull($this->outbox->record('test.effect', ['n' => 8], 'effect:8'), 'being performed → null');
        $this->assertSame('processing', $this->row($id)->status);

        DB::table('outbox_messages')->where('id', $id)->update(['status' => 'done', 'processed_at' => now()]);
        $this->assertNull($this->outbox->record('test.effect', ['n' => 8], 'effect:8'), 'done → null');
        $this->assertSame(1, DB::table('outbox_messages')->count());
    }

    public function test_a_failing_handler_backs_off_then_fails_and_is_kept(): void
    {
        $this->outbox->handle('test.flaky', fn () => throw new \RuntimeException('provider down'));
        $id = $this->outbox->record('test.flaky', []);

        $this->assertSame(0, $this->outbox->relay([$id]));
        $row = $this->row($id);
        $this->assertSame(['pending', 1], [$row->status, (int) $row->attempts]);
        $this->assertStringContainsString('provider down', (string) $row->last_error);
        $this->assertSame(0, $this->outbox->relay([$id]), 'not due again before the back-off');

        DB::table('outbox_messages')->where('id', $id)->update(['attempts' => TransactionalOutbox::MAX_ATTEMPTS - 1, 'available_at' => now()->subMinute()]);
        $this->outbox->relay([$id]);
        $this->assertSame('failed', $this->row($id)->status);

        $this->artisan('payments:prune-webhooks')->assertSuccessful();
        $this->assertNotNull($this->row($id), 'failed messages are never pruned');
    }

    public function test_a_message_claimed_by_another_relay_is_skipped_until_stale(): void
    {
        $runs = 0;
        $this->outbox->handle('test.effect', function () use (&$runs) {
            $runs++;
        });
        $id = $this->outbox->record('test.effect', []);
        DB::table('outbox_messages')->where('id', $id)->update(['status' => 'processing', 'claimed_at' => now()]);

        $this->assertSame(0, $this->outbox->relay());
        DB::table('outbox_messages')->where('id', $id)->update(['claimed_at' => now()->subMinutes(TransactionalOutbox::STALE_AFTER_MINUTES + 1)]);
        $this->assertSame(1, $this->outbox->relay());
        $this->assertSame(1, $runs);
    }

    public function test_a_type_without_handler_stays_pending(): void
    {
        $id = $this->outbox->record('test.unhandled', []);
        $this->assertSame(0, $this->outbox->relay());
        $this->assertSame('pending', $this->row($id)->status);
    }

    public function test_the_relay_command_and_job_perform_due_messages(): void
    {
        $runs = 0;
        $this->outbox->handle('test.effect', function () use (&$runs) {
            $runs++;
        });
        $this->outbox->record('test.effect', []);
        $this->artisan('payments:outbox-relay')->assertSuccessful();
        $this->outbox->record('test.effect', []);
        (new RelayOutboxJob)->handle($this->outbox);

        $this->assertSame(2, $runs);
    }

    /* ------------------------------------------------------------------ paid store order */

    private function settleOrder(): array
    {
        [$store, $order, $tx] = $this->makeOrderTransaction('stripe', 20.0, [], 'USD');
        $tx->update(['transaction_gatway' => 'stripe', 'payment_id' => 'pi_ob_1']);
        Settlement::credit($tx->fresh());

        return [$store, $order, $tx];
    }

    public function test_a_paid_order_records_its_side_effects_with_the_claim_and_performs_them_inline(): void
    {
        Bus::fake([SyncInvoiceJob::class]);
        [, $order, $tx] = $this->settleOrder();

        $this->assertSettledOnce($order, $tx);
        $messages = DB::table('outbox_messages')->where('type', 'like', 'ordering.order_paid.%')->orderBy('id')->get();
        $this->assertSame(OrderPaidSideEffects::TYPES, $messages->pluck('type')->all());
        $this->assertSame(['done'], $messages->pluck('status')->unique()->values()->all());
        $this->assertSame((int) $order->id, json_decode($messages[0]->payload, true)['order_id']);
        $this->assertSame('stripe', json_decode($messages[0]->payload, true)['gateway']);
        Bus::assertDispatched(SyncInvoiceJob::class, fn ($job) => $job->orderId === $order->id);

        // A replayed confirmation is a no-op: no second set of side effects.
        Settlement::credit($tx->fresh());
        $this->assertSame(4, DB::table('outbox_messages')->where('type', 'like', 'ordering.order_paid.%')->count());
        Bus::assertDispatchedTimes(SyncInvoiceJob::class, 1);
    }

    public function test_a_failing_side_effect_never_fails_the_payment_and_is_retried(): void
    {
        Bus::fake([SyncInvoiceJob::class]);
        $this->outbox->handle(OrderPaidSideEffects::SYNC_INVOICE, fn () => throw new \RuntimeException('queue down'));

        [, $order, $tx] = $this->settleOrder();
        $this->assertSettledOnce($order, $tx);
        $message = DB::table('outbox_messages')->where('type', OrderPaidSideEffects::SYNC_INVOICE)->first();
        $this->assertSame('pending', $message->status);
        Bus::assertNotDispatched(SyncInvoiceJob::class);

        // The queue is back; the scheduled relay performs the message once it is due.
        OrderPaidSideEffects::register($this->outbox);
        DB::table('outbox_messages')->where('id', $message->id)->update(['available_at' => now()->subMinute()]);
        $this->artisan('payments:outbox-relay')->assertSuccessful();
        $this->assertSame('done', $this->row($message->id)->status);
        Bus::assertDispatched(SyncInvoiceJob::class, fn ($job) => $job->orderId === $order->id);
    }
}
