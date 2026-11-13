<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use Juwri\Modules\Fiscal\Jobs\SubmitFiscalDocumentJob;
use Juwri\Modules\Fiscal\Models\FiscalCertificate;
use Juwri\Modules\Fiscal\Models\FiscalDevice;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\FiscalChain;
use Juwri\Modules\Fiscal\Services\FiscalDriverManager;
use Juwri\Modules\Fiscal\Services\Zatca\Contracts\ZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\FakeZatcaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZatcaSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private int $rid = 900;
    private FakeZatcaClient $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStores(900); // P1-04 5b: fixture store ids must be real stores
        $this->fake = new FakeZatcaClient();
        $this->app->instance(ZatcaClient::class, $this->fake);
        // Production device + PCSID so the driver has credentials.
        FiscalDevice::create(['store_id' => $this->rid, 'device_key' => 'EGS-1', 'country' => 'SA', 'status' => 'production']);
        FiscalCertificate::create(['store_id' => $this->rid, 'device_key' => 'EGS-1', 'kind' => 'pcsid', 'pem' => 'CERT', 'active' => true, 'meta' => ['secret' => 's']]);
    }

    private function doc(string $type = 'simplified'): FiscalDocument
    {
        return FiscalChain::issue($this->rid, 'EGS-1', [
            'country' => 'SA', 'type' => $type, 'total' => 115, 'tax_total' => 15, 'currency' => 'SAR',
            'payload' => '<Invoice><cbc:ID>SIM-000001</cbc:ID></Invoice>',
        ]);
    }

    public function test_simplified_invoice_is_reported_and_accepted(): void
    {
        $doc = $this->doc();
        new SubmitFiscalDocumentJob($doc->id)->handle(app(FiscalDriverManager::class));

        $doc->refresh();
        $this->assertSame('accepted', $doc->status);
        $this->assertSame('REPORTED', $doc->authority_response['reportingStatus']);
        $this->assertContains('reportInvoice', $this->fake->calls);
    }

    public function test_warnings_are_surfaced_but_accepted(): void
    {
        $this->fake->withWarnings = true;
        $doc = $this->doc();
        new SubmitFiscalDocumentJob($doc->id)->handle(app(FiscalDriverManager::class));

        $doc->refresh();
        $this->assertSame('accepted', $doc->status);
        $this->assertSame('REPORTED_WITH_WARNINGS', $doc->authority_response['reportingStatus']);
    }

    public function test_standard_invoice_clears_before_print_and_stores_cleared_xml(): void
    {
        $doc = $this->doc('standard');
        new SubmitFiscalDocumentJob($doc->id)->handle(app(FiscalDriverManager::class));

        $doc->refresh();
        $this->assertSame('accepted', $doc->status);
        $this->assertStringContainsString('ZATCA-CLEARED', $doc->payload);
        $this->assertContains('clearInvoice', $this->fake->calls);
    }

    public function test_rejection_then_correction_keeps_chain_intact(): void
    {
        $this->fake->rejectNext = true;
        $doc = $this->doc();
        new SubmitFiscalDocumentJob($doc->id)->handle(app(FiscalDriverManager::class));

        $doc->refresh();
        $this->assertSame('rejected', $doc->status);

        // Correction: new doc references the rejected one; chain continues.
        $correction = FiscalChain::correct($doc);
        $this->assertSame($doc->id, $correction->supersedes_id);
        $this->assertSame('superseded', $doc->fresh()->status);
        $this->assertSame(2, $correction->counter, 'chain continues from last doc');

        // Resubmit the correction → accepted.
        new SubmitFiscalDocumentJob($correction->id)->handle(app(FiscalDriverManager::class));
        $this->assertSame('accepted', $correction->fresh()->status);
    }

    public function test_replay_is_idempotent_after_acceptance(): void
    {
        $doc = $this->doc();
        $mgr = app(FiscalDriverManager::class);
        new SubmitFiscalDocumentJob($doc->id)->handle($mgr);
        $before = count($this->fake->calls);
        new SubmitFiscalDocumentJob($doc->id)->handle($mgr);
        $this->assertCount($before, $this->fake->calls, 'accepted doc never re-sent');
    }
}
