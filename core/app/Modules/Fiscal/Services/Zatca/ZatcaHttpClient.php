<?php

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Zatca;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Services\AlertDispatcher;
use Juwri\Modules\Fiscal\Services\Http\FiscalHttpVerdicts;
use Juwri\Modules\Fiscal\Services\Zatca\Contracts\ZatcaClient;

/**
 * TAX-RUN Z-P3: live/sandbox FATOORA HTTP client. Only bound when
 * `config('services.zatca.live')` is true AND real creds exist — otherwise the
 * container serves FakeZatcaClient, so tests and un-onboarded tenants never make
 * a network call. Endpoints per environment: sandbox / simulation / production.
 */
class ZatcaHttpClient implements ZatcaClient
{
    public function __construct(private string $baseUrl) {}

    /** ZATCA_BASE_URL, else the gateway of ZATCA_ENV. Production is refused unless ZATCA_LIVE is on (it only gets here live). */
    public static function resolveBaseUrl(): string
    {
        $override = (string) config('services.zatca.base_url');
        if ($override !== '') {
            return rtrim($override, '/');
        }
        $env = (string) config('services.zatca.environment', 'sandbox');
        $url = config('services.zatca.urls.'.$env);
        if (! is_string($url) || $url === '') {
            throw new ZatcaApiException("ZATCA_ENV must be sandbox, simulation or production (got '{$env}').");
        }

        return $url;
    }

    private function http(?string $certificate = null, ?string $secret = null): PendingRequest
    {
        $req = Http::acceptJson()->withHeaders(['Accept-Version' => 'V2', 'Accept-Language' => 'en'])->timeout(30)->retry(2, 500, fn ($e) => $e instanceof ConnectionException, throw: false);

        return $certificate !== null ? $req->withBasicAuth($certificate, (string) $secret) : $req;
    }

    /** @return array<string,mixed> the JSON body of a 2xx answer; anything else throws with ZATCA's own message. */
    private function ok(Response $res, string $what): array
    {
        $body = $res->json() ?? [];
        if (! $res->successful()) {
            throw new ZatcaApiException($what.' failed: '.$this->reason($res, $body), $res->status(), is_array($body) ? $body : []);
        }

        return is_array($body) ? $body : [];
    }

    /** @param  array<string, mixed>  $body */
    private function reason(Response $res, array $body): string
    {
        $msg = $body['message'] ?? $body['error'] ?? null;
        if ($msg === null && ! empty($body['errors'][0])) {
            $e = $body['errors'][0];
            $msg = is_array($e) ? ($e['message'] ?? json_encode($e)) : $e;
        }
        if ($msg === null && ! empty($body['validationResults']['errorMessages'][0])) {
            $e = $body['validationResults']['errorMessages'][0];
            $msg = is_array($e) ? (($e['code'] ?? '').' '.($e['message'] ?? '')) : $e;
        }

        return 'HTTP '.$res->status().($msg ? ' - '.mb_substr(trim((string) $msg), 0, 300) : '');
    }

