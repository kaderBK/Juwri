<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use App\Models\SellerWithdrawal;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Juwri\Core\Models\PlatformAlert;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Mail\PaymentHealthAlertMail;
use Juwri\Modules\Payments\Models\LedgerAccount;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\PaymentLog;
use Juwri\Modules\Payments\Services\PaymentsWatch;
use Tests\TestCase;

/**
 * P6-93: payments:watch — one finding per rule, nothing on a healthy baseline, a cooldown so a lasting condition is
 * not re-sent every 5 minutes, and a store's finding only ever reaches that store's owner.
 */
class PaymentsWatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        Cache::flush();
        Mail::fake();
        $this->travelTo(now()->setTime(12, 30));
    }

    /** @return list<string> */
    private function watch(): array
    {
        return app(PaymentsWatch::class)->run();
    }

    private function alerts(): int
    {
        return PlatformAlert::where('kind', 'payments-watch')->count();
    }

    /** @param array<string, mixed> $attrs */
    private function tx(array $attrs, int $minutesAgo): Transaction
    {
        $this->travel(-$minutesAgo)->minutes();
        $t = Transaction::factory()->create($attrs);
        $this->travel($minutesAgo)->minutes();

        return $t;
    }

    public function test_healthy_baseline_raises_nothing(): void
    {
        $store = Store::factory()->create();
        $this->tx(['transaction_gatway' => 'stripe', 'status' => Transaction::STATUS_SUCCESS, 'store_id' => $store->id], 120);
        $this->tx(['transaction_gatway' => 'stripe', 'status' => null], 5); // fresh: still in flight
        $this->tx(['transaction_gatway' => 'wire_transfer', 'status' => Transaction::STATUS_PENDING], 600); // slow by design
        $this->tx(['transaction_gatway' => 'wire_transfer', 'status' => Transaction::STATUS_PENDING], 600);
        $this->tx(['transaction_gatway' => 'wire_transfer', 'status' => Transaction::STATUS_PENDING], 600);

        $this->assertSame([], $this->watch());
        $this->assertSame(0, $this->alerts());
        Mail::assertNothingQueued();
        $this->artisan('payments:watch')->expectsOutput('Payments healthy.')->assertSuccessful();
    }

    public function test_stuck_gateway_payments_alert_per_gateway_and_owner_of_that_store_only(): void
    {
        $a = Store::factory()->create();
        $b = Store::factory()->create();
        foreach (range(1, 3) as $i) {
            $this->tx(['transaction_gatway' => 'tap', 'status' => null, 'store_id' => $a->id, 'user_id' => null], 45);
        }
        $this->tx(['transaction_gatway' => 'tap', 'status' => null, 'store_id' => $b->id, 'user_id' => null], 45); // B: 1 < 3

        $messages = $this->watch();

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('4 tap payment(s) unconfirmed', $messages[0]);
        $this->assertSame(1, $this->alerts());
        Mail::assertQueued(PaymentHealthAlertMail::class, 1);
        Mail::assertQueued(PaymentHealthAlertMail::class, fn ($m) => $m->hasTo($a->user->email) && str_contains($m->lines[0][0], 'waiting for confirmation') && $m->lines[0][1]['count'] === 3);
        Mail::assertNotQueued(PaymentHealthAlertMail::class, fn ($m) => $m->hasTo($b->user->email));
    }

    public function test_below_threshold_or_too_young_is_quiet(): void
    {
        $this->tx(['transaction_gatway' => 'tap', 'status' => null], 45);
        $this->tx(['transaction_gatway' => 'tap', 'status' => null], 45);
        $this->tx(['transaction_gatway' => 'tap', 'status' => null], 10);

        $this->assertSame([], $this->watch());
    }

    public function test_paypal_recurring_pending_rows_do_not_trigger_stuck_alert(): void
    {
        foreach (range(1, 3) as $i) {
            $this->tx([
                'transaction_gatway' => 'paypal',
                'status' => Transaction::STATUS_RECURRING_PENDING,
                'payment_id' => 'I-PEND-'.$i,
            ], 240);
        }

        $this->assertSame([], $this->watch());
        $this->assertSame(0, $this->alerts());
    }

    public function test_cooldown_sends_once_then_again_after_it_expires(): void
    {
        foreach (range(1, 3) as $i) {
            $this->tx(['transaction_gatway' => 'moyasar', 'status' => null], 45);
        }
        $this->watch();
        $this->travel(5)->minutes();
        $this->watch();
        $this->travel(5)->minutes();
        $this->watch();
        $this->assertSame(1, PlatformAlert::count());

        // Next day (the dashboard row de-dupes per day) and past the cooldown: reminded again.
        PlatformAlert::query()->update(['resolved_at' => now()]);
        $this->travel(61)->minutes();
        $this->watch();
        $this->assertSame(2, PlatformAlert::count());
    }

    public function test_webhook_rejections_platform_rate_and_store_rate(): void
    {
        config(['payments.watch.webhook_rejections_per_hour' => 3, 'payments.watch.webhook_rejections_per_hour_store' => 2]);
        $a = Store::factory()->create();
        $b = Store::factory()->create();
        PaymentLog::webhookRejected('paypal', 'invalid store signature', null, ['store_id' => $a->id]);
        PaymentLog::webhookRejected('paypal', 'invalid store signature', null, ['store_id' => $a->id]);
        PaymentLog::webhookRejected('paypal', 'invalid store signature', null, ['store_id' => $b->id]);

        $messages = $this->watch();

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('3 webhook notification(s) rejected', $messages[0]);
        Mail::assertQueued(PaymentHealthAlertMail::class, 1);
        Mail::assertQueued(PaymentHealthAlertMail::class, fn ($m) => $m->hasTo($a->user->email) && $m->lines[0][1]['count'] === 2);
    }

    public function test_unknown_outcome_and_refused_refunds_surface_with_store_and_amount(): void
    {
        $a = Store::factory()->create();
        $b = Store::factory()->create();
        $details = fn (string $status, string $id, int $minutesAgo) => json_encode(['gateway_refunds' => [[
            'id' => $id, 'gateway' => 'stripe', 'amount_minor' => 1250, 'currency' => 'USD',
            'status' => $status, 'reference' => null, 'at' => now()->subMinutes($minutesAgo)->toIso8601String(),
        ]]]);
        $unknown = $this->tx(['transaction_gatway' => 'stripe', 'status' => Transaction::STATUS_SUCCESS, 'store_id' => $a->id, 'user_id' => null], 60);
        DB::table('transaction')->where('id', $unknown->id)->update(['details' => $details('pending', 'r1', 30)]);
        $refused = $this->tx(['transaction_gatway' => 'stripe', 'status' => Transaction::STATUS_SUCCESS, 'store_id' => $a->id, 'user_id' => null], 60);
        DB::table('transaction')->where('id', $refused->id)->update(['details' => $details('failed', 'r2', 10)]);
        $fresh = $this->tx(['transaction_gatway' => 'stripe', 'status' => Transaction::STATUS_SUCCESS, 'store_id' => $b->id, 'user_id' => null], 60);
        DB::table('transaction')->where('id', $fresh->id)->update(['details' => $details('pending', 'r3', 2)]); // in flight
        $old = $this->tx(['transaction_gatway' => 'stripe', 'status' => Transaction::STATUS_SUCCESS, 'store_id' => $b->id, 'user_id' => null], 600);
        DB::table('transaction')->where('id', $old->id)->update(['details' => $details('failed', 'r4', 500)]); // long ago

        $messages = $this->watch();

        $this->assertCount(2, $messages);
        $this->assertStringContainsString('12.50 USD', $messages[0]);
        $this->assertStringContainsString('UNKNOWN', $messages[0]);
        $this->assertStringContainsString('store '.$a->id, $messages[0]);
        $this->assertStringContainsString('refused', $messages[1]);
        Mail::assertQueued(PaymentHealthAlertMail::class, 1);
        Mail::assertQueued(PaymentHealthAlertMail::class, fn ($m) => $m->hasTo($a->user->email) && count($m->lines) === 2);
    }

    /** Lines come in transaction order, even when an older row was written last (PostgreSQL heap order differs). */
    public function test_refund_lines_keep_transaction_order_after_later_updates(): void
    {
        $store = Store::factory()->create();
        $details = fn (string $status, string $id, int $minutesAgo) => json_encode(['gateway_refunds' => [[
            'id' => $id, 'gateway' => 'stripe', 'amount_minor' => 500, 'currency' => 'USD',
            'status' => $status, 'reference' => null, 'at' => now()->subMinutes($minutesAgo)->toIso8601String(),
        ]]]);
        $first = $this->tx(['transaction_gatway' => 'stripe', 'status' => Transaction::STATUS_SUCCESS, 'store_id' => $store->id, 'user_id' => null], 60);
        $second = $this->tx(['transaction_gatway' => 'stripe', 'status' => Transaction::STATUS_SUCCESS, 'store_id' => $store->id, 'user_id' => null], 60);
        DB::table('transaction')->where('id', $second->id)->update(['details' => $details('failed', 's2', 10)]);
        DB::table('transaction')->where('id', $first->id)->update(['details' => $details('failed', 's1', 10)]);
        DB::table('transaction')->where('id', $first->id)->update(['details' => $details('failed', 's1', 10)]); // a later write of the older row

        $messages = $this->watch();

        $this->assertCount(2, $messages);
        $this->assertStringContainsString('transaction '.$first->id.' ', $messages[0]);
        $this->assertStringContainsString('transaction '.$second->id.' ', $messages[1]);
    }

    public function test_ledger_drift_alerts(): void
    {
        $account = LedgerAccount::factory()->create();
        DB::table('ledger_entries')->insert([
            'ledger_account_id' => $account->id, 'amount_minor' => 500, 'balance_after_minor' => 500,
            'currency_code' => 'USD', 'transaction_id' => 'tx-1', 'type' => 'credit', 'ref' => 'r1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $messages = $this->watch();

        $this->assertCount(1, $messages);
        $this->assertStringContainsString('ledger:reconcile found drift (1 issue', $messages[0]);
    }

    public function test_old_pending_payouts_alert(): void
    {
        $seller = User::factory()->create();
        $this->travel(-80)->hours();
        SellerWithdrawal::create(['user_id' => $seller->id, 'amount' => 10, 'status' => 'pending']);
        $this->travel(80)->hours();
        SellerWithdrawal::create(['user_id' => $seller->id, 'amount' => 10, 'status' => 'pending']);

        $messages = $this->watch();

        $this->assertSame(['payments: 1 seller withdrawal(s) pending for over 72 h'], $messages);
    }

    public function test_outbox_backlog_and_dead_messages_alert(): void
    {
        $row = fn (string $status, int $minutesAgo) => [
            'type' => 't', 'payload' => '{}', 'status' => $status, 'attempts' => 0,
            'available_at' => now()->subMinutes($minutesAgo), 'created_at' => now()->subMinutes($minutesAgo), 'updated_at' => now(),
        ];
        DB::table('outbox_messages')->insert([$row('pending', 2), $row('done', 300)]);
        $this->assertSame([], $this->watch());

        DB::table('outbox_messages')->insert([$row('pending', 40), $row('failed', 300)]);
        $messages = $this->watch();

        $this->assertCount(2, $messages);
        $this->assertStringContainsString('outbox relay backlog — 1 message(s)', $messages[0]);
        $this->assertStringContainsString('1 outbox message(s) failed', $messages[1]);
    }

    public function test_owner_mail_renders_the_lines(): void
    {
        $html = (new PaymentHealthAlertMail('Sam', 'Cafe A', [[':count payments on :gateway have been waiting for confirmation for over :minutes minutes.', ['count' => 3, 'gateway' => 'tap', 'minutes' => 30]]]))->render();

        // The test install's default language is Arabic: the line is translated with its placeholders filled.
        $this->assertStringContainsString('3 مدفوعات عبر tap بانتظار التأكيد منذ أكثر من 30 دقيقة.', $html);
        $this->assertStringContainsString('Cafe A', $html);
    }

    public function test_command_is_scheduled_every_five_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'payments:watch'));

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
