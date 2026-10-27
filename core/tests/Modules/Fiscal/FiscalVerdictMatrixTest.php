<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Juwri\Core\Models\PlatformAlert;
use Juwri\Modules\Fiscal\Jobs\SubmitFiscalDocumentJob;
use Juwri\Modules\Fiscal\Models\FiscalCertificate;
use Juwri\Modules\Fiscal\Models\FiscalDevice;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\Eta\Contracts\EtaClient;
use Juwri\Modules\Fiscal\Services\Eta\EtaHttpClient;
use Juwri\Modules\Fiscal\Services\FiscalChain;
use Juwri\Modules\Fiscal\Services\FiscalDriverManager;
use Juwri\Modules\Fiscal\Services\Jofotara\Contracts\JofotaraClient;
use Juwri\Modules\Fiscal\Services\Jofotara\JofotaraHttpClient;
use Juwri\Modules\Fiscal\Services\Zatca\Contracts\ZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P6-157: table-driven authority answer → document state, through the real job, driver and HTTP client (HTTP faked).
 * For every answer: the document's state (accepted / rejected / retry = stays "submitted" and the job throws), that no
 * new invoice number is issued, and the platform alert (only when retries run out, or a ZATCA 409 clearance without
 * the cleared XML). A rejection is the merchant's to correct, not a platform alert.
 */
class FiscalVerdictMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const STORE = 957;

    /** @return array<string, array{string, string, int, array<string, mixed>, string, bool}> */
    public static function verdicts(): array
    {
        $reported = ['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'PASS']];
        $warned = ['reportingStatus' => 'REPORTED', 'validationResults' => ['status' => 'WARNING', 'warningMessages' => [['code' => 'W1']]]];
        $cleared = ['clearanceStatus' => 'CLEARED', 'clearedInvoice' => base64_encode('<Invoice>CLEARED</Invoice>')];
        $etaOk = ['submissionId' => 'S1', 'acceptedDocuments' => [['uuid' => 'x']], 'rejectedDocuments' => []];
        $etaRej = ['submissionId' => 'S1', 'acceptedDocuments' => [], 'rejectedDocuments' => [['uuid' => 'x', 'error' => ['code' => 'E']]]];

        // [authority, doc type, HTTP status, body, expected state, platform alert]
        return [
            'zatca report 200' => ['SA', 'simplified', 200, $reported, 'accepted', false],
            'zatca report 202 warnings' => ['SA', 'simplified', 202, $warned, 'accepted', false],
            'zatca report 400' => ['SA', 'simplified', 400, ['reportingStatus' => 'NOT_REPORTED', 'validationResults' => ['status' => 'ERROR']], 'rejected', false],
            'zatca report 409 already reported' => ['SA', 'simplified', 409, ['errors' => [['message' => 'already reported']]], 'accepted', false],
            'zatca report 500' => ['SA', 'simplified', 500, ['message' => 'down'], 'retry', true],
            'zatca report 503' => ['SA', 'simplified', 503, ['message' => 'down'], 'retry', true],
            'zatca report 401' => ['SA', 'simplified', 401, ['message' => 'bad csid'], 'retry', true],
            'zatca clear 200' => ['SA', 'standard', 200, $cleared, 'accepted', false],
            'zatca clear 400' => ['SA', 'standard', 400, ['clearanceStatus' => 'NOT_CLEARED'], 'rejected', false],
            'zatca clear 409 no cleared xml' => ['SA', 'standard', 409, ['errors' => [['message' => 'already cleared']]], 'accepted', true],
            'zatca clear 502' => ['SA', 'standard', 502, ['message' => 'gw'], 'retry', true],
            'eta 200' => ['EG', 'simplified', 200, $etaOk, 'accepted', false],
            'eta 202' => ['EG', 'simplified', 202, $etaOk, 'accepted', false],
            'eta 202 rejected document' => ['EG', 'simplified', 202, $etaRej, 'rejected', false],
            'eta 400' => ['EG', 'simplified', 400, ['error' => 'bad'], 'rejected', false],
            'eta 404' => ['EG', 'simplified', 404, ['error' => 'bad'], 'rejected', false],
            'eta 401' => ['EG', 'simplified', 401, ['error' => 'token'], 'retry', true],
            'eta 422 duplicate submission' => ['EG', 'simplified', 422, ['error' => 'duplicate'], 'retry', true],
            'eta 429' => ['EG', 'simplified', 429, ['error' => 'rate'], 'retry', true],
            'eta 500' => ['EG', 'simplified', 500, ['error' => 'down'], 'retry', true],
            'eta 503' => ['EG', 'simplified', 503, ['error' => 'down'], 'retry', true],
            'jofotara 200 submitted' => ['JO', 'simplified', 200, ['EINV_STATUS' => 'SUBMITTED', 'EINV_QR' => 'QR1'], 'accepted', false],
            'jofotara 200 already submitted' => ['JO', 'simplified', 200, ['EINV_STATUS' => 'ALREADY_SUBMITTED', 'EINV_QR' => 'QR1'], 'accepted', false],
            'jofotara 200 not submitted' => ['JO', 'simplified', 200, ['EINV_STATUS' => 'NOT_SUBMITTED'], 'rejected', false],
            'jofotara 400' => ['JO', 'simplified', 400, ['EINV_STATUS' => 'NOT_SUBMITTED', 'EINV_RESULTS' => ['ERRORS' => [['EINV_CODE' => 'E']]]], 'rejected', false],
            'jofotara 403' => ['JO', 'simplified', 403, ['error' => 'auth'], 'retry', true],
            'jofotara 409 duplicate' => ['JO', 'simplified', 409, ['error' => 'duplicate'], 'retry', true],
            'jofotara 429' => ['JO', 'simplified', 429, ['error' => 'rate'], 'retry', true],
            'jofotara 500' => ['JO', 'simplified', 500, ['error' => 'down'], 'retry', true],
            'jofotara 504' => ['JO', 'simplified', 504, 'Gateway timeout', 'retry', true],
        ];
    }

    /** @param  array<string, mixed>|string  $body */
    #[DataProvider('verdicts')]
    public function test_authority_answer_maps_to_the_right_document_state(string $cc, string $type, int $http, array|string $body, string $expect, bool $alert): void
    {
        $this->seedStores(self::STORE);
        $this->bindLiveClients();
        $doc = $this->issue($cc, $type);
        $before = ['count' => FiscalDocument::where('store_id', self::STORE)->count(), 'number' => $doc->number, 'seq' => $doc->seq, 'uuid' => $doc->uuid];
        Http::fake(['*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]), '*' => Http::response($body, $http)]);

        $job = new SubmitFiscalDocumentJob($doc->id);
        $thrown = null;
        try {
            $job->handle(app(FiscalDriverManager::class));
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $fresh = $doc->fresh();
        if ($expect === 'retry') {
            $this->assertNotNull($thrown, "HTTP {$http} must throw so the queue retries");
            $this->assertSame('submitted', $fresh->status);
            $this->assertSame(0, PlatformAlert::where('kind', 'fiscal')->count(), 'no alert while retries remain');
            $job->failed($thrown); // retries used up
        } else {
            $this->assertNull($thrown, $thrown?->getMessage() ?? '');
            $this->assertSame($expect, $fresh->status);
        }
        if ($expect === 'accepted') {
            $this->assertNotNull($fresh->accepted_at);
        }

        $this->assertSame($alert ? 1 : 0, PlatformAlert::where('kind', 'fiscal')->count(), 'platform alert');
        $this->assertSame($before['count'], FiscalDocument::where('store_id', self::STORE)->count(), 'no new invoice number issued');
        $this->assertSame([$before['number'], $before['seq'], $before['uuid']], [$fresh->number, $fresh->seq, $fresh->uuid]);

        // A rerun (stray duplicate job) never re-sends a settled document; a retry re-sends the same UUID.
        Http::fake(['*' => Http::response(['unexpected' => true], 500)]);
        $rerun = null;
        try {
            new SubmitFiscalDocumentJob($doc->id)->handle(app(FiscalDriverManager::class));
        } catch (\Throwable $e) {
            $rerun = $e;
        }
        if ($expect === 'retry') {
            $this->assertNotNull($rerun);
        } else {
            $this->assertNull($rerun);
            Http::assertNothingSent();
            $this->assertSame($expect, $doc->fresh()->status);
        }
        $this->assertSame($before['count'], FiscalDocument::where('store_id', self::STORE)->count());
    }

    public function test_zatca_409_on_clearance_with_the_cleared_xml_stores_it_and_does_not_alert(): void
    {
        $this->seedStores(self::STORE);
        $this->bindLiveClients();
        $doc = $this->issue('SA', 'standard');
        Http::fake(['*' => Http::response(['clearedInvoice' => base64_encode('<Invoice>STAMPED</Invoice>')], 409)]);
        new SubmitFiscalDocumentJob($doc->id)->handle(app(FiscalDriverManager::class));

        $this->assertSame('accepted', $doc->fresh()->status);
        $this->assertSame('<Invoice>STAMPED</Invoice>', $doc->fresh()->payload);
        $this->assertSame(0, PlatformAlert::where('kind', 'fiscal')->count());
    }

    private function bindLiveClients(): void
    {
        $this->app->instance(ZatcaClient::class, new ZatcaHttpClient('https://gw.zatca.test/api'));
        $this->app->instance(EtaClient::class, new EtaHttpClient('https://id.eta.test', 'https://api.eta.test', 'https://lookup.eta.test'));
        $this->app->instance(JofotaraClient::class, new JofotaraHttpClient('https://backend.jofotara.test'));
        $this->app->forgetInstance(FiscalDriverManager::class);
    }

    private function issue(string $cc, string $type): FiscalDocument
    {
        $key = 'DEV-'.$cc;
        FiscalDevice::create(['store_id' => self::STORE, 'device_key' => $key, 'country' => $cc, 'status' => 'production']);
        FiscalCertificate::create(['store_id' => self::STORE, 'device_key' => $key, 'kind' => 'pcsid', 'pem' => 'CERT', 'active' => true, 'meta' => ['secret' => 's']]);
        $currency = ['SA' => 'SAR', 'EG' => 'EGP', 'JO' => 'JOD'][$cc];

        $doc = FiscalChain::issue(self::STORE, $key, [
            'country' => $cc, 'type' => $type, 'total' => 115, 'tax_total' => 15, 'currency' => $currency,
            'payload' => '<Invoice><cbc:ID>P6157</cbc:ID></Invoice>',
        ]);
        if ($cc === 'EG') {
            $doc->update(['authority_response' => ['receipt' => ['header' => ['uuid' => $doc->uuid]]]]);
        }

        return $doc->fresh();
    }
}
