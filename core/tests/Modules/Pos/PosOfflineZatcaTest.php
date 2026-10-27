<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\User;
use Juwri\Modules\Fiscal\Services\Zatca\Contracts\ZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\FakeZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\InvoiceHash;
use Juwri\Modules\Fiscal\Services\Zatca\UblBuilder;
use Juwri\Modules\Fiscal\Services\Zatca\UblInvoice;
use Juwri\Modules\Fiscal\Services\Zatca\XadesStamp;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaCrypto;
use Juwri\Modules\Pos\Models\PosOfflineDevice;
use Juwri\Modules\Pos\Models\PosOfflineZatcaInvoice;
use Juwri\Modules\Pos\Models\PosOfflineZatcaUnit;
use Tests\TestCase;

/**
 * OP-12 server side: a till is its own ZATCA EGS unit (owner Q6 option A). The test plays the till: its key pair and CSR
 * are made "on the device" (here), invoices are built and signed with that key; the server never sees the private key.
 * FATOORA is the FakeZatcaClient. Off until OP-0 (pos.offline.zatca_device_signing).
 */
class PosOfflineZatcaTest extends TestCase
{
    use OfflineTillFixture;
    use RefreshDatabase;

    private FakeZatcaClient $fatoora;

    /** @var array{private: string, public: string} */
    private array $keys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        config(['pos.offline.zatca_device_signing' => true]);
        $this->fatoora = new FakeZatcaClient;
        $this->app->instance(ZatcaClient::class, $this->fatoora);
        $this->keys = app(ZatcaCrypto::class)->generateKeyPair();
    }

    /** The owner signed in on the till (web guard) + the till's device headers. */
    private function owner(array $t, string $deviceId = 'till-z-0001'): static
    {
        $h = $this->till($t, $deviceId);
        $this->flushHeaders();
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->actingAs(User::find($t['store']->user_id), 'web')->withHeaders($h);
    }

    private function csr(): string
    {
        return app(ZatcaCrypto::class)->generateCsr($this->keys['private'], ['common_name' => 'till-z-0001', 'vat' => '300000000000003']);
    }

    /** @return array{xml: string, signature: string, hash: string} an invoice signed with the till's key */
    private function signed(int $icv, ?string $pih, string $item = 'Kabsa', ?array $keys = null): array
    {
        $keys ??= $this->keys;
        $xml = (new UblBuilder)->build(new UblInvoice(type: 'simplified', number: 'T1-'.$icv, uuid: (string) Str::uuid(), issuedAt: now()->toDateTimeImmutable(),
            currency: 'SAR', sellerName: 'R', sellerVat: '300000000000003', sellerCr: null, icv: $icv, previousHash: $pih,
            lines: [['name' => $item, 'qty' => 1, 'unit_price' => 10.0, 'tax_rate' => 15, 'line_total' => 10.0, 'tax_amount' => 1.5]], total: 11.5, taxTotal: 1.5));
        $s = app(XadesStamp::class)->stamp($xml, $keys['private'], $keys['public']);

        return ['xml' => $s['xml'], 'signature' => $s['signature'], 'hash' => $s['hash']];
    }

    private function first(): string
    {
        return InvoiceHash::FIRST_PIH;
    }

    /** @return array{0: array, 1: string} the store fixture and the till id, onboarded to production */
    private function onboarded(): array
    {
        $t = $this->make();
        $this->owner($t)->postJson(route('pos.offline.zatca.onboard'), ['csr' => $this->csr(), 'otp' => '123456'])->assertCreated()->assertJsonPath('status', 'compliance');
        $this->owner($t)->postJson(route('pos.offline.zatca.compliance'), ['samples' => [$this->signed(1, null, 'Sample A'), $this->signed(1, null, 'Sample B')]])
            ->assertOk()->assertJsonPath('status', 'production')->assertJsonPath('production_certificate', base64_encode('PRODUCTION-CERT'));

        return [$t, 'till-z-0001'];
    }

    private function upload(array $t, array $invoices, string $deviceId = 'till-z-0001'): TestResponse
    {
        return $this->asStaff($t['cashier'])->withHeaders($this->till($t, $deviceId))->postJson(route('pos.offline.zatca.invoices'), ['invoices' => $invoices]);
    }

    public function test_off_by_default_every_route_is_404(): void
    {
        $t = $this->make();
        config(['pos.offline.zatca_device_signing' => false]);
        $this->owner($t)->postJson(route('pos.offline.zatca.onboard'), ['csr' => $this->csr(), 'otp' => '123456'])->assertNotFound();
        $this->owner($t)->getJson(route('pos.offline.zatca.status'))->assertNotFound();
        $this->upload($t, [$this->signed(1, null)])->assertNotFound();
        $this->artisan('pos:offline-zatca-report')->expectsOutputToContain('off')->assertSuccessful();
        $this->assertSame([], $this->fatoora->calls);
        $this->assertFalse((require config_path('pos.php'))['offline']['zatca_device_signing'], 'off unless the env turns it on (OP-0)');
    }

    public function test_onboarding_goes_through_fatoora_with_the_device_csr_and_keeps_no_private_key(): void
    {
        [$t] = $this->onboarded();
        $this->assertSame(['complianceCsid', 'complianceCheck', 'complianceCheck', 'productionCsid'], $this->fatoora->calls);
        $unit = PosOfflineZatcaUnit::query()->sole();
        $this->assertSame('production', $unit->status);
        $this->assertSame(trim($this->keys['public']), trim((string) $unit->public_key));
        $raw = (array) DB::table('pos_offline_zatca_units')->where('id', $unit->id)->first();
        $this->assertNotSame('production-secret', $raw['production_secret']);
        $this->assertSame('production-secret', $unit->production_secret);
        $this->assertStringNotContainsString('PRIVATE KEY', json_encode($raw));
        $this->assertArrayNotHasKey('production_secret', $unit->toArray());
        $this->owner($t)->getJson(route('pos.offline.zatca.status'))->assertOk()->assertJson(['status' => 'production', 'last_icv' => 0]);
    }

    public function test_onboarding_is_owner_only_and_refuses_bad_csrs_otps_and_foreign_samples(): void
    {
        $t = $this->make();
        $this->asStaff($t['manager'])->withHeaders($this->till($t, 'till-z-0001'))->postJson(route('pos.offline.zatca.onboard'), ['csr' => $this->csr(), 'otp' => '123456'])->assertForbidden();
        $this->owner($t)->postJson(route('pos.offline.zatca.onboard'), ['csr' => 'not a csr', 'otp' => '123456'])->assertStatus(422)->assertJson(['code' => 'invalid_csr']);
        $this->owner($t)->postJson(route('pos.offline.zatca.onboard'), ['csr' => $this->csr(), 'otp' => '1'])->assertStatus(422)->assertJson(['code' => 'zatca_refused']);
        $this->assertSame(0, PosOfflineZatcaUnit::query()->count());
        $this->flushHeaders();
        $this->actingAs(User::find($t['store']->user_id), 'web')->postJson(route('pos.offline.zatca.onboard'), ['csr' => $this->csr(), 'otp' => '123456'])->assertStatus(401);

        $this->owner($t)->postJson(route('pos.offline.zatca.onboard'), ['csr' => $this->csr(), 'otp' => '123456'])->assertCreated();
        $other = app(ZatcaCrypto::class)->generateKeyPair();
        $this->owner($t)->postJson(route('pos.offline.zatca.compliance'), ['samples' => [$this->signed(1, null, 'X', $other)]])->assertStatus(422)->assertJson(['code' => 'invalid_signature']);
        $this->assertSame('compliance', PosOfflineZatcaUnit::query()->sole()->status);
    }

    public function test_an_unbroken_chain_is_accepted_exactly_once_and_reported_after_sync(): void
    {
        [$t] = $this->onboarded();
        $a = $this->signed(1, $this->first());
        $b = $this->signed(2, $a['hash']);
        $c = $this->signed(3, $b['hash']);
        $r = $this->upload($t, [$a, $b, $c])->assertOk();
        $this->assertSame([['accepted', 'ok'], ['accepted', 'ok'], ['accepted', 'ok']], array_map(fn ($x) => [$x['status'], $x['chain']], $r->json('results')));
        $this->assertSame(3, $r->json('last_icv'));
        $this->assertSame('duplicate', $this->upload($t, [$b])->json('results.0.status'), 'a re-sent invoice is stored once');
        $this->assertSame(3, PosOfflineZatcaInvoice::query()->count());
        $unit = PosOfflineZatcaUnit::query()->sole();
        $this->assertSame([3, $c['hash']], [(int) $unit->last_icv, $unit->last_hash]);

        // reported after the response (afterResponse) — and by the scheduled command for anything left
        $this->artisan('pos:offline-zatca-report')->assertSuccessful();
        $this->assertSame(['reported'], PosOfflineZatcaInvoice::query()->distinct()->pluck('status')->all());
        $this->assertSame(3, count(array_filter($this->fatoora->calls, fn ($c) => $c === 'reportInvoice')));
    }

    public function test_a_gap_is_flagged_until_the_missing_invoice_arrives(): void
    {
        [$t] = $this->onboarded();
        $a = $this->signed(1, $this->first());
        $b = $this->signed(2, $a['hash']);
        $c = $this->signed(3, $b['hash']);
        $this->upload($t, [$a, $c])->assertOk()->assertJsonPath('results.1.chain', 'gap');
        $this->upload($t, [$b])->assertOk()->assertJsonPath('results.0.chain', 'ok');
        $this->assertSame(['ok', 'ok', 'ok'], PosOfflineZatcaInvoice::query()->orderBy('icv')->pluck('chain')->all(), 'the gap closes');
    }

    public function test_forks_are_flagged_but_every_issued_invoice_is_kept_and_reported(): void
    {
        [$t] = $this->onboarded();
        $a = $this->signed(1, $this->first());
        $b = $this->signed(2, $a['hash']);
        $b2 = $this->signed(2, $a['hash'], 'Shawarma');                       // a second invoice at ICV 2
        $c = $this->signed(3, base64_encode(hash('sha256', 'nope', true))); // PIH that is not the hash at ICV 2
        $this->upload($t, [$a, $b, $b2, $c])->assertOk();
        $this->assertSame([[1, 'ok'], [2, 'fork'], [2, 'fork'], [3, 'fork']], PosOfflineZatcaInvoice::query()->orderBy('icv')->orderBy('id')->get()->map(fn ($r) => [$r->icv, $r->chain])->all());
        $this->artisan('pos:offline-zatca-report')->assertSuccessful();
        $this->assertSame(4, PosOfflineZatcaInvoice::query()->where('status', 'reported')->count(), 'issued invoices always reach ZATCA');
        $owner = User::find($t['store']->user_id);
        $dev = $this->actingAs($owner, 'web')->getJson(route('pos.offline.devices', ['store' => $t['store']->id]))->json('devices.0.zatca');
        $this->assertSame(['status' => 'production', 'last_icv' => 3, 'gaps' => 0, 'forks' => 3, 'unreported' => 0, 'rejected' => 0], $dev);
    }

    public function test_tampered_or_foreign_invoices_are_refused_and_nothing_is_stored(): void
    {
        [$t] = $this->onboarded();
        $a = $this->signed(1, $this->first());
        $tampered = ['xml' => str_replace('Kabsa', 'Kabsb', $a['xml']), 'signature' => $a['signature']];
        $foreign = $this->signed(1, $this->first(), 'Kabsa', app(ZatcaCrypto::class)->generateKeyPair());
        $r = $this->upload($t, [$tampered, $foreign, ['xml' => '<x', 'signature' => 'AA==']])->assertOk();
        $this->assertSame(['invalid_signature', 'invalid_signature', 'invalid_xml'], array_column($r->json('results'), 'code'));
        $this->assertSame(0, PosOfflineZatcaInvoice::query()->count());
    }

    public function test_rejections_and_transport_errors(): void
    {
        [$t] = $this->onboarded();
        $a = $this->signed(1, $this->first());
        $this->fatoora->rejectNext = true;
        $this->upload($t, [$a])->assertOk();
        $this->artisan('pos:offline-zatca-report')->assertSuccessful();
        $row = PosOfflineZatcaInvoice::query()->sole();
        $this->assertSame('rejected', $row->status);
        $this->assertSame('ERROR', $row->report_response['reportingStatus']);

        $b = $this->signed(2, $a['hash']);
        $this->app->instance(ZatcaClient::class, new class extends FakeZatcaClient
        {
            public function reportInvoice(string $certificate, string $secret, array $document): array
            {
                throw new \RuntimeException('FATOORA down');
            }
        });
        $this->upload($t, [$b])->assertOk();
        $this->artisan('pos:offline-zatca-report')->assertSuccessful();
        $this->assertSame('pending', PosOfflineZatcaInvoice::query()->where('icv', 2)->value('status'), 'kept for the next run');
        $this->assertGreaterThanOrEqual(1, (int) PosOfflineZatcaInvoice::query()->where('icv', 2)->value('attempts'));
    }

    public function test_a_revoked_till_still_uploads_what_it_issued_and_a_wiped_till_onboards_a_new_unit(): void
    {
        [$t] = $this->onboarded();
        $a = $this->signed(1, $this->first());
        PosOfflineDevice::query()->where('device_id', 'till-z-0001')->update(['revoked_at' => now()]);
        $this->upload($t, [$a])->assertOk()->assertJsonPath('results.0.status', 'accepted');
        PosOfflineDevice::query()->where('device_id', 'till-z-0001')->update(['revoked_at' => null]);

        $this->keys = app(ZatcaCrypto::class)->generateKeyPair(); // wiped: a new key on the device
        $this->owner($t)->postJson(route('pos.offline.zatca.onboard'), ['csr' => $this->csr(), 'otp' => '123456'])->assertCreated();
        $this->assertSame(['retired', 'compliance'], PosOfflineZatcaUnit::query()->orderBy('id')->pluck('status')->all());
        $this->assertSame(1, PosOfflineZatcaInvoice::query()->count(), 'the old unit\'s invoices stay');
    }

    public function test_a_till_of_one_store_cannot_reach_another_stores_units(): void
    {
        [$a] = $this->onboarded();
        $b = $this->make();
        $hA = $this->till($a, 'till-z-0001');
        $this->asStaff($b['cashier'])->withHeaders($hA)->postJson(route('pos.offline.zatca.invoices'), ['invoices' => [$this->signed(1, $this->first())]])->assertStatus(401);
        $this->asStaff($b['cashier'])->withHeaders($this->till($b, 'till-z-0001'))->postJson(route('pos.offline.zatca.invoices'), ['invoices' => [$this->signed(1, $this->first())]])->assertStatus(409);
        $this->assertSame(0, PosOfflineZatcaInvoice::query()->count());
        $this->assertSame((int) $a['store']->account_id, (int) PosOfflineZatcaUnit::query()->withoutGlobalScopes()->sole()->account_id);
    }
}
