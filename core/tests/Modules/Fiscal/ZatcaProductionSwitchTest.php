<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Juwri\Modules\Fiscal\Models\FiscalCertificate;
use Juwri\Modules\Fiscal\Models\FiscalDevice;
use Juwri\Modules\Fiscal\Services\Zatca\FakeZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaApiException;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaCertificate;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaCrypto;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaHttpClient;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaOnboarding;
use Tests\TestCase;

/** RB-11: sandbox -> production switch. No live ZATCA call anywhere: Http::fake only. */
class ZatcaProductionSwitchTest extends TestCase
{
    use RefreshDatabase;

    private int $rid = 710;

    private array $egs = ['common_name' => 'EGS-9', 'organization' => 'Al Baik', 'organization_unit' => '300000000000003', 'vat' => '300000000000003', 'country' => 'SA'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStores($this->rid);
        StoreOption::updateStoreOption($this->rid, 'fiscal_vat_number', '300000000000003');
        StoreOption::updateStoreOption($this->rid, 'fiscal_legal_name', 'Al Baik');
    }

    /** A fresh fake each time: Http::fake() calls in one test otherwise stack and the first stub wins. */
    private function fake(array $stubs): void
    {
        Http::swap(new Factory);
        Http::fake($stubs);
    }

    private function http(): ZatcaHttpClient
    {
        return new ZatcaHttpClient('https://gw.test/api');
    }

