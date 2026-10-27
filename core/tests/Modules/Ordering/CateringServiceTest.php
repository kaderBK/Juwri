<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\CateringRequest;
use App\Models\StoreOption;
use App\Services\Catering\CateringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Jobs\SendStaffOrderPushJob;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\LedgerService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GAPS-RUN-2 B8-P1: catering engine.
 */
class CateringServiceTest extends TestCase
{
    use RefreshDatabase;

    private CateringService $svc;

    private Store $r;

    private Menu $menu;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.admin_allow_catering' => true]);
        $this->svc = new CateringService;
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $this->r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($this->r->id, 'catering_enabled', '1');
        StoreOption::updateStoreOption($this->r->id, 'currency_code', 'USD');
        $this->menu = Menu::create(['store_id' => $this->r->id, 'name' => 'Platter', 'price' => 50, 'active' => 1]);
    }

    private function request(array $override = []): CateringRequest
    {
        return $this->svc->createRequest($this->r->id, array_merge([
            'name' => 'Sam', 'phone' => '123', 'event_at' => now()->addDays(30)->toDateTimeString(), 'headcount' => 20,
        ], $override));
    }

    public function test_lead_time_rejected(): void
    {
        StoreOption::updateStoreOption($this->r->id, 'catering_min_lead_days', '7');
        $this->expectException(ValidationException::class);
        $this->request(['event_at' => now()->addDays(2)->toDateTimeString()]);
    }

    public function test_request_created_within_lead_time(): void
    {
        StoreOption::updateStoreOption($this->r->id, 'catering_min_lead_days', '7');
        $req = $this->request(['event_at' => now()->addDays(10)->toDateTimeString()]);
        $this->assertSame('requested', $req->status);
        $this->assertSame(20, $req->headcount);
    }

    public function test_accept_enforces_min_spend(): void
    {
        StoreOption::updateStoreOption($this->r->id, 'catering_min_spend', '500');
        $req = $this->svc->quote($this->request(), 200.0); // below 500

        $this->expectException(ValidationException::class);
        $this->svc->accept($req, [['item_id' => $this->menu->id, 'quantity' => 4]]);
    }

    public function test_accept_creates_scheduled_catering_order(): void
    {
        Bus::fake();
        StoreOption::updateStoreOption($this->r->id, 'allow_order_scheduling', '1');
        $req = $this->svc->quote($this->request(), 500.0);

        $order = $this->svc->accept($req, [['item_id' => $this->menu->id, 'quantity' => 4]]);

        $this->assertTrue((bool) $order->is_catering);
        $this->assertNotNull($order->scheduled_at);
        $this->assertTrue($order->isPendingSchedule(), 'a future catering order stays pending-schedule');
        // It must NOT fire the kitchen the moment it is accepted (event is weeks out).
        Bus::assertNotDispatched(SendStaffOrderPushJob::class);
        $this->assertSame('accepted', $req->fresh()->status);
        $this->assertSame($order->id, $req->fresh()->order_id);
    }

    public function test_deposit_is_idempotent(): void
    {
        StoreOption::updateStoreOption($this->r->id, 'catering_deposit_enabled', '1');
        StoreOption::updateStoreOption($this->r->id, 'catering_deposit_amount', '100');
        $req = $this->request();

        $tx = $this->svc->initiateDeposit($req, 'stripe');
        $this->assertInstanceOf(Transaction::class, $tx);

        $this->svc->markDepositPaid($req->fresh(), $tx);
        $this->svc->markDepositPaid($req->fresh(), $tx); // replay

        $this->assertSame('paid', $req->fresh()->deposit_status);
        $ownerId = $this->r->user_id;
        $merchant = LedgerService::accountFor('merchant', $ownerId, 'merchant', 'USD');
        $this->assertEqualsWithDelta(100.0, LedgerService::balanceFor($merchant), 0.001);
    }

    public function test_decline(): void
    {
        $req = $this->request();
        $this->svc->decline($req);
        $this->assertSame('declined', $req->fresh()->status);
    }
}