    /** ZATCA wants {invoiceHash, uuid, invoice: base64(xml)}. */
    /**
     * @param  array<string, mixed>  $document
     * @return array<string, string>
     */
    private function invoiceBody(array $document): array
    {
        return [
            'invoiceHash' => (string) ($document['hash'] ?? ''),
            'uuid' => (string) ($document['uuid'] ?? ''),
            'invoice' => base64_encode((string) ($document['xml'] ?? '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $res
     * @return array<string, string>
     */
    private function csid(array $res, string $what): array
    {
        if (empty($res['binarySecurityToken']) || empty($res['secret'])) {
            throw new ZatcaApiException($what.' answered without a certificate.');
        }

        return [
            'request_id' => (string) ($res['requestID'] ?? ''),
            'certificate' => (string) $res['binarySecurityToken'],
            'secret' => (string) $res['secret'],
        ];
    }

    public function complianceCsid(string $csrBase64, string $otp): array
    {
        $res = $this->http()->withHeaders(['OTP' => $otp])->post($this->baseUrl.'/compliance', ['csr' => $csrBase64]);

        return $this->csid($this->ok($res, 'Compliance certificate request'), 'Compliance certificate request');
    }

    public function complianceCheck(string $certificate, string $secret, array $document): array
    {
        $res = $this->http($certificate, $secret)->post($this->baseUrl.'/compliance/invoices', $this->invoiceBody($document));
        $body = $this->ok($res, 'Compliance check');
        $status = (string) ($body['validationResults']['status'] ?? '');
        if ($status === '') {
            throw new ZatcaApiException('Compliance check answered without a validation status.', $res->status(), $body);
        }
        // PASS and WARNING both count as passed; ERROR does not.
        $passed = in_array($status, ['PASS', 'WARNING'], true);

        return ['status' => $passed ? 'PASS' : 'ERROR', 'validationResults' => $body['validationResults']];
    }

    public function productionCsid(string $complianceRequestId, string $certificate, string $secret): array
    {
        $res = $this->http($certificate, $secret)->post($this->baseUrl.'/production/csids', ['compliance_request_id' => $complianceRequestId]);

        return $this->csid($this->ok($res, 'Production certificate request'), 'Production certificate request');
    }

    /** @return array<string, string> */
    public function renewProductionCsid(string $certificate, string $secret, string $csrBase64, string $otp): array
    {
        $res = $this->http($certificate, $secret)->withHeaders(['OTP' => $otp])->patch($this->baseUrl.'/production/csids', ['csr' => $csrBase64]);

        return $this->csid($this->ok($res, 'Certificate renewal'), 'Certificate renewal');
    }

    /** 200/202 = accepted (202 carries warnings); 400 = rejected by validation; auth/5xx/timeouts THROW so the job retries. */
    public function reportInvoice(string $certificate, string $secret, array $document): array
    {
        $res = $this->http($certificate, $secret)->withHeaders(['Clearance-Status' => '0'])
            ->post($this->baseUrl.'/invoices/reporting/single', $this->invoiceBody($document));

        return $this->verdict($res, 'Invoice reporting', 'reportingStatus', 'REPORTED');
    }

    public function clearInvoice(string $certificate, string $secret, array $document): array
    {
        $res = $this->http($certificate, $secret)->withHeaders(['Clearance-Status' => '1'])
            ->post($this->baseUrl.'/invoices/clearance/single', $this->invoiceBody($document));
        $out = $this->verdict($res, 'Invoice clearance', 'clearanceStatus', 'CLEARED');
        $cleared = $res->json('clearedInvoice');
        $out['cleared_xml'] = is_string($cleared) && $cleared !== '' ? (base64_decode($cleared, true) ?: $cleared) : null;
        if ($res->status() === 409 && $out['status'] === 'accepted' && $out['cleared_xml'] === null) {
            // P6-139 review: a 409 on a clearance re-send carries no clearedInvoice, so the ZATCA-stamped XML the buyer must
            // receive is not stored. The document is cleared (no re-send), but flag it and alert so it is fetched by hand.
            $out['response']['cleared_xml_missing'] = true;
            Log::warning('zatca.clearance.409_without_cleared_xml');
            try {
                AlertDispatcher::send('fiscal', '[zatca-clearance-409] invoice cleared earlier but the cleared XML was not returned; fetch it from the FATOORA portal');
            } catch (\Throwable) {
                // alerting must never break submission
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function verdict(Response $res, string $what, string $statusKey, string $okValue): array
    {
        $body = $res->json() ?? [];
        $body = is_array($body) ? $body : [];
        // P6-139/P6-157: the status meaning lives in FiscalHttpVerdicts (409 = already reported/cleared -> accepted, marked;
        // 400 = rejected; any other non-2xx -> ok() throws so the job retries with the same UUID).
        $verdict = FiscalHttpVerdicts::for('zatca', $res->status());
        if ($verdict === FiscalHttpVerdicts::ACCEPTED && ! $res->successful()) {
            return ['status' => 'accepted', 'response' => $body + ['already_processed' => true]];
        }
        if ($verdict === FiscalHttpVerdicts::REJECTED || ($verdict === FiscalHttpVerdicts::BODY && ($body[$statusKey] ?? $okValue) !== $okValue)) {
            return ['status' => 'rejected', 'response' => $body];
        }
        if ($verdict === FiscalHttpVerdicts::RETRY) {
            $this->ok($res, $what);
            throw new ZatcaApiException($what.' failed: HTTP '.$res->status(), $res->status(), $body);
        }

        return ['status' => 'accepted', 'response' => $this->ok($res, $what)];
    }
}
