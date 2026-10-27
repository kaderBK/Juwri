<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\Order;
use App\Services\DashboardAttentionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Messaging\Contracts\MessageQueue;
use Juwri\Modules\Ordering\Services\Payments\PendingPaymentCancelledNotice;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\TestCase;

/**
 * P3-06b (API-21, API-27, API-28, API-29, API-32): the owner side of late payments — the dashboard list with accept
 * and refund, the "needs attention" rows (waiting decisions, card holds lapsing within a day), tenant isolation of the
 * actions, and the per-store "message when a customer cancels a pending payment" switch (off by default).
 */
class LatePaymentsOwnerTest extends TestCase
{
    use RefreshDatabase;

    private ApiFixtures $fx;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'd.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'f.png'])
        {
            public function __construct(private array $d) {}

            public function __get($k): mixed
            {
                return $this->d[$k] ?? null;
            }

            public function __set($k, $v): void
            {
                $this->d[$k] = $v;
            }

            public function __isset($k): bool
            {
                return array_key_exists($k, $this->d);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect()]);
        $this->fx = new ApiFixtures;
    }

    /** @param  array<string, mixed>  $attributes */
    private function late(Store $store, string $track, array $attributes = []): Order
    {
        return $this->fx->order($store, $track, array_merge([
            'payment_mode' => 'hosted', 'status' => 'cancelled', 'cancelled_at' => now(), 'is_paid' => 0,
            'payment_expires_at' => now()->subMinutes(30), 'late_payment_status' => 'received_late', 'late_payment_minor' => 7644,
            'late_payment_at' => now()->subMinutes(5),
        ], $attributes));
    }

    public function test_the_owner_lists_accepts_and_refunds_late_payments_of_their_own_store_only(): void
    {
        $store = $this->fx->freshMart();
        $owner = User::query()->findOrFail($store->user_id);
        $other = $this->fx->store($this->fx->owner(), 'other-mart', 'Other Mart', 'grocery', ['restaurant_delivery_charge' => '0']);
        $mine = $this->late($store, 'trk_late_mine_000000000000001');
        $refundMe = $this->late($store, 'trk_late_mine_000000000000002');
        $theirs = $this->late($other, 'trk_late_theirs_00000000000001');
        $this->withoutMiddleware();

        $page = $this->actingAs($owner)->get(route('stores.latePayments.index').'?store_id='.$store->id)->assertOk();
        $page->assertSee('data-late-payment="'.$mine->id.'"', false);
        $page->assertDontSee('data-late-payment="'.$theirs->id.'"', false);
        $page->assertSee(route('stores.latePayments.accept', $mine->id), false);
        $this->actingAs($owner)->get(route('stores.latePayments.index').'?store_id='.$other->id)->assertForbidden();

        // another store's order: 404, untouched
        $this->actingAs($owner)->post(route('stores.latePayments.accept', $theirs->id))->assertNotFound();
        $this->actingAs($owner)->post(route('stores.latePayments.refund', $theirs->id))->assertNotFound();
        $this->assertSame('received_late', $theirs->fresh()->late_payment_status);

        // accept: an ASAP order with free stock goes ahead
        $this->actingAs($owner)->from(route('stores.latePayments.index'))->post(route('stores.latePayments.accept', $mine->id))
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame('accepted', $mine->fresh()->late_payment_status);
        $this->assertNotSame('cancelled', $mine->fresh()->status);
        // a second accept is refused (no longer waiting)
        $this->actingAs($owner)->post(route('stores.latePayments.accept', $mine->id))->assertSessionHas('error');

        // refund (the D7 refund switches do not apply)
        StoreSettings::set((int) $store->id, 'auto_refunds_enabled', false);
        $this->actingAs($owner)->post(route('stores.latePayments.refund', $refundMe->id))->assertSessionHas('success');
        $this->assertSame('refunded', $refundMe->fresh()->late_payment_status);
        $this->assertSame('cancelled', $refundMe->fresh()->status);
    }

    public function test_attention_rows_show_waiting_late_payments_and_card_holds_lapsing_within_a_day(): void
    {
        $store = $this->fx->freshMart();
        $this->assertSame([], array_values(array_filter(DashboardAttentionService::for([$store->id]), fn ($r) => str_starts_with($r['type'], 'late_'))));

        $this->late($store, 'trk_late_att_0000000000000001');
        $this->late($store, 'trk_late_att_0000000000000002', [
            'status' => Order::STATUS_PENDING_APPROVAL, 'late_payment_status' => 'accepted', 'is_paid' => 1, 'cancelled_at' => null,
            'authorisation_expires_at' => Carbon::now()->addHours(6),
        ]);
        // decided ones do not count
        $this->late($store, 'trk_late_att_0000000000000003', ['late_payment_status' => 'refunded']);

        $rows = collect(DashboardAttentionService::for([$store->id]))->keyBy('type');
        $this->assertSame(2, $rows['late_payments']['count']);
        $this->assertSame('danger', $rows['late_payments']['severity']);
        $this->assertStringContainsString(route('stores.latePayments.index'), $rows['late_payments']['url']);
        $this->assertSame(1, $rows['late_holds']['count']);
        $this->assertSame(1, DashboardAttentionService::countsByStore([$store->id])[$store->id] ?? 0);

        $other = $this->fx->store($this->fx->owner(), 'other-mart', 'Other Mart', 'grocery', ['restaurant_delivery_charge' => '0']);
        $this->assertArrayNotHasKey('late_payments', collect(DashboardAttentionService::for([$other->id]))->keyBy('type')->all());
    }

    public function test_the_pending_payment_cancelled_message_is_off_by_default(): void
    {
        $store = $this->fx->freshMart();
        $order = $this->fx->order($store, 'trk_notice_000000000000000001', ['phone_number' => '+966551234567', 'status' => 'unpaid']);
        $sent = [];
        $this->mock(MessageQueue::class, function ($mock) use (&$sent) {
            $mock->shouldReceive('sms')->andReturnUsing(function (string $to, string $message, ?int $storeId = null, string $feature = 'general', bool $marketing = false) use (&$sent) {
                $sent[] = [$to, $message, $storeId, $marketing];
            });
        });

        $this->assertFalse(app(PendingPaymentCancelledNotice::class)->send($order));
        $this->assertSame([], $sent);

        StoreSettings::set((int) $store->id, 'notify_customer_pending_payment_cancelled', true);
        $this->assertTrue(app(PendingPaymentCancelledNotice::class)->send($order));
        $this->assertCount(1, $sent);
        $this->assertSame('+966551234567', $sent[0][0]);
        $this->assertStringContainsString('#'.$order->id, $sent[0][1]);
        $this->assertFalse($sent[0][3], 'transactional, not marketing');
    }
}
