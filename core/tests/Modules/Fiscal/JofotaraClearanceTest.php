<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Fiscal\Jobs\SubmitFiscalDocumentJob;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\FiscalChain;
use Juwri\Modules\Fiscal\Services\FiscalDriverManager;
use Juwri\Modules\Fiscal\Services\FiscalRegistry;
use Juwri\Modules\Fiscal\Services\Jofotara\Contracts\JofotaraClient;
use Juwri\Modules\Fiscal\Services\Jofotara\FakeJofotaraClient;
use Juwri\Modules\Fiscal\Services\Jofotara\JofotaraDocumentService;
use Juwri\Modules\Ordering\Services\OrderFiscalContext;
use Tests\Support\TestPlans;
use Tests\TestCase;

class JofotaraClearanceTest extends TestCase
{
    use RefreshDatabase;

    private FakeJofotaraClient $fake;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_fiscalization' => true, 'settings.admin_allow_fiscal_jo' => true]);
        $this->fake = new FakeJofotaraClient;
        $this->app->instance(JofotaraClient::class, $this->fake);
    }

    private function storeFixture(): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999])]);
        $u = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create(['user_id' => $u->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Amman Grill', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
        StoreOption::updateStoreOption($r->id, 'fiscal_country', 'JO');
        StoreOption::updateStoreOption($r->id, 'fiscal_vat_number', '12345678');
        StoreOption::updateStoreOption($r->id, 'fiscal_legal_name', 'Amman Grill');
        Credential::create(['store_id' => $r->id, 'feature' => 'jofotara', 'provider' => 'jofotara', 'secrets' => ['client_id' => 'c', 'secret' => 's', 'activity_number' => 'a'], 'active' => true]);

        return $r;
    }

    public function test_clearance_returns_istd_qr_and_receipt_renders_it(): void
    {
        $r = $this->storeFixture();
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new']);

        $doc = new JofotaraDocumentService()->issue($r, OrderFiscalContext::for($r, $order));

        $this->assertSame('accepted', $doc->status);
        $this->assertStringStartsWith('ISTD-QR:', $doc->authority_response['istd_qr']);

        // Receipt now renders the ISTD QR (final copy).
        $html = View::make('fiscal.receipt-block', ['store' => $r, 'order' => $order])->render();
        $this->assertStringContainsString('<svg', $html);
    }

    public function test_no_qr_means_no_final_receipt_qr(): void
    {
        $r = $this->storeFixture();
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new']);

        // A draft/queued doc with no ISTD QR yet → receipt shows no QR.
        FiscalChain::issue($r->id, 'default', ['country' => 'JO', 'type' => 'simplified', 'order_id' => $order->id, 'total' => 116, 'tax_total' => 16, 'currency' => 'JOD']);

        $ctx = OrderFiscalContext::for($r, $order);
        $this->assertNull(FiscalRegistry::profile('JO')->qr($ctx));
    }

    public function test_outage_leaves_document_queued_provisional(): void
    {
        $r = $this->storeFixture();
        $this->fake->unreachable = true;
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new']);

        // The job throws on outage; simulate the queue swallowing it (sync driver).
        try {
            new JofotaraDocumentService()->issue($r, OrderFiscalContext::for($r, $order));
        } catch (\Throwable $e) {
            // expected under sync
        }

        $doc = FiscalDocument::where('store_id', $r->id)->first();
        $this->assertNotNull($doc);
        $this->assertContains($doc->status, ['queued', 'submitted']);
        $this->assertArrayNotHasKey('istd_qr', $doc->authority_response ?? []);
    }

    public function test_rejection_then_resubmit(): void
    {
        $r = $this->storeFixture();
        $this->fake->rejectNext = true;
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new']);

        $doc = new JofotaraDocumentService()->issue($r, OrderFiscalContext::for($r, $order));
        $this->assertSame('rejected', $doc->status);

        $correction = FiscalChain::correct($doc);
        $correction->update(['authority_response' => ['envelope' => ['invoiceNumber' => $correction->number, 'uuid' => $correction->uuid]]]);
        $correction->transitionTo('queued', ['queued_at' => now()]);
        new SubmitFiscalDocumentJob($correction->id)->handle(app(FiscalDriverManager::class));
        $this->assertSame('accepted', $correction->fresh()->status);
    }

    public function test_credentials_are_vaulted_encrypted(): void
    {
        $r = $this->storeFixture();
        $raw = DB::table('credentials')->where('store_id', $r->id)->value('secrets');
        $this->assertStringNotContainsString('client_id', $raw); // encrypted at rest
        $this->assertSame('c', Credential::for('jofotara', $r->id)['client_id']);
    }
}
