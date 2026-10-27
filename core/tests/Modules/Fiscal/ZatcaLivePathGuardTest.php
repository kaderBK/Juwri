<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\PlatformAlert;
use Juwri\Modules\Fiscal\Jobs\SubmitFiscalDocumentJob;
use Juwri\Modules\Fiscal\Models\FiscalCertificate;
use Juwri\Modules\Fiscal\Models\FiscalDevice;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\FiscalChain;
use Juwri\Modules\Fiscal\Services\FiscalDriverManager;
use Juwri\Modules\Fiscal\Services\Zatca\Contracts\ZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\FakeZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaCrypto;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaDriver;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaOnboarding;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaSubmissionHeld;
use Tests\TestCase;

/** ZATCA live path: never send without an in-date production certificate from the configured gateway. */
class ZatcaLivePathGuardTest extends TestCase
{
    use RefreshDatabase;

    private int $rid = 940;

    private FakeZatcaClient $fake;

    /** @var array<string, string> */
    private array $egs = ['common_name' => 'EGS-G', 'organization' => 'Al Baik', 'organization_unit' => '300000000000003', 'vat' => '300000000000003', 'country' => 'SA'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStores($this->rid);
        config(['services.zatca.environment' => 'sandbox']);
        $this->fake = new FakeZatcaClient;
        $this->app->instance(ZatcaClient::class, $this->fake);
    }

    /** @param  array<string, mixed>  $meta */
    private function device(string $status, array $meta = []): FiscalDevice
    {
        return FiscalDevice::create(['store_id' => $this->rid, 'device_key' => 'EGS-G', 'country' => 'SA', 'status' => $status, 'meta' => $meta]);
    }

    /** @param  array<string, mixed>  $extra */
    private function cert(string $kind, array $extra = []): FiscalCertificate
    {
        return FiscalCertificate::create($extra + ['store_id' => $this->rid, 'device_key' => 'EGS-G', 'kind' => $kind, 'pem' => strtoupper($kind), 'active' => true, 'meta' => ['secret' => $kind.'-secret']]);
    }

    private function doc(): FiscalDocument
    {
        $doc = FiscalChain::issue($this->rid, 'EGS-G', ['country' => 'SA', 'type' => 'simplified', 'total' => 115, 'tax_total' => 15, 'currency' => 'SAR',
            'payload' => '<Invoice><cbc:ID>SIM-G</cbc:ID></Invoice>']);
        $doc->transitionTo('queued', ['queued_at' => now()]);

        return $doc;
    }

    private function submitDoc(FiscalDocument $doc): void
    {
        new SubmitFiscalDocumentJob($doc->id)->handle(app(FiscalDriverManager::class));
    }

    private function assertHeld(FiscalDocument $doc, string $reason, string $alertNeedle): void
    {
        $this->submitDoc($doc);
        $this->submitDoc($doc); // the queue's retry / a duplicate job: still nothing sent, still one alert

        $this->assertSame('queued', $doc->fresh()->status, 'a held document stays queued');
        $this->assertSame([], $this->fake->calls, 'zero calls to ZATCA');
        $this->assertSame($reason, FiscalDevice::where('store_id', $this->rid)->where('device_key', 'EGS-G')->first()->meta['held']['reason']);
        $alerts = PlatformAlert::where('kind', 'fiscal')->get();
        $this->assertCount(1, $alerts, 'one clear alert: '.json_encode($alerts->pluck('message')));
        $this->assertStringContainsString($alertNeedle, (string) $alerts->first()->message);
        $this->assertStringNotContainsString('secret', (string) $alerts->first()->message);
    }

    public function test_compliance_device_never_sends_live_invoices_with_its_compliance_certificate(): void
    {
        $this->device('compliance');
        $this->cert('ccsid');
        $this->assertHeld($this->doc(), 'not_production', 'not in production');
    }

    public function test_production_device_without_a_certificate_is_held_not_sent_with_empty_credentials(): void
    {
        $this->device('production');
        $this->assertHeld($this->doc(), 'not_onboarded', 'not onboarded');
    }

    public function test_certificate_without_secret_is_held(): void
    {
        $this->device('production');
        $this->cert('pcsid', ['meta' => []]);
        $this->assertHeld($this->doc(), 'not_onboarded', 'not onboarded');
    }

    public function test_expired_certificate_is_held_with_a_renew_alert(): void
    {
        $this->device('production');
        $this->cert('pcsid', ['expires_at' => now()->subDay()]);
        $this->assertHeld($this->doc(), 'cert_expired', 'Renew the certificate');
    }

    public function test_device_onboarded_on_the_other_gateway_is_held(): void
    {
        $this->device('production', ['environment' => 'production']);
        $this->cert('pcsid');
        $this->assertHeld($this->doc(), 'env_mismatch', 'ZATCA_ENV is sandbox');
    }

