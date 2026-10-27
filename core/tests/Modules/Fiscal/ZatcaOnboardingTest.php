<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use Juwri\Modules\Fiscal\Models\FiscalCertificate;
use Juwri\Modules\Fiscal\Services\Zatca\FakeZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\UblBuilder;
use Juwri\Modules\Fiscal\Services\Zatca\UblInvoice;
use Juwri\Modules\Fiscal\Services\Zatca\XadesStamp;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaCrypto;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaOnboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZatcaOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStores(700); // P1-04 5b: fixture store ids must be real stores
    }

    private int $rid = 700;
    private array $egs = [
        'common_name' => 'EGS-1', 'organization' => 'Al Baik', 'organization_unit' => '300000000000003',
        'vat' => '300000000000003', 'country' => 'SA',
    ];

    private function onboarding(FakeZatcaClient $client): ZatcaOnboarding
    {
        return new ZatcaOnboarding($client, new ZatcaCrypto());
    }

    public function test_secp256k1_keypair_and_csr_generate(): void
    {
        $crypto = new ZatcaCrypto();
        $keys = $crypto->generateKeyPair();
        $this->assertStringContainsString('BEGIN', $keys['private']);
        $this->assertStringContainsString('PUBLIC KEY', $keys['public']);

        $csr = $crypto->generateCsr($keys['private'], $this->egs);
        $this->assertStringContainsString('CERTIFICATE REQUEST', $csr);
    }

    public function test_stamp_verifies_with_public_key(): void
    {
        $crypto = new ZatcaCrypto();
        $keys = $crypto->generateKeyPair();

        $inv = new UblInvoice(
            type: 'simplified', number: 'SIM-1', uuid: 'u', issuedAt: new \DateTimeImmutable(),
            currency: 'SAR', sellerName: 'X', sellerVat: '300000000000003', sellerCr: null,
            icv: 1, previousHash: null,
            lines: [['name' => 'A', 'qty' => 1, 'unit_price' => 100, 'tax_rate' => 15, 'line_total' => 100, 'tax_amount' => 15]],
            total: 115, taxTotal: 15,
        );
        $xml = new UblBuilder()->build($inv);

        $stamp = new XadesStamp($crypto);
        $out = $stamp->stamp($xml, $keys['private'], $keys['public']);

        $this->assertTrue($stamp->verify($out['xml'], $out['signature'], $keys['public']));
        // Tamper → verification fails.
        $this->assertFalse($stamp->verify(str_replace('X', 'Y', $out['xml']), $out['signature'], $keys['public']));
    }

    public function test_full_onboarding_state_machine(): void
    {
        $client = new FakeZatcaClient();
        $svc = $this->onboarding($client);

        $device = $svc->ensureDevice($this->rid, 'EGS-1', 1);
        $this->assertSame('new', $device->status);

        $svc->requestComplianceCsid($device, '123456', $this->egs);
        $this->assertSame('compliance', $device->fresh()->status);
        $this->assertNotNull(FiscalCertificate::resolve($this->rid, 'ccsid', 'EGS-1'));
        $this->assertNotNull(FiscalCertificate::resolve($this->rid, 'private_key', 'EGS-1'));

        $this->assertTrue($svc->runComplianceChecks($device, array_fill(0, 6, ['xml' => '<Invoice/>'])));

        $svc->requestProductionCsid($device->fresh());
        $this->assertSame('production', $device->fresh()->status);
        $this->assertTrue($device->fresh()->isProduction());
        $this->assertNotNull(FiscalCertificate::resolve($this->rid, 'pcsid', 'EGS-1'));
    }

    public function test_invalid_otp_blocks_onboarding(): void
    {
        $client = new FakeZatcaClient();
        $svc = $this->onboarding($client);
        $device = $svc->ensureDevice($this->rid, 'EGS-2');

        $this->expectException(\RuntimeException::class);
        try {
            $svc->requestComplianceCsid($device, '12', $this->egs); // too short / expired
        } finally {
            $this->assertSame('new', $device->fresh()->status);
        }
    }

    public function test_compliance_failure_does_not_advance_to_production(): void
    {
        $client = new class extends FakeZatcaClient {
            public function complianceCheck(string $certificate, string $secret, array $document): array
            {
                return ['status' => 'FAIL'];
            }
        };
        $svc = $this->onboarding($client);
        $device = $svc->ensureDevice($this->rid, 'EGS-3');
        $svc->requestComplianceCsid($device, '123456', $this->egs);

        $this->assertFalse($svc->runComplianceChecks($device, [['xml' => '<Invoice/>']]));
        $this->assertSame('compliance', $device->fresh()->status);
    }
}
