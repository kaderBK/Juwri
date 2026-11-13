<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Eta;

use Illuminate\Support\Facades\Http;
use Juwri\Modules\Fiscal\Services\Http\FiscalHttpVerdicts;
use Juwri\Modules\Fiscal\Services\Eta\Contracts\EtaClient;

/**
 * TAX-RUN E-P2: live/preprod ETA HTTP client. Only bound under an env flag with
 * real creds — otherwise the Fake is served so tests never hit the network.
 */
class EtaHttpClient implements EtaClient
{
    public function __construct(private string $idBase, private string $apiBase, private string $lookupBase) {}

    public function authenticatePos(array $creds): array
    {
        $res = Http::asForm()->post($this->idBase.'/connect/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $creds['clientId'] ?? '',
            'client_secret' => $creds['secret'] ?? '',
        ])->json();

        return ['token' => (string) ($res['access_token'] ?? ''), 'expiresIn' => (int) ($res['expires_in'] ?? 3600)];
    }

    public function submitReceipts(string $token, array $receipts): array
    {
        $res = Http::withToken($token)->post($this->apiBase.'/receiptsubmissions', ['receipts' => $receipts]);
        // P6-139: only a 2xx carries ETA's verdict. Before, any other answer (5xx, an expired token, ETA's 422
        // "duplicate submission" after a killed job's re-send) had no rejectedDocuments and so counted as ACCEPTED —
        // the receipt was marked done though ETA never took it. A transient answer now throws (the job retries with
        // backoff, same UUID); a plain 4xx is a rejection.
        // P6-157: status meanings live in FiscalHttpVerdicts ('eta' row).
        $verdict = FiscalHttpVerdicts::for('eta', $res->status());
        if ($verdict === FiscalHttpVerdicts::RETRY) {
            throw new \RuntimeException('ETA receipt submission: HTTP '.$res->status().' (will retry)');
        }
        if ($verdict === FiscalHttpVerdicts::REJECTED) {
            return ['status' => 'rejected', 'acceptedDocuments' => [], 'rejectedDocuments' => [], 'response' => $res->json() ?? ['http_status' => $res->status()]];
        }
        $body = $res->json() ?? [];
        $rejected = $body['rejectedDocuments'] ?? [];

        return [
            'status' => empty($rejected) ? 'accepted' : 'rejected',
            'acceptedDocuments' => $body['acceptedDocuments'] ?? [],
            'rejectedDocuments' => $rejected,
            'response' => $body,
        ];
    }

    public function lookupUrl(string $uuid): string
    {
        return rtrim($this->lookupBase, '/').'/'.$uuid.'/share';
    }
}
