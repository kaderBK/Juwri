<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use Juwri\Modules\Fiscal\Models\FiscalCertificate;
use Juwri\Modules\Fiscal\Services\Eta\EtaCanonicalizer;
use Juwri\Modules\Fiscal\Services\Eta\EtaSigner;
use Juwri\Modules\Fiscal\Services\Eta\ReceiptBuilder;
use Juwri\Modules\Fiscal\Services\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EtaReceiptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStores(5); // P1-04 5b: fixture store ids must be real stores
    }

    use RefreshDatabase;

    private function ctx(float $total = 114.0, float $tax = 14.0): FiscalReceiptContext
    {
        return new FiscalReceiptContext(
            sellerName: 'Cairo Eats', vatNumber: null, crNumber: null,
            total: $total, taxAmount: $tax, issuedAt: new \DateTimeImmutable('2026-07-12T09:00:00+00:00'),
            currency: 'EGP',
        );
    }

    private function device(string $serial = 'POS-1', string $prev = '', string $num = 'R1'): array
    {
        return ['rin' => '123456789', 'branchCode' => '0', 'deviceSerial' => $serial, 'receiptNumber' => $num, 'previousUUID' => $prev];
    }

    public function test_canonicalizer_is_deterministic_and_handles_arrays(): void
    {
        $c = new EtaCanonicalizer();
        $data = ['a' => 'x', 'b' => ['k' => 1], 'list' => [['n' => 'p'], ['n' => 'q']]];
        $this->assertSame($c->canonicalize($data), $c->canonicalize($data));
        $expected = '"a""x""b""k"1"list""n""p""list""n""q"';
        $this->assertSame($expected, $c->canonicalize($data));
    }

    public function test_builds_receipt_v1_2_with_uuid(): void
    {
        $out = new ReceiptBuilder()->build($this->ctx(), $this->device());
        $this->assertSame('1.2', $out['receipt']['documentType']['typeVersion']);
        $this->assertSame('s', $out['receipt']['documentType']['receiptType']);
        $this->assertSame(64, strlen($out['uuid'])); // hex sha-256
        $this->assertSame($out['uuid'], $out['receipt']['header']['uuid']);
        $this->assertSame('14.00', $out['receipt']['taxTotals'][0]['amount']);
    }

    public function test_previous_uuid_chains_per_device(): void
    {
        $builder = new ReceiptBuilder();
        $r1 = $builder->build($this->ctx(), $this->device('POS-1', '', 'R1'));
        $r2 = $builder->build($this->ctx(), $this->device('POS-1', $r1['uuid'], 'R2'));

        $this->assertSame('', $r1['receipt']['header']['previousUUID']);
        $this->assertSame($r1['uuid'], $r2['receipt']['header']['previousUUID']);
        $this->assertNotSame($r1['uuid'], $r2['uuid']);
    }

    public function test_return_receipt_references_old_uuid(): void
    {
        $builder = new ReceiptBuilder();
        $sale = $builder->build($this->ctx(), $this->device());
        $ret = $builder->build($this->ctx(), $this->device('POS-1', $sale['uuid'], 'R2'), true, $sale['uuid']);

        $this->assertSame('r', $ret['receipt']['documentType']['receiptType']);
        $this->assertSame('1.0', $ret['receipt']['documentType']['typeVersion']);
        $this->assertSame($sale['uuid'], $ret['receipt']['header']['referenceOldUUID']);
    }

    public function test_reconciliation_hard_fails(): void
    {
        $this->expectException(\RuntimeException::class);
        new ReceiptBuilder()->build($this->ctx(114.0, 200.0), $this->device());
    }

    public function test_signer_produces_signature_with_eseal(): void
    {
        $keys = new ZatcaCrypto()->generateKeyPair(); // reuse EC keygen for a soft e-seal
        FiscalCertificate::create(['store_id' => 5, 'device_key' => 'POS-1', 'kind' => 'eseal', 'pem' => $keys['private'], 'active' => true]);

        $out = new ReceiptBuilder()->build($this->ctx(), $this->device());
        $sig = new EtaSigner()->sign(5, 'POS-1', $out['canonical']);
        $this->assertNotNull($sig);
        $this->assertNotEmpty(base64_decode($sig, true));
        // Signature is over the canonical content hashed ONCE (not a double hash):
        // verifying against the raw canonical with SHA-256 must succeed.
        $ok = openssl_verify($out['canonical'], base64_decode($sig, true), $keys['public'], OPENSSL_ALGO_SHA256);
        $this->assertSame(1, $ok);
    }

    public function test_signer_returns_null_without_cert(): void
    {
        $out = new ReceiptBuilder()->build($this->ctx(), $this->device());
        $this->assertNull(new EtaSigner()->sign(999, 'POS-9', $out['canonical']));
    }
}
