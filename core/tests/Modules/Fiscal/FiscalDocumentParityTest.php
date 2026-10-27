<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Fiscal\Models\FiscalDevice;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\Eta\Contracts\EtaClient;
use Juwri\Modules\Fiscal\Services\Eta\EtaDocumentService;
use Juwri\Modules\Fiscal\Services\Eta\FakeEtaClient;
use Juwri\Modules\Fiscal\Services\Jofotara\Contracts\JofotaraClient;
use Juwri\Modules\Fiscal\Services\Jofotara\FakeJofotaraClient;
use Juwri\Modules\Fiscal\Services\Jofotara\JofotaraDocumentService;
use Juwri\Modules\Fiscal\Services\Tlv;
use Juwri\Modules\Fiscal\Services\Zatca\Contracts\ZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\FakeZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaCrypto;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaDocumentService;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaOnboarding;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Services\OrderFiscalContext;
use Ramsey\Uuid\Uuid;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-08b follow-up (J25-8) — characterisation test, written BEFORE the FiscalAdapter refactor and recorded on the
 * old code (fixtures/fiscal-document-parity.json). The refactor (documents build only from FiscalReceiptContext,
 * Ordering fills the context) must keep every byte the same: receipt context, ZATCA UBL XML, invoice hash, PIH
 * chain, 5- and 9-tag TLV QR, JoFotara UBL/JSON and the ETA receipt.
 *
 * Deterministic inputs: frozen clock, sequential UUIDs, fixed store identity. Only the parts that are random by
 * design are masked: the ECDSA signature + device public key of a production ZATCA stamp (TLV tags 7/8 and the
 * SignatureValue / X509Certificate nodes) — tags 1–6 and 9, the digest and the rest of the XML stay pinned.
 *
 * before: 2026-10-02 the `zatca` part was re-recorded once, for the coordinator-approved ZATCA issue-time fix (AST,
 * BR-KSA-70 — only IssueTime, QR tag 3 and the hashes derived from them changed; see the fixture's `_before`).
 *
 * Re-record (only on the pre-change code): FISCAL_PARITY_RECORD=1 php artisan test --filter=FiscalDocumentParityTest
 */
class FiscalDocumentParityTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/fixtures/fiscal-document-parity.json';

    private int $uuidSeq = 0;

    private string $tz = 'UTC';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_fiscalization' => true]);
        foreach (['sa', 'jo', 'eg'] as $cc) {
            config(['settings.admin_allow_fiscal_'.$cc => true]);
        }
        $this->app->instance(ZatcaClient::class, new FakeZatcaClient);
        $this->app->instance(JofotaraClient::class, new FakeJofotaraClient);
        $this->app->instance(EtaClient::class, new FakeEtaClient);
        // Pinned: another test in a serial run may leave a different app timezone behind.
        $this->tz = date_default_timezone_get();
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-02 09:30:15', 'UTC'));
        Str::createUuidsUsing(fn () => Uuid::fromString(sprintf('00000000-0000-4000-8000-%012d', ++$this->uuidSeq)));
        View::share(['settings' => (object) []]);
    }

    protected function tearDown(): void
    {
        Str::createUuidsNormally();
        Carbon::setTestNow();
        date_default_timezone_set($this->tz);
        parent::tearDown();
    }

    // ---- the only lines that change with the refactor: how a document / context is produced from an order ----

    private function context(Store $store, Order $order): array
    {
        return $this->ctxArray(OrderFiscalContext::for($store, $order));
    }

    private function zatca(Store $store, Order $order, string $device): FiscalDocument
    {
        return new ZatcaDocumentService(new ZatcaCrypto)->issue($store, OrderFiscalContext::for($store, $order), $device);
    }

    private function jofotara(Store $store, Order $order): FiscalDocument
    {
        return new JofotaraDocumentService()->issue($store, OrderFiscalContext::for($store, $order));
    }

    private function eta(Store $store, Order $order, bool $return = false, ?string $ref = null): FiscalDocument
    {
        return new EtaDocumentService()->issue($store, OrderFiscalContext::for($store, $order), 'POS-1', ['rin' => '123456789', 'branchCode' => '0'], $return, $ref);
    }

    // ---------------------------------------------------------------------------------------------------------

    public function test_zatca_documents_are_byte_identical(): void
    {
        $store = $this->store('SA', 'Riyadh Diner', ['fiscal_vat_number' => '300000000000003', 'fiscal_cr_number' => '1010101010', 'restaurant_taxx_charge' => 15]);
        $plain = $this->order($store, []);
        $items = $this->order($store, [['Kabsa', 42.5, 2], ['Tea', 3.25, 3]]);

        FiscalDevice::create(['store_id' => $store->id, 'device_key' => 'EGS-1', 'country' => 'SA', 'status' => 'new']);
        $snap = [
            'ctx_plain' => $this->context($store, $plain),
            'ctx_items' => $this->context($store, $items),
            'doc1' => $this->docArray($this->zatca($store, $plain, 'EGS-1')),
            'doc2' => $this->docArray($this->zatca($store, $items, 'EGS-1')),
        ];
        // The receipt context after a document exists picks up the stored TLV.
        $snap['ctx_after_doc'] = $this->context($store, $items->fresh());

        // Production device: 9-tag QR + XAdES stamp (signature/public key masked, everything else pinned).
        $onboard = new ZatcaOnboarding(new FakeZatcaClient, new ZatcaCrypto);
        $device = FiscalDevice::create(['store_id' => $store->id, 'device_key' => 'EGS-2', 'country' => 'SA', 'status' => 'new']);
        $onboard->requestComplianceCsid($device, '123456', ['vat' => '300000000000003', 'country' => 'SA', 'common_name' => 'EGS-2']);
        $onboard->runComplianceChecks($device->fresh(), array_fill(0, 6, ['xml' => '<Invoice/>']));
        $onboard->requestProductionCsid($device->fresh());
        $snap['doc_production'] = $this->docArray($this->zatca($store->fresh(), $this->order($store, [['Mandi', 61.0, 1]]), 'EGS-2'), true);

        $this->assertParity('zatca', $snap);
    }

    public function test_jofotara_and_eta_documents_are_byte_identical(): void
    {
        $jo = $this->store('JO', 'Amman Grill', ['fiscal_vat_number' => '12345678', 'restaurant_taxx_charge' => 16]);
        Credential::create(['store_id' => $jo->id, 'feature' => 'jofotara', 'provider' => 'jofotara', 'secrets' => ['client_id' => 'c', 'secret' => 's', 'activity_number' => 'a'], 'active' => true]);
        $eg = $this->store('EG', 'Cairo Eats', ['restaurant_taxx_charge' => 14]);
        Credential::create(['store_id' => $eg->id, 'feature' => 'eta', 'provider' => 'eta', 'secrets' => ['rin' => '123456789', 'client_id' => 'cid', 'secret' => 'sec'], 'active' => true]);

        $first = $this->eta($eg, $this->order($eg, [['Koshari', 55.0, 2]]));
        $snap = [
            'jo_ctx' => $this->context($jo, $this->order($jo, [['Mansaf', 12.75, 1]])),
            'jo_doc' => $this->docArray($this->jofotara($jo, $this->order($jo, [['Mansaf', 12.75, 1]]))),
            'eg_doc' => $this->docArray($first),
            'eg_return' => $this->docArray($this->eta($eg, $this->order($eg, [['Koshari', 55.0, 1]]), true, $first->uuid)),
        ];

        $this->assertParity('jofotara_eta', $snap);
    }

    private function assertParity(string $key, array $snap): void
    {
        $snap = json_decode(json_encode($snap), true);
        $all = is_file(self::FIXTURE) ? json_decode((string) file_get_contents(self::FIXTURE), true) : [];
        if (getenv('FISCAL_PARITY_RECORD')) {
            $all[$key] = $snap;
            @mkdir(dirname(self::FIXTURE), 0777, true);
            file_put_contents(self::FIXTURE, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            $this->markTestIncomplete("recorded $key");
        }
        $this->assertArrayHasKey($key, $all, 'parity fixture missing — record it on the pre-change code');
        // JSON columns come back in the database's key order (MySQL/PG jsonb sort keys): compare maps key-sorted.
        $this->assertSame(self::sorted($all[$key]), self::sorted($snap));
    }

    private static function sorted(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        if (! array_is_list($v)) {
            ksort($v);
        }

        return array_map(self::sorted(...), $v);
    }

    private function store(string $country, string $legal, array $options): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999])]);
        $u = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(['user_id' => $u->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => $legal.' Store', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
        StoreOption::updateStoreOption($store->id, 'fiscal_country', $country);
        StoreOption::updateStoreOption($store->id, 'fiscal_legal_name', $legal);
        foreach ($options as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }

        return $store;
    }

    /** @param list<array{0: string, 1: float, 2: int}> $lines */
    private function order(Store $store, array $lines): Order
    {
        $order = Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'new']);
        foreach ($lines as [$name, $price, $qty]) {
            $menu = Menu::create(['store_id' => $store->id, 'name' => $name, 'price' => $price, 'active' => '1']);
            OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => $qty]);
        }

        return $order->fresh();
    }

    private function ctxArray(object $ctx): array
    {
        return [
            'seller' => $ctx->sellerName, 'vat' => $ctx->vatNumber, 'cr' => $ctx->crNumber,
            'total' => $ctx->total, 'tax' => $ctx->taxAmount, 'issued' => $ctx->issuedAt->format('c'),
            'currency' => $ctx->currency, 'extra' => $ctx->extra,
        ];
    }

    private function docArray(FiscalDocument $doc, bool $maskSignature = false): array
    {
        $doc = $doc->fresh();
        $resp = $doc->authority_response ?? [];
        $payload = (string) $doc->payload;
        if ($maskSignature) {
            // P6-87: the XAdES block (signature, random test key, digests over it) and the embedded QR (tags 7/8) vary per
            // run; the invoice hash itself is compared through the `hash` column.
            $payload = (string) preg_replace('#<ext:UBLExtensions\b.*</ext:UBLExtensions>#s', '<ext:UBLExtensions>MASKED</ext:UBLExtensions>', $payload);
            $payload = (string) preg_replace('#(<cbc:ID[^>]*>QR</cbc:ID><cac:Attachment[^>]*><cbc:EmbeddedDocumentBinaryObject[^>]*>)[^<]*#', '$1MASKED', $payload);
        }
        if (isset($resp['tlv'])) {
            $tags = Tlv::decode($resp['tlv']);
            if ($maskSignature) {
                $tags[7] = 'MASKED';
                $tags[8] = 'MASKED';
                unset($resp['tlv']);
            }
            ksort($tags);
            $resp['tlv_tags'] = array_map(fn ($v) => base64_encode((string) $v), $tags);
        }

        return [
            'country' => $doc->country, 'type' => $doc->type, 'status' => $doc->status, 'number' => $doc->number,
            'seq' => $doc->seq, 'counter' => $doc->counter, 'uuid' => $doc->uuid, 'device' => $doc->device_key,
            'hash' => $doc->hash, 'previous_hash' => $doc->previous_hash, 'currency' => $doc->currency,
            'total_minor' => $doc->total_minor, 'tax_total_minor' => $doc->tax_total_minor,
            'has_order' => $doc->order_id !== null,
            'payload' => $payload, 'payload_sha256' => hash('sha256', $payload),
            'authority_response' => $resp,
        ];
    }
}
