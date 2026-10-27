<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use Juwri\Modules\Fiscal\Jobs\SubmitFiscalDocumentJob;
use Juwri\Modules\Fiscal\Models\FiscalCertificate;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\Contracts\FiscalDriver;
use Juwri\Modules\Fiscal\Services\FiscalChain;
use Juwri\Modules\Fiscal\Services\FiscalDriverManager;
use Juwri\Modules\Fiscal\Services\FiscalSlaWatchdog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FiscalDocumentStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStores(4242); // P1-04 5b: fixture store ids must be real stores
    }

    use RefreshDatabase;

    private int $rid = 4242;

    private function issue(string $device = 'EGS-1', string $type = 'simplified'): FiscalDocument
    {
        return FiscalChain::issue($this->rid, $device, [
            'country' => 'SA', 'type' => $type, 'total' => 115, 'tax_total' => 15, 'currency' => 'SAR',
        ]);
    }

    public function test_gapless_sequential_numbering_and_counter(): void
    {
        $counters = [];
        $seqs = [];
        for ($i = 0; $i < 25; $i++) {
            $doc = $this->issue();
            $counters[] = $doc->counter;
            $seqs[] = $doc->seq;
        }

        $this->assertSame(range(1, 25), $counters, 'ICV counter must be gapless per device');
        $this->assertSame(range(1, 25), $seqs, 'seq must be gapless per restaurant+type');
        $this->assertCount(25, array_unique(FiscalDocument::pluck('number')->all()), 'numbers unique');
    }

    public function test_counters_are_independent_per_device(): void
    {
        $a1 = $this->issue('EGS-A');
        $b1 = $this->issue('EGS-B');
        $a2 = $this->issue('EGS-A');

        $this->assertSame(1, $a1->counter);
        $this->assertSame(1, $b1->counter);
        $this->assertSame(2, $a2->counter);
        // previous_hash on A2 links to A1 (still null until sealed) not B1.
        $this->assertNull($a2->previous_hash);
    }

    public function test_hash_chain_links_to_prior_document(): void
    {
        $d1 = $this->issue('EGS-C');
        FiscalChain::seal($d1, hash('sha256', 'doc1'));

        $d2 = $this->issue('EGS-C');
        $this->assertSame($d1->fresh()->hash, $d2->previous_hash, 'chain link = prior hash');
    }

    public function test_seal_is_idempotent(): void
    {
        $d = $this->issue();
        FiscalChain::seal($d, 'HASH_A');
        FiscalChain::seal($d, 'HASH_B');
        $this->assertSame('HASH_A', $d->fresh()->hash);
    }

    public function test_status_machine_allows_legal_and_blocks_illegal(): void
    {
        $d = $this->issue();
        $this->assertTrue($d->transitionTo('queued'));
        $this->assertTrue($d->transitionTo('submitted'));
        $this->assertTrue($d->transitionTo('accepted'));

        // Illegal: accepted → draft.
        $this->assertFalse($d->transitionTo('draft'));
        $this->assertSame('accepted', $d->fresh()->status);
        $this->assertTrue($d->isImmutable());
    }

    public function test_certificate_pem_is_encrypted_and_hidden(): void
    {
        $cert = FiscalCertificate::create([
            'store_id' => $this->rid, 'device_key' => 'EGS-1', 'kind' => 'pcsid',
            'pem' => "-----BEGIN CERTIFICATE-----SECRET-----END-----",
            'active' => true,
        ]);

        // Round-trips through the model.
        $this->assertStringContainsString('SECRET', $cert->fresh()->pem);

        // Ciphertext at rest — the raw column is not the plaintext.
        $raw = DB::table('fiscal_certificates')->where('id', $cert->id)->value('pem');
        $this->assertStringNotContainsString('SECRET', $raw);

        // Hidden from array/JSON so it never leaks into a snapshot/log.
        $this->assertArrayNotHasKey('pem', $cert->toArray());
        $this->assertStringNotContainsString('SECRET', json_encode($cert));
    }

    public function test_certificate_resolve_returns_active_of_kind(): void
    {
        FiscalCertificate::create(['store_id' => $this->rid, 'kind' => 'eseal', 'pem' => 'A', 'active' => false]);
        $active = FiscalCertificate::create(['store_id' => $this->rid, 'kind' => 'eseal', 'pem' => 'B', 'active' => true]);

        $this->assertSame($active->id, FiscalCertificate::resolve($this->rid, 'eseal')->id);
        $this->assertNull(FiscalCertificate::resolve($this->rid, 'pcsid'));
    }

    public function test_sla_watchdog_flags_aged_queued_documents(): void
    {
        $fresh = $this->issue();
        $fresh->transitionTo('queued', ['queued_at' => now()]);

        $aged = $this->issue();
        $aged->transitionTo('queued', ['queued_at' => now()->subHours(23)]);

        $count = app(FiscalSlaWatchdog::class)->run();
        $this->assertSame(1, $count);
        $this->assertTrue((bool) ($aged->fresh()->authority_response['sla_warning'] ?? false));
        $this->assertArrayNotHasKey('sla_warning', $fresh->fresh()->authority_response ?? []);
    }

    public function test_submit_job_uses_driver_and_is_idempotent(): void
    {
        $manager = app(FiscalDriverManager::class);
        $calls = 0;
        $manager->register('SA', function () use (&$calls) {
            return new class($calls) implements FiscalDriver {
                public function __construct(private int &$calls) {}
                public function country(): string { return 'SA'; }
                public function mode(): string { return 'report_after_print'; }
                public function submit(FiscalDocument $d): array
                {
                    $this->calls++;
                    return ['status' => 'accepted', 'response' => ['ok' => true], 'hash' => 'SEALED'];
                }
            };
        });

        $doc = $this->issue();
        new SubmitFiscalDocumentJob($doc->id)->handle($manager);

        $doc->refresh();
        $this->assertSame('accepted', $doc->status);
        $this->assertSame('SEALED', $doc->hash);
        $this->assertTrue($doc->authority_response['ok']);

        // Replay is a no-op (idempotent) — driver not called again.
        new SubmitFiscalDocumentJob($doc->id)->handle($manager);
        $this->assertSame(1, $calls);
    }

    public function test_submit_job_marks_rejected(): void
    {
        $manager = app(FiscalDriverManager::class);
        $manager->register('SA', fn () => new class implements FiscalDriver {
            public function country(): string { return 'SA'; }
            public function mode(): string { return 'report_after_print'; }
            public function submit(FiscalDocument $d): array
            {
                return ['status' => 'rejected', 'response' => ['error' => 'BR-KSA-01']];
            }
        });

        $doc = $this->issue();
        new SubmitFiscalDocumentJob($doc->id)->handle($manager);
        $this->assertSame('rejected', $doc->fresh()->status);
    }

    public function test_no_driver_leaves_document_queued(): void
    {
        // AE is a profile-only country (no live driver registered).
        $manager = app(FiscalDriverManager::class);
        $doc = FiscalChain::issue($this->rid, 'POS-X', [
            'country' => 'AE', 'type' => 'simplified', 'total' => 10, 'tax_total' => 0.5, 'currency' => 'AED',
        ]);
        $doc->transitionTo('queued', ['queued_at' => now()]);
        new SubmitFiscalDocumentJob($doc->id)->handle($manager);
        $this->assertSame('queued', $doc->fresh()->status);
    }
}
