<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Notification;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Models\Dispute;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Disputes\DisputeService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GAPS-RUN-2 B11-P1: dispute webhook ingestion.
 */
class DisputeServiceTest extends TestCase
{
    use RefreshDatabase;

    private DisputeService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(Installed::class);
        $this->svc = new DisputeService;
    }

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function event(string $type, string $id, string $charge, array $over = []): array
    {
        return [
            'type' => $type,
            'data' => ['object' => array_merge([
                'id' => $id,
                'charge' => $charge,
                'amount' => 2500, // cents
                'currency' => 'usd',
                'status' => 'needs_response',
                'reason' => 'fraudulent',
                'created' => now()->timestamp,
                'evidence_details' => ['due_by' => now()->addDays(10)->timestamp],
            ], $over)],
        ];
    }

    public function test_created_upserts_dispute_resolves_tenant_and_alerts(): void
    {
        $r = $this->storeFixture();
        $order = Order::create(['store_id' => $r->id, 'status' => 'completed', 'type' => 'takeaway']);
        Transaction::create(['user_id' => $r->user_id, 'product_id' => $order->id, 'amount' => 25, 'currency_code' => 'USD', 'payment_id' => 'ch_ABC', 'transaction_gatway' => 'stripe']);

        $d = $this->svc->upsertFromStripe($this->event('charge.dispute.created', 'dp_1', 'ch_ABC'));

        $this->assertSame($r->id, $d->store_id);
        $this->assertSame($order->id, $d->order_id);
        $this->assertEqualsWithDelta(25.0, (float) $d->amount, 0.001);
        $this->assertSame('needs_response', $d->status);
        $this->assertNotNull($d->evidence_due_at);
        $this->assertSame(1, Notification::where('type', 'dispute')->count());
        // status is a 0/1 boolean column — the alert must land UNREAD (0), not a
        // truthy string that casts to "read".
        $this->assertFalse((bool) Notification::where('type', 'dispute')->value('status'));
    }

    public function test_upsert_is_idempotent_and_updates_status(): void
    {
        $r = $this->storeFixture();
        Transaction::create(['user_id' => $r->user_id, 'product_id' => 0, 'amount' => 25, 'currency_code' => 'USD', 'payment_id' => 'ch_X', 'transaction_gatway' => 'stripe']);

        $this->svc->upsertFromStripe($this->event('charge.dispute.created', 'dp_2', 'ch_X'));
        $this->svc->upsertFromStripe($this->event('charge.dispute.closed', 'dp_2', 'ch_X', ['status' => 'lost']));

        $this->assertSame(1, Dispute::where('external_id', 'dp_2')->count());
        $d = Dispute::where('external_id', 'dp_2')->first();
        $this->assertSame('lost', $d->status);
        $this->assertNotNull($d->closed_at);
        // Alert fires only on the first `created`, not on replay/close.
        $this->assertSame(1, Notification::where('type', 'dispute')->count());
    }

    public function test_unresolved_tenant_still_records(): void
    {
        $d = $this->svc->upsertFromStripe($this->event('charge.dispute.created', 'dp_3', 'ch_UNKNOWN'));
        $this->assertNotNull($d);
        $this->assertNull($d->store_id);
    }
}