    public function test_driver_refuses_directly_too(): void
    {
        $this->device('compliance');
        $this->cert('ccsid');
        $this->expectException(ZatcaSubmissionHeld::class);
        try {
            new ZatcaDriver($this->fake)->submit($this->doc());
        } finally {
            $this->assertSame([], $this->fake->calls);
        }
    }

    public function test_in_date_production_certificate_on_the_right_gateway_is_sent(): void
    {
        $this->device('production', ['environment' => 'sandbox']);
        $this->cert('pcsid', ['expires_at' => now()->addYear()]);
        $doc = $this->doc();
        $this->submitDoc($doc);

        $this->assertSame('accepted', $doc->fresh()->status);
        $this->assertSame(['reportInvoice'], $this->fake->calls);
        $this->assertSame(0, PlatformAlert::count());
    }

    public function test_renewal_releases_held_documents_and_clears_the_hold(): void
    {
        $device = $this->device('production', ['environment' => 'sandbox']);
        $this->cert('private_key', ['meta' => ['public' => 'P']]);
        $this->cert('pcsid', ['expires_at' => now()->subDay()]);
        $doc = $this->doc();
        $this->submitDoc($doc);
        $this->assertSame('queued', $doc->fresh()->status);

        new ZatcaOnboarding($this->fake, new ZatcaCrypto)->renewProductionCsid($device->fresh(), '123456', $this->egs);

        $this->assertSame('accepted', $doc->fresh()->status, 'held document sent once a valid certificate is stored');
        $this->assertArrayNotHasKey('held', (array) $device->fresh()->meta);
    }

    public function test_reonboarding_a_production_device_keeps_it_live_until_the_new_certificate_is_stored(): void
    {
        $client = new class extends FakeZatcaClient
        {
            public bool $fail = true;

            public function complianceCheck(string $certificate, string $secret, array $document): array
            {
                $this->calls[] = 'complianceCheck';

                return ['status' => $this->fail ? 'FAIL' : 'PASS'];
            }
        };
        $this->app->instance(ZatcaClient::class, $client);
        $device = $this->device('production', ['environment' => 'sandbox']);
        $oldKey = $this->cert('private_key', ['meta' => ['public' => 'P']]);
        $oldPcsid = $this->cert('pcsid');
        $svc = new ZatcaOnboarding($client, new ZatcaCrypto);

        $svc->requestComplianceCsid($device, '123456', $this->egs);
        $this->assertFalse($svc->runComplianceChecks($device->fresh(), [['xml' => '<Invoice/>']]));

        $device->refresh();
        $this->assertSame('production', $device->status, 'failed re-onboarding never demotes a live device');
        $this->assertSame($oldKey->id, FiscalCertificate::resolve($this->rid, 'private_key', 'EGS-G')->id, 'live invoices still signed with the key of the live certificate');
        $this->assertSame($oldPcsid->id, FiscalCertificate::resolve($this->rid, 'pcsid', 'EGS-G')->id);
        $doc = $this->doc();
        $this->submitDoc($doc);
        $this->assertSame('accepted', $doc->fresh()->status, 'the valid production certificate keeps sending');

        // A later successful run swaps key + certificate together.
        $client->fail = false;
        $pendingKey = $device->meta['pending']['key_id'];
        $samples = $svc->complianceSamples($device, 'Al Baik', '300000000000003', ZatcaUblTest::SELLER_ADDRESS);
        $this->assertCount(6, $samples);
        $this->assertTrue($svc->runComplianceChecks($device, $samples));
        $new = $svc->requestProductionCsid($device);

        $device->refresh();
        $this->assertSame('production', $device->status);
        $this->assertArrayNotHasKey('pending', (array) $device->meta);
        $this->assertSame($pendingKey, FiscalCertificate::resolve($this->rid, 'private_key', 'EGS-G')->id);
        $this->assertSame($new->id, FiscalCertificate::resolve($this->rid, 'pcsid', 'EGS-G')->id);
        $this->assertFalse($oldPcsid->fresh()->active);
        $this->assertFalse($oldKey->fresh()->active);
    }

    public function test_first_onboarding_still_moves_new_to_compliance_to_production(): void
    {
        $svc = new ZatcaOnboarding($this->fake, new ZatcaCrypto);
        $device = $svc->ensureDevice($this->rid, 'EGS-G');
        $svc->requestComplianceCsid($device, '123456', $this->egs);
        $this->assertSame('compliance', $device->fresh()->status);
        $this->assertSame('sandbox', $device->fresh()->meta['environment']);
        $svc->requestProductionCsid($device->fresh());
        $this->assertSame('production', $device->fresh()->status);
    }
}
