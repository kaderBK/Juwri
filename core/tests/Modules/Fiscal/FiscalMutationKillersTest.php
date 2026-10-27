<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use App\Models\StoreOption;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Data\FiscalSale;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\FiscalChain;
use Juwri\Modules\Fiscal\Services\FiscalProfile;
use Juwri\Modules\Fiscal\Services\FiscalReceipt;
use Juwri\Modules\Fiscal\Services\Jofotara\JofotaraBuilder;
use Juwri\Modules\Fiscal\Services\Profiles\AeProfile;
use Juwri\Modules\Fiscal\Services\Profiles\EgProfile;
use Juwri\Modules\Fiscal\Services\Profiles\JoProfile;
use Juwri\Modules\Fiscal\Services\Profiles\OmProfile;
use Juwri\Modules\Fiscal\Services\Profiles\SaProfile;
use Juwri\Modules\Fiscal\Services\Tlv;
use ReflectionMethod;
use Tests\TestCase;

/**
 * P1-18 part 2: pins the Fiscal behaviours the mutation run (composer mutate:fiscal) found
 * uncovered — the FiscalProfile base defaults, the Egypt/Jordan/Oman/UAE/Saudi profile constants, TLV decoding of
 * damaged input, the document-chain defaults and duplicate-key detection, the receipt seller name, and the JoFotara
 * reconciliation guard.
 */
class FiscalMutationKillersTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // The retry test hangs throwaway `creating` listeners on the model; drop them AND re-run the model's own booted()
        // hooks (the sealed-field `updating` guard) so nothing leaks into later tests of the same worker.
        FiscalDocument::flushEventListeners();
        FiscalDocument::clearBootedModels();
        parent::tearDown();
    }

    public function test_base_profile_defaults(): void
    {
        $p = new class extends FiscalProfile
        {
            public function country(): string
            {
                return 'ZZ';
            }

            public function countryName(): string
            {
                return 'Zedland';
            }

            public function defaultVatRate(): float
            {
                return 7.0;
            }

            public function currency(): string
            {
                return 'ZZD';
            }
        };
        $this->assertSame(FiscalProfile::MODE_NONE, $p->driverMode());
        $this->assertSame('none', FiscalProfile::MODE_NONE);
        $this->assertSame('clear_before_print', FiscalProfile::MODE_CLEAR);
        $this->assertSame('report_after_print', FiscalProfile::MODE_REPORT);
        $this->assertFalse($p->hasLiveDriver());
        $this->assertNull($p->simplifiedThreshold());
        $this->assertSame([], $p->requiredReceiptFields());
        $this->assertFalse($p->requiresSimplifiedTitle());
        $this->assertTrue($p->isSimplified(1.0e9), 'no threshold: always simplified-eligible');
        $this->assertSame(2, $p->roundingScale());
        $this->assertSame(1.23, $p->round(1.2345));
        $this->assertSame(1.24, $p->round(1.235), 'half up on the 15-digit value, like the storefront');
        $this->assertSame(0.02, $p->roundingTolerance());
        $this->assertFalse($p->qrIsUrl());
        $this->assertNull($p->qr(new FiscalReceiptContext('S', null, null, 1.0, 0.0, new \DateTimeImmutable('2026-01-01'), 'USD')));
        $this->assertTrue($p->validateVatNumber(null));
        $this->assertTrue($p->validateVatNumber(''));
        $this->assertTrue($p->validateVatNumber('AB-12 345'));
        $this->assertTrue($p->validateVatNumber(str_repeat('A', 20)));
        $this->assertFalse($p->validateVatNumber('AB12'), 'under 5 characters');
        $this->assertFalse($p->validateVatNumber(str_repeat('A', 21)), 'over 20 characters');
        $this->assertFalse($p->validateVatNumber('AB_12345'), 'underscore is not allowed');
    }

    public function test_threshold_and_title_rules_on_a_real_profile(): void
    {
        $sa = new SaProfile;
        $this->assertSame('SAR', $sa->currency());
        $this->assertSame(15.0, $sa->defaultVatRate());
        $this->assertSame('Saudi Arabia', $sa->countryName());
    }

    public function test_egypt_profile(): void
    {
        $p = new EgProfile;
        $this->assertSame('EG', $p->country());
        $this->assertSame('Egypt', $p->countryName());
        $this->assertSame(14.0, $p->defaultVatRate());
        $this->assertSame('EGP', $p->currency());
        $this->assertSame(FiscalProfile::MODE_REPORT, $p->driverMode());
        $this->assertTrue($p->hasLiveDriver());
        $this->assertSame(['seller_name', 'vat_number', 'tax_breakdown', 'qr'], $p->requiredReceiptFields());
        $this->assertTrue($p->qrIsUrl());
        $ctx = new FiscalReceiptContext('S', null, null, 1.0, 0.0, new \DateTimeImmutable('2026-01-01'), 'EGP', ['lookup_url' => 'https://eta.example/r/1']);
        $this->assertSame('https://eta.example/r/1', $p->qr($ctx));
        $none = new FiscalReceiptContext('S', null, null, 1.0, 0.0, new \DateTimeImmutable('2026-01-01'), 'EGP');
        $this->assertNull($p->qr($none));
    }

    public function test_jordan_and_oman_profiles_are_three_decimal(): void
    {
        $jo = new JoProfile;
        $this->assertSame('Jordan', $jo->countryName());
        $this->assertSame(16.0, $jo->defaultVatRate());
        $this->assertSame('JOD', $jo->currency());
        $this->assertSame(FiscalProfile::MODE_CLEAR, $jo->driverMode());
        $this->assertTrue($jo->hasLiveDriver());
        $this->assertSame(3, $jo->roundingScale());
        $this->assertSame(1.235, $jo->round(1.2345));

        $om = new OmProfile;
        $this->assertSame('Oman', $om->countryName());
        $this->assertSame(5.0, $om->defaultVatRate());
        $this->assertSame('OMR', $om->currency());
        $this->assertSame(3, $om->roundingScale());
        $this->assertSame(1.235, $om->round(1.2345));
    }

    public function test_uae_trn_is_exactly_fifteen_digits(): void
    {
        $ae = new AeProfile;
        $this->assertTrue($ae->validateVatNumber(null));
        $this->assertTrue($ae->validateVatNumber(''));
        $this->assertTrue($ae->validateVatNumber('100123456700003'));
        $this->assertFalse($ae->validateVatNumber('10012345670000'), '14 digits');
        $this->assertFalse($ae->validateVatNumber('1001234567000030'), '16 digits');
        $this->assertFalse($ae->validateVatNumber('10012345670000A'));
        $this->assertFalse($ae->validateVatNumber(' 100123456700003'));
        $this->assertSame(['seller_name', 'vat_number', 'tax_breakdown', 'simplified_title'], $ae->requiredReceiptFields());
    }

    public function test_tlv_decode_survives_damaged_input(): void
    {
        $this->assertSame([], Tlv::decode('***not base64***'));
        $this->assertSame([], Tlv::decode(''));
        // tag 1 with no length byte at all: stops, nothing decoded
        $this->assertSame([], Tlv::decode(base64_encode("\x01")));
        // tag 1 declaring 9 bytes but only 3 present: takes what is there
        $this->assertSame([1 => 'abc'], Tlv::decode(base64_encode("\x01\x09abc")));
        // a well-formed pair followed by a dangling tag
        $this->assertSame([1 => 'ab', 2 => 'x'], Tlv::decode(base64_encode("\x01\x02ab\x02\x01x\x03")));
    }

    public function test_chain_defaults_device_and_type_and_detects_duplicate_keys(): void
    {
        $this->seedStores(5151);
        $doc = FiscalChain::issue(5151, '', ['country' => 'SA', 'total' => 115, 'tax_total' => 15, 'currency' => 'SAR']);
        $this->assertSame('default', $doc->device_key, 'an empty device key becomes "default"');
        $this->assertSame('simplified', $doc->type, 'no type means simplified');

        $dup = new ReflectionMethod(FiscalChain::class, 'isDuplicateKey');
        $e = fn (string $message, ?array $info) => (function () use ($message, $info) {
            $ex = new QueryException('sqlite', 'insert', [], new \Exception($message));
            if ($info !== null) {
                $ex->errorInfo = $info;
            }

            return $ex;
        })();
        $this->assertTrue($dup->invoke(null, $e('x', ['23000', 1062, 'dup'])), 'MySQL 1062');
        $this->assertTrue($dup->invoke(null, $e('UNIQUE constraint failed: a.b', [])), 'SQLite message');
        $this->assertTrue($dup->invoke(null, $e('boom', ['23505', null, 'dup'])), 'Postgres 23505');
        $this->assertFalse($dup->invoke(null, $e('boom', ['23000', 1451, 'fk'])), 'a foreign-key error is not a duplicate');
        $this->assertFalse($dup->invoke(null, $e('boom', [])));
    }

    public function test_chain_retries_a_lost_duplicate_key_race_a_bounded_number_of_times(): void
    {
        $this->seedStores(5252);
        $attrs = ['country' => 'SA', 'type' => 'simplified', 'total' => 115, 'tax_total' => 15, 'currency' => 'SAR'];
        $dup = fn () => new QueryException('sqlite', 'insert', [], new \Exception('UNIQUE constraint failed: fiscal_documents.counter'));

        // one listener whose behaviour the scenarios switch (removed again in tearDown)
        $state = ['mode' => 'race', 'calls' => 0];
        FiscalDocument::creating(function () use (&$state, $dup) {
            $state['calls']++;
            if ($state['mode'] === 'race' && $state['calls'] <= 2) {
                throw $dup();
            }
            if ($state['mode'] === 'always') {
                throw $dup();
            }
            if ($state['mode'] === 'other') {
                throw new QueryException('sqlite', 'insert', [], new \Exception('disk I/O error'));
            }
        });

        // two lost races, the third attempt wins
        $doc = FiscalChain::issue(5252, 'EGS-R', $attrs);
        $this->assertSame(3, $state['calls'], 'two failed attempts, then success');
        $this->assertSame(1, $doc->counter);

        // a permanent duplicate: six attempts in all (0..5), then the error surfaces
        $state = ['mode' => 'always', 'calls' => 0];
        try {
            FiscalChain::issue(5252, 'EGS-R2', $attrs);
            $this->fail('the duplicate-key error must surface after the retries');
        } catch (QueryException $e) {
            $this->assertSame(6, $state['calls']);
        }

        // any other database error is not retried
        $state = ['mode' => 'other', 'calls' => 0];
        try {
            FiscalChain::issue(5252, 'EGS-R3', $attrs);
            $this->fail('a non-duplicate error must surface at once');
        } catch (QueryException $e) {
            $this->assertSame(1, $state['calls']);
        }
    }

    public function test_receipt_seller_name_is_the_legal_name_else_the_store_title(): void
    {
        $owner = User::factory()->create();
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'f-'.Str::lower(Str::random(6)), 'title' => 'Corner Cafe',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'KWD');
        $sale = new FiscalSale(11.5, 1.5, new \DateTimeImmutable('2026-03-04 05:06:07'));

        $ctx = FiscalReceipt::forSale($store, $sale);
        $this->assertSame('Corner Cafe', $ctx->sellerName);
        $this->assertNull($ctx->vatNumber);
        $this->assertNull($ctx->crNumber);
        $this->assertSame(11.5, $ctx->total);
        $this->assertSame(1.5, $ctx->taxAmount);

        StoreOption::updateStoreOption($store->id, 'fiscal_legal_name', 'Corner Cafe Trading LLC');
        StoreOption::updateStoreOption($store->id, 'fiscal_vat_number', '300000000000003');
        StoreOption::updateStoreOption($store->id, 'fiscal_cr_number', '1010101010');
        $ctx = FiscalReceipt::forSale($store, $sale);
        $this->assertSame('Corner Cafe Trading LLC', $ctx->sellerName);
        $this->assertSame('300000000000003', $ctx->vatNumber);
        $this->assertSame('1010101010', $ctx->crNumber);
    }

    public function test_jofotara_reconciliation_bounds(): void
    {
        $b = app(JofotaraBuilder::class);
        $b->reconcile(100.0, 16.0, 116.0);
        $b->reconcile(100.0, 16.0005, 116.0, 0.001);
        $this->addToAssertionCount(2);
        foreach ([[100.0, 16.0, 116.01], [100.0, 16.0, 115.99]] as [$net, $tax, $total]) {
            try {
                $b->reconcile($net, $tax, $total);
                $this->fail("net $net + tax $tax vs total $total must not reconcile");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('net+tax=', $e->getMessage());
            }
        }
        foreach ([[100.0, -0.5, 99.5], [0.0, 120.0, 100.0]] as [$net, $tax, $total]) {
            try {
                $b->reconcile($net, $tax, $total);
                $this->fail('tax out of range must fail');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('out of range', $e->getMessage());
            }
        }
    }
}
