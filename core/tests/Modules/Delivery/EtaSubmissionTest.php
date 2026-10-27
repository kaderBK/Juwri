<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use App\Http\Middleware\Installed;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Fiscal\Services\Eta\Contracts\EtaClient;
use Juwri\Modules\Fiscal\Services\Eta\EtaDocumentService;
use Juwri\Modules\Fiscal\Services\Eta\FakeEtaClient;
use Juwri\Modules\Ordering\Services\OrderFiscalContext;
use Tests\Support\TestPlans;
use Tests\TestCase;

class EtaSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private FakeEtaClient $fake;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_fiscalization' => true, 'settings.admin_allow_fiscal_eg' => true]);
        $this->fake = new FakeEtaClient;
        $this->app->instance(EtaClient::class, $this->fake);
    }

    private function storeFixture(): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999])]);
        $u = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create(['user_id' => $u->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Cairo Eats', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
        StoreOption::updateStoreOption($r->id, 'fiscal_country', 'EG');
        StoreOption::updateStoreOption($r->id, 'fiscal_legal_name', 'Cairo Eats');
        Credential::create(['store_id' => $r->id, 'feature' => 'eta', 'provider' => 'eta', 'secrets' => ['rin' => '123456789', 'client_id' => 'cid', 'secret' => 'sec'], 'active' => true]);

        return $r;
    }

    private function device(): array
    {
        return ['rin' => '123456789', 'branchCode' => '0'];
    }

    public function test_receipt_submits_and_stores_lookup_url_qr(): void
    {
        $r = $this->storeFixture();
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new']);

        $doc = new EtaDocumentService()->issue($r, OrderFiscalContext::for($r, $order), 'POS-1', $this->device());

        $this->assertSame('accepted', $doc->status);
        $this->assertStringContainsString('/share', $doc->authority_response['lookup_url']);
        $this->assertContains('authenticatePos', $this->fake->calls);

        $html = View::make('fiscal.receipt-block', ['store' => $r, 'order' => $order])->render();
        $this->assertStringContainsString('<svg', $html); // lookup-URL QR
    }

    public function test_two_devices_chain_independently(): void
    {
        $r = $this->storeFixture();
        $svc = new EtaDocumentService;
        $o1 = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new']);
        $o2 = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new']);
        $o3 = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new']);

        $a1 = $svc->issue($r, OrderFiscalContext::for($r, $o1), 'POS-A', $this->device());
        $b1 = $svc->issue($r, OrderFiscalContext::for($r, $o2), 'POS-B', $this->device());
        $a2 = $svc->issue($r, OrderFiscalContext::for($r, $o3), 'POS-A', $this->device());

        $this->assertSame('', $a1->authority_response['receipt']['header']['previousUUID']);
        $this->assertSame('', $b1->authority_response['receipt']['header']['previousUUID']);
        $this->assertSame($a1->uuid, $a2->authority_response['receipt']['header']['previousUUID']);
    }

    public function test_rejection_then_reference_old_uuid_resubmit(): void
    {
        $r = $this->storeFixture();
        $this->fake->rejectNext = true;
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new']);
        $doc = new EtaDocumentService()->issue($r, OrderFiscalContext::for($r, $order), 'POS-1', $this->device());
        $this->assertSame('rejected', $doc->status);

        // Return/correction referencing the old uuid.
        $order2 = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new']);
        $corr = new EtaDocumentService()->issue($r, OrderFiscalContext::for($r, $order2), 'POS-1', $this->device(), true, $doc->uuid);
        $this->assertSame('accepted', $corr->status);
        $this->assertSame($doc->uuid, $corr->authority_response['receipt']['header']['referenceOldUUID']);
    }

    public function test_async_callback_flips_status(): void
    {
        $r = $this->storeFixture();
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new']);
        $doc = new EtaDocumentService()->issue($r, OrderFiscalContext::for($r, $order), 'POS-1', $this->device());
        // Force a non-terminal state to observe the callback effect.
        $doc->update(['status' => 'submitted']);

        $this->withoutMiddleware(Installed::class);

        // The public callback requires a per-tenant secret in the URL.
        Credential::create(['feature' => 'eta_webhook', 'store_id' => $r->id, 'provider' => 'eta', 'secrets' => ['token' => 'etatok'], 'active' => true]);

        // Unknown token → 404, no state change.
        $this->postJson(route('webhook.fiscal.eta', 'wrong'), ['uuid' => $doc->uuid, 'status' => 'invalid'])->assertNotFound();
        $this->assertSame('submitted', $doc->fresh()->status);

        // Valid token → flips the document.
        $this->postJson(route('webhook.fiscal.eta', 'etatok'), ['uuid' => $doc->uuid, 'status' => 'invalid'])->assertOk();
        $this->assertSame('rejected', $doc->fresh()->status);
        $this->assertSame('Invalid', $doc->fresh()->authority_response['eta_status']);
    }

    public function test_token_lifecycle_reauthenticates_per_submission(): void
    {
        $r = $this->storeFixture();
        $o1 = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new']);
        $o2 = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new']);
        new EtaDocumentService()->issue($r, OrderFiscalContext::for($r, $o1), 'POS-1', $this->device());
        new EtaDocumentService()->issue($r, OrderFiscalContext::for($r, $o2), 'POS-1', $this->device());
        $this->assertGreaterThanOrEqual(2, $this->fake->authCount);
    }
}
