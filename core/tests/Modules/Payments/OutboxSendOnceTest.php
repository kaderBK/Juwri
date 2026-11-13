<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Models\GiftCard;
use App\Models\Order;
use App\Services\GiftCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Loyalty\Mail\GiftCardMail;
use Juwri\Modules\Payments\Contracts\Outbox;
use Juwri\Modules\Payments\Contracts\StoreCredits;
use Juwri\Modules\Payments\Services\Webhooks\SendOnce;
use Juwri\Modules\Pos\Services\Printing\PaidOrderPrint;
use Tests\TestCase;

/**
 * P6-158: outbox email / print side effects are at-most-once across a kill. The marker is written before the send,
 * outside the handler transaction; a re-run after "kill after send, before ack" sends nothing.
 */
class OutboxSendOnceTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_once_runs_the_send_once_and_a_clean_failure_releases_the_claim(): void
    {
        $sends = 0;
        $this->assertTrue(SendOnce::run('t.x', 'k1', function () use (&$sends) {
            $sends++;
        }));
        $this->assertFalse(SendOnce::run('t.x', 'k1', function () use (&$sends) {
            $sends++;
        }));
        $this->assertSame(1, $sends);
        $this->assertSame('sent', SendOnce::state('t.x', 'k1'));

        try {
            SendOnce::run('t.x', 'k2', fn () => throw new \RuntimeException('smtp down'));
            $this->fail('expected the send error to surface');
        } catch (\RuntimeException) {
        }
        $this->assertNull(SendOnce::state('t.x', 'k2'), 'a clean failure frees the claim');
        $this->assertTrue(SendOnce::run('t.x', 'k2', function () use (&$sends) {
            $sends++;
        }));
        $this->assertSame(2, $sends);
    }

    public function test_the_outbox_contract_send_once_is_the_same_marker_for_modules_above_payments(): void
    {
        $outbox = app(Outbox::class);
        $sends = 0;
        $this->assertTrue($outbox->sendOnce('drill.print', 'o1:paid', function () use (&$sends): void {
            $sends++;
        }));
        $this->assertFalse($outbox->sendOnce('drill.print', 'o1:paid', function () use (&$sends): void {
            $sends++;
        }), 'second call for the same key sends nothing');
        $this->assertSame(1, $sends);
        $this->assertSame('sent', SendOnce::state('drill.print', 'o1:paid'));

        try {
            $outbox->sendOnce('drill.print', 'o2:paid', function (): void {
                throw new \RuntimeException('printer offline');
            });
            $this->fail('the send exception is rethrown');
        } catch (\RuntimeException) {
        }
        $this->assertNull(SendOnce::state('drill.print', 'o2:paid'), 'a clean failure frees the claim for the retry');
    }

    public function test_send_once_handler_runs_outside_the_handler_transaction_and_a_kill_after_send_resends_nothing(): void
    {
        $outbox = app(Outbox::class);
        $sends = 0;
        $levels = [];
        $killAfterSend = true;
        $outbox->handle('p6158.mail', function (array $p) use (&$sends, &$killAfterSend, &$levels) {
            $levels[] = DB::transactionLevel();
            SendOnce::run('p6158.mail', (string) $p['id'], function () use (&$sends) {
                $sends++;
            });
            if ($killAfterSend) {
                $killAfterSend = false;
                throw new \RuntimeException('killed after the send, before the ack');
            }
        }, sendOnce: true);
        $id = (int) $outbox->record('p6158.mail', ['id' => 5], 'p6158:5');

        $outbox->relay([$id]);
        $this->assertSame(1, $sends);
        $this->assertSame('pending', $outbox->status($id), 'not acknowledged');
        $this->assertSame('sent', SendOnce::state('p6158.mail', '5'), 'the marker survived the unacknowledged run');

        DB::table('outbox_messages')->where('id', $id)->update(['available_at' => now()->subMinute()]);
        $outbox->relay([$id]);
        $outbox->relay([$id]);
        $this->assertSame(1, $sends, 're-run sends nothing');
        $this->assertSame('done', $outbox->status($id));
        // RefreshDatabase holds one outer transaction; a wrapped handler would sit one level deeper than that.
        $this->assertSame($levels[0], $levels[1]);
        $this->assertSame(DB::transactionLevel(), $levels[0], 'the handler was not wrapped in its own transaction');
    }

    public function test_gift_card_kill_after_mail_before_delivered_at_does_not_email_twice(): void
    {
        Mail::fake();
        $card = $this->card();

        // Kill: the mail went out and the marker is written, but delivered_at was never saved.
        $this->assertTrue(GiftCardService::deliver($card));
        GiftCard::whereKey($card->id)->update(['delivered_at' => null]);

        $this->assertFalse(GiftCardService::deliver($card->fresh()), 're-run (outbox or tick) skips the send');
        $this->assertFalse(GiftCardService::deliver($card->fresh()));
        $this->assertSame(1, Mail::sent(GiftCardMail::class)->count() + Mail::queued(GiftCardMail::class)->count());
        $this->assertNotNull($card->fresh()->delivered_at, 'bookkeeping is repaired from the sent marker');
    }

    public function test_gift_card_failed_mail_is_retried_not_marked_sent(): void
    {
        $card = $this->card();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp down'));
        $this->assertFalse(GiftCardService::deliver($card));
        $this->assertNull(SendOnce::state(StoreCredits::GIFT_CARD_DELIVERY, (string) $card->id));
        $this->assertNull($card->fresh()->delivered_at);
    }

    public function test_gift_card_killed_mid_send_is_not_resent_and_stays_visible(): void
    {
        Mail::fake();
        $card = $this->card();
        SendOnce::claim(StoreCredits::GIFT_CARD_DELIVERY, (string) $card->id); // killed between claim and confirm

        $this->assertFalse(GiftCardService::deliver($card));
        Mail::assertNothingSent();
        $this->assertSame('sending', SendOnce::state(StoreCredits::GIFT_CARD_DELIVERY, (string) $card->id));
    }

    public function test_paid_order_print_message_is_marked_and_a_redelivered_message_prints_nothing_more(): void
    {
        $user = User::factory()->create();
        $store = Store::create(['user_id' => $user->id, 'slug' => 'p'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'R',
            'sub_title' => 'x', 'description' => 'x', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1']);
        $order = Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway']);
        $outbox = app(Outbox::class);
        $id = (int) $outbox->record(PaidOrderPrint::PRINT, ['order_id' => $order->id, 'transition' => 'T1'], 'p6158:print:'.$order->id);

        $outbox->relay([$id]);
        $this->assertSame('done', $outbox->status($id));
        $this->assertSame('sent', SendOnce::state(PaidOrderPrint::PRINT, $order->id.':T1'));

        // Kill after the print, before the ack: the message comes back pending; the marker stops a second print.
        DB::table('outbox_messages')->where('id', $id)->update(['status' => 'pending', 'available_at' => now()->subMinute()]);
        $before = DB::table('print_jobs')->count();
        $outbox->relay([$id]);
        $this->assertSame('done', $outbox->status($id));
        $this->assertSame($before, DB::table('print_jobs')->count());
        $this->assertSame(1, DB::table(SendOnce::TABLE)->where('message_type', PaidOrderPrint::PRINT)->count());
    }

    private function card(): GiftCard
    {
        $user = User::factory()->create();
        $store = Store::create(['user_id' => $user->id, 'slug' => 'g'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'R',
            'sub_title' => 'x', 'description' => 'x', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1']);

        return GiftCard::create(['store_id' => $store->id, 'code' => 'GC-'.Str::upper(Str::random(6)), 'currency' => 'USD',
            'initial_value' => 10, 'status' => 'active', 'recipient_email' => 'friend@example.test']);
    }

    /** P6-158 review: a marker a kill left in `sending` is visible to the operator and can be released for re-issue. */
    public function test_operator_lists_and_releases_a_stuck_marker_but_never_a_sent_one(): void
    {
        $this->assertTrue(SendOnce::claim('t.stuck', 'card-9')); // a kill right after the send: stays `sending`
        SendOnce::run('t.done', 'card-1', fn () => null);
        $stuck = (int) DB::table(SendOnce::TABLE)->where('entity_key', 'card-9')->value('id');
        $sent = (int) DB::table(SendOnce::TABLE)->where('entity_key', 'card-1')->value('id');

        $this->artisan('payments:send-markers')
            ->expectsOutputToContain('card-9')
            ->doesntExpectOutputToContain('card-1')
            ->assertExitCode(0);

        $this->artisan('payments:send-markers', ['--release' => (string) $sent])->assertExitCode(1);
        $this->assertSame('sent', SendOnce::state('t.done', 'card-1'));

        $this->artisan('payments:send-markers', ['--release' => (string) $stuck])->assertExitCode(0);
        $this->assertNull(SendOnce::state('t.stuck', 'card-9'));
        $sends = 0;
        $this->assertTrue(SendOnce::run('t.stuck', 'card-9', function () use (&$sends) {
            $sends++;
        }), 'released: the re-issue sends');
        $this->assertSame(1, $sends);

        $this->artisan('payments:send-markers')->expectsOutputToContain('no stuck send markers')->assertExitCode(0);
    }

    /** P6-158 review: confirmed markers follow the outbox retention; stuck ones stay for the operator. */
    public function test_prune_drops_old_sent_markers_and_keeps_stuck_ones(): void
    {
        SendOnce::run('t.p', 'old-sent', fn () => null);
        SendOnce::run('t.p', 'new-sent', fn () => null);
        SendOnce::claim('t.p', 'old-stuck');
        DB::table(SendOnce::TABLE)->whereIn('entity_key', ['old-sent', 'old-stuck'])->update(['updated_at' => now()->subDays(40)]);

        $this->artisan('payments:prune-webhooks')->assertExitCode(0);

        $this->assertNull(SendOnce::state('t.p', 'old-sent'));
        $this->assertSame('sent', SendOnce::state('t.p', 'new-sent'));
        $this->assertSame('sending', SendOnce::state('t.p', 'old-stuck'));
    }
}
