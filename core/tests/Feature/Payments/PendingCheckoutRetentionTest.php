<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\TestCase;

/**
 * Regression for old-platform 7a58a1892 (covered on Juwri by BL-038): the hourly cleanup (cron:hourly) must keep the
 * checkout rows a late Paddle / Lemon Squeezy webhook still needs, and only purge the truly stale ones.
 */
class PendingCheckoutRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-19 09:00:00');
        // A stale Paddle checkout is closed at the gateway first: refuse every call, nothing leaves the test.
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['error' => 'refused'], 500)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $attributes */
    private function checkout(array $attributes = []): Transaction
    {
        return Transaction::factory()->pending()->create($attributes);
    }

    private function runCleanupAfter(int $hours): void
    {
        Carbon::setTestNow(now()->addHours($hours));
        $this->artisan('cron:hourly')->assertExitCode(0);
    }

    public function test_settled_merchant_of_record_checkouts_are_never_purged(): void
    {
        $recurring = $this->checkout(['status' => Transaction::STATUS_RECURRING_SETUP, 'details' => ['checkout_gateway' => 'paddle']]);
        $oneTime = $this->checkout(['status' => Transaction::STATUS_CHECKOUT_SETTLED, 'details' => ['checkout_gateway' => 'lemon_squeezy']]);

        $this->runCleanupAfter(24 * 60);

        $this->assertNotNull($recurring->fresh(), 'renewals are bound to the settled checkout row');
        $this->assertNotNull($oneTime->fresh());
    }

    public function test_legacy_gateway_checkouts_without_checkout_gateway_are_kept_for_late_webhooks(): void
    {
        $paddle = $this->checkout(['details' => ['paddle_transaction' => 'txn_legacy']]);
        $lemon = $this->checkout(['details' => ['lemon_expires_at' => now()->addDays(2)->toIso8601ZuluString()]]);
        $plain = $this->checkout();

        $this->runCleanupAfter(5);

        $this->assertNotNull($paddle->fresh(), 'a Paddle checkout may still be paid after 3 hours');
        $this->assertNotNull($lemon->fresh(), 'a Lemon Squeezy checkout may still be paid after 3 hours');
        $this->assertNull($plain->fresh(), 'a checkout that never reached a gateway is purged after 3 hours');

        $this->runCleanupAfter(24 * 8);

        $this->assertNull($paddle->fresh(), 'past the 7-day keep window an unsettled checkout goes');
        $this->assertNull($lemon->fresh());
    }

    public function test_abandoned_and_recurring_pending_checkouts_go_only_after_the_keep_window(): void
    {
        $abandoned = $this->checkout(['status' => Transaction::STATUS_ABANDONED, 'details' => ['checkout_gateway' => 'lemon_squeezy']]);
        $paypal = $this->checkout(['status' => Transaction::STATUS_RECURRING_PENDING, 'payment_id' => 'I-PENDING']);

        $this->runCleanupAfter(24 * 6);
        $this->assertNotNull($abandoned->fresh(), 'within the week: a retried webhook still finds it');
        $this->assertNotNull($paypal->fresh());

        $this->runCleanupAfter(24 * 2);
        $this->assertNull($abandoned->fresh());
        $this->assertNull($paypal->fresh());
    }

    public function test_a_checkout_left_for_a_person_is_never_purged(): void
    {
        $gaveUp = $this->checkout(['details' => ['checkout_gateway' => 'paddle', 'paddle_transaction' => 'txn_stuck', 'close_gave_up' => true]]);

        $this->runCleanupAfter(24 * 30);

        $this->assertNotNull($gaveUp->fresh());
        $this->assertNull($gaveUp->fresh()->status);
    }
}