    /** A real self-signed certificate valid for $days; returns the ZATCA-style token (base64 of base64 DER body). */
    private function tokenValidFor(int $days): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'EGS'], $key);
        $x509 = openssl_csr_sign($csr, null, $key, $days);
        openssl_x509_export($x509, $pem);
        $body = preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);

        return base64_encode($body);
    }

    public function test_base_url_follows_environment_and_override(): void
    {
        foreach (['sandbox' => 'developer-portal', 'simulation' => 'simulation', 'production' => 'core'] as $env => $tail) {
            config(['services.zatca.environment' => $env, 'services.zatca.base_url' => null]);
            $this->assertStringEndsWith('/e-invoicing/'.$tail, ZatcaHttpClient::resolveBaseUrl());
        }
        config(['services.zatca.base_url' => 'https://x.test/y/']);
        $this->assertSame('https://x.test/y', ZatcaHttpClient::resolveBaseUrl());
        config(['services.zatca.base_url' => null, 'services.zatca.environment' => 'staging']);
        $this->expectException(ZatcaApiException::class);
        ZatcaHttpClient::resolveBaseUrl();
    }

    public function test_compliance_csid_sends_otp_and_csr_and_parses_certificate(): void
    {
        $this->fake(['gw.test/*' => Http::response(['requestID' => '99', 'binarySecurityToken' => 'TOK', 'secret' => 'SEC'], 200)]);
        $r = $this->http()->complianceCsid('CSR64', '123456');
        $this->assertSame(['request_id' => '99', 'certificate' => 'TOK', 'secret' => 'SEC'], $r);
        Http::assertSent(fn (Request $q) => $q->url() === 'https://gw.test/api/compliance' && $q->header('OTP')[0] === '123456' && $q['csr'] === 'CSR64');
    }

    public function test_bad_otp_throws_with_zatca_message_and_no_certificate_is_stored_empty(): void
    {
        $this->fake(['gw.test/*' => Http::response(['message' => 'Invalid OTP'], 400)]);
        try {
            $this->http()->complianceCsid('CSR64', '000000');
            $this->fail('expected exception');
        } catch (ZatcaApiException $e) {
            $this->assertStringContainsString('Invalid OTP', $e->getMessage());
            $this->assertSame(400, $e->httpStatus);
        }
        $this->fake(['gw.test/*' => Http::response([], 200)]); // 200 without a certificate must not pass either
        $this->expectException(ZatcaApiException::class);
        $this->http()->complianceCsid('CSR64', '123456');
    }

    public function test_compliance_check_error_is_not_a_pass(): void
    {
        $this->fake(['gw.test/*' => Http::response(['validationResults' => ['status' => 'ERROR', 'errorMessages' => [['code' => 'X', 'message' => 'bad']]]], 400)]);
        $this->expectException(ZatcaApiException::class); // 4xx on this endpoint is an API failure, never a silent PASS
        $this->http()->complianceCheck('C', 'S', ['xml' => '<a/>', 'uuid' => 'u', 'hash' => 'h']);
    }

    public function test_compliance_check_pass_and_warning_pass_and_error_status_fails(): void
    {
        foreach (['PASS' => 'PASS', 'WARNING' => 'PASS', 'ERROR' => 'ERROR'] as $in => $out) {
            $this->fake(['gw.test/*' => Http::response(['validationResults' => ['status' => $in]], 200)]);
            $this->assertSame($out, $this->http()->complianceCheck('C', 'S', ['xml' => '<a/>', 'uuid' => 'u', 'hash' => 'h'])['status']);
        }
        $this->fake(['gw.test/*' => Http::response([], 200)]);
        $this->expectException(ZatcaApiException::class); // no status at all is not a pass
        $this->http()->complianceCheck('C', 'S', ['xml' => '<a/>', 'uuid' => 'u', 'hash' => 'h']);
    }

    public function test_report_sends_zatca_body_and_maps_verdicts(): void
    {
        $this->fake(['gw.test/*' => Http::response(['reportingStatus' => 'REPORTED'], 200)]);
        $res = $this->http()->reportInvoice('CERT', 'SEC', ['xml' => '<Invoice/>', 'uuid' => 'U-1', 'hash' => 'H1']);
        $this->assertSame('accepted', $res['status']);
        Http::assertSent(fn (Request $q) => str_ends_with($q->url(), '/invoices/reporting/single')
            && $q['invoiceHash'] === 'H1' && $q['uuid'] === 'U-1' && base64_decode($q['invoice']) === '<Invoice/>'
            && $q->header('Clearance-Status')[0] === '0' && $q->header('Accept-Version')[0] === 'V2'
            && str_starts_with($q->header('Authorization')[0], 'Basic '));

        $this->fake(['gw.test/*' => Http::response(['validationResults' => ['status' => 'ERROR']], 400)]);
        $this->assertSame('rejected', $this->http()->reportInvoice('C', 'S', ['xml' => 'x', 'uuid' => 'u', 'hash' => 'h'])['status']);
    }

    public function test_auth_and_server_errors_throw_so_the_job_retries_instead_of_accepting(): void
    {
        foreach ([401, 403, 500, 503] as $code) {
            $this->fake(['gw.test/*' => Http::response(['message' => 'nope'], $code)]);
            try {
                $this->http()->reportInvoice('C', 'S', ['xml' => 'x', 'uuid' => 'u', 'hash' => 'h']);
                $this->fail("HTTP {$code} must not be accepted");
            } catch (ZatcaApiException $e) {
                $this->assertSame($code, $e->httpStatus);
            }
        }
    }

    public function test_clearance_returns_decoded_cleared_xml(): void
    {
        $this->fake(['gw.test/*' => Http::response(['clearanceStatus' => 'CLEARED', 'clearedInvoice' => base64_encode('<Cleared/>')], 200)]);
        $res = $this->http()->clearInvoice('C', 'S', ['xml' => 'x', 'uuid' => 'u', 'hash' => 'h']);
        $this->assertSame('accepted', $res['status']);
        $this->assertSame('<Cleared/>', $res['cleared_xml']);
        Http::assertSent(fn (Request $q) => $q->header('Clearance-Status')[0] === '1');
    }

    public function test_renewal_patches_production_csids_with_otp_and_csr(): void
    {
        $this->fake(['gw.test/*' => Http::response(['requestID' => '5', 'binarySecurityToken' => 'NEW', 'secret' => 'NS'], 200)]);
        $r = $this->http()->renewProductionCsid('OLD', 'OS', 'CSR64', '654321');
        $this->assertSame('NEW', $r['certificate']);
        Http::assertSent(fn (Request $q) => $q->method() === 'PATCH' && str_ends_with($q->url(), '/production/csids') && $q->header('OTP')[0] === '654321' && $q['csr'] === 'CSR64');
    }

    public function test_certificate_expiry_is_read_from_the_token(): void
    {
        $exp = ZatcaCertificate::expiry($this->tokenValidFor(100));
        $this->assertNotNull($exp);
        $this->assertEqualsWithDelta(100, now()->diffInDays($exp, false), 1);
        $this->assertNull(ZatcaCertificate::expiry(base64_encode('PRODUCTION-CERT'))); // the fake client's token
        $this->assertNull(ZatcaCertificate::expiry('%%%'));
    }

    public function test_wizard_samples_cover_the_six_document_types_and_pass_the_check(): void
    {
        $svc = new ZatcaOnboarding($client = new FakeZatcaClient, new ZatcaCrypto);
        $device = $svc->ensureDevice($this->rid, 'EGS-9', 1);
        $svc->requestComplianceCsid($device, '123456', $this->egs);
        $samples = $svc->complianceSamples($device->fresh(), 'Al Baik', '300000000000003', ZatcaUblTest::SELLER_ADDRESS); // P6-176
        $this->assertCount(6, $samples);
        $this->assertSame(['standard', 'credit_note', 'debit_note', 'simplified', 'credit_note', 'debit_note'], array_column($samples, 'type'));
        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="0100000">383</cbc:InvoiceTypeCode>', $samples[2]['xml']);
        $this->assertStringContainsString('>381<', $samples[1]['xml']);
        $this->assertSame(6, count(array_unique(array_column($samples, 'uuid'))));
        $this->assertTrue($svc->runComplianceChecks($device->fresh(), $samples));
        $this->assertSame(config('services.zatca.environment'), $device->fresh()->meta['environment']);
    }

    public function test_renewal_swaps_certificates_and_keeps_old_ones_when_it_fails(): void
    {
        $svc = new ZatcaOnboarding($client = new FakeZatcaClient, new ZatcaCrypto);
        $device = $svc->ensureDevice($this->rid, 'EGS-9', 1);
        $svc->requestComplianceCsid($device, '123456', $this->egs);
        $svc->requestProductionCsid($device->fresh());
        $oldKey = FiscalCertificate::resolve($this->rid, 'private_key', 'EGS-9');
        $oldCert = FiscalCertificate::resolve($this->rid, 'pcsid', 'EGS-9');

        try {
            $svc->renewProductionCsid($device->fresh(), '12', $this->egs); // bad code
            $this->fail('expected exception');
        } catch (\RuntimeException) {
        }
        $this->assertSame($oldCert->id, FiscalCertificate::resolve($this->rid, 'pcsid', 'EGS-9')->id);
        $this->assertSame($oldKey->id, FiscalCertificate::resolve($this->rid, 'private_key', 'EGS-9')->id);

        $new = $svc->renewProductionCsid($device->fresh(), '654321', $this->egs);
        $this->assertSame($new->id, FiscalCertificate::resolve($this->rid, 'pcsid', 'EGS-9')->id);
        $this->assertNotSame($oldKey->id, FiscalCertificate::resolve($this->rid, 'private_key', 'EGS-9')->id);
        $this->assertFalse((bool) $oldCert->fresh()->active);
        $this->assertTrue($device->fresh()->isProduction());
    }

    private function productionDevice(string $token, ?string $env = null): void
    {
        $svc = new ZatcaOnboarding(new FakeZatcaClient, new ZatcaCrypto);
        $device = $svc->ensureDevice($this->rid, 'EGS-9', 1);
        $svc->requestComplianceCsid($device, '123456', $this->egs);
        $svc->requestProductionCsid($device->fresh());
        $c = FiscalCertificate::resolve($this->rid, 'pcsid', 'EGS-9');
        $c->update(['pem' => $token, 'expires_at' => ZatcaCertificate::expiry($token)]);
        if ($env) {
            $device->fresh()->update(['meta' => array_merge($device->fresh()->meta, ['environment' => $env])]);
        }
    }

    public function test_check_command_fails_when_production_env_but_live_is_off(): void
    {
        config(['services.zatca.environment' => 'production', 'services.zatca.live' => false]);
        $this->artisan('fiscal:zatca-check')->expectsOutputToContain('ZATCA_LIVE')->assertFailed();
    }

    public function test_check_command_passes_a_healthy_production_device(): void
    {
        config(['services.zatca.environment' => 'production', 'services.zatca.live' => true]);
        $this->productionDevice($this->tokenValidFor(200), 'production');
        $this->artisan('fiscal:zatca-check', ['--strict' => true])->expectsOutputToContain('valid for')->assertSuccessful();
    }

    public function test_check_command_flags_expiring_expired_and_wrong_environment(): void
    {
        config(['services.zatca.environment' => 'production', 'services.zatca.live' => true]);
        $this->productionDevice($this->tokenValidFor(5), 'production');
        $this->artisan('fiscal:zatca-check')->expectsOutputToContain('expires in')->assertFailed();

        FiscalCertificate::resolve($this->rid, 'pcsid', 'EGS-9')->update(['expires_at' => now()->subDays(3)]);
        $this->artisan('fiscal:zatca-check')->expectsOutputToContain('EXPIRED')->assertFailed();

        FiscalCertificate::resolve($this->rid, 'pcsid', 'EGS-9')->update(['expires_at' => now()->addDays(300)]);
        FiscalDevice::withoutGlobalScopes()->where('device_key', 'EGS-9')->first()
            ->update(['meta' => ['environment' => 'sandbox']]);
        $this->artisan('fiscal:zatca-check')->expectsOutputToContain('Onboarded on the sandbox gateway')->assertFailed();
    }

    public function test_check_command_flags_bad_vat_and_missing_key(): void
    {
        config(['services.zatca.environment' => 'production', 'services.zatca.live' => true]);
        $this->productionDevice($this->tokenValidFor(200), 'production');
        StoreOption::updateStoreOption($this->rid, 'fiscal_vat_number', '12345');
        FiscalCertificate::resolve($this->rid, 'private_key', 'EGS-9')->update(['active' => false]);
        $this->artisan('fiscal:zatca-check')->expectsOutputToContain('15 digits')->expectsOutputToContain('without an active key')->assertFailed();
    }
}
