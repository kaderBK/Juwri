<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Jofotara;

use Illuminate\Support\Facades\Http;
use Juwri\Modules\Fiscal\Services\Http\FiscalHttpVerdicts;
use Juwri\Modules\Fiscal\Services\Jofotara\Contracts\JofotaraClient;

/**
 * TAX-RUN J-P2: live/sandbox JoFotara ISTD HTTP client. Only bound under an
 * explicit env flag with real creds — otherwise the Fake is served, so tests
 * never hit the network.
 */
class JofotaraHttpClient implements JofotaraClient
{
    public function __construct(private string $baseUrl) {}

    public function submit(array $creds, array $document): array
    {
        $res = Http::withHeaders([
            'Client-Id' => $creds['clientId'] ?? '',
            'Secret-Key' => $creds['secret'] ?? '',
        ])->post($this->baseUrl.'/core/invoices/', $document);

        // P6-139: a server error / rate limit / timeout is not a verdict. Before, it counted as REJECTED, which invites a
        // correction (a second invoice number) for an invoice JoFotara may already hold. It now throws, so the job
        // retries the same document (same UUID) with backoff.
        // P6-157: 409 (a conflict on the same invoice UUID = a duplicate re-send) is also not a rejection; the verdict
        // table (FiscalHttpVerdicts) holds the per-status answer.
        $verdict = FiscalHttpVerdicts::for('jofotara', $res->status());
        if ($verdict === FiscalHttpVerdicts::RETRY) {
            throw new \RuntimeException('JoFotara submission: HTTP '.$res->status().' (will retry)');
        }
        $body = $res->json() ?? [];
        $einv = is_array($body) ? (string) ($body['EINV_STATUS'] ?? '') : '';
        // P6-157: ALREADY_SUBMITTED is the ISTD's answer to a re-send of an invoice it already took (a job killed after
        // the ISTD answered, before our status write). It was counted as REJECTED, inviting a second invoice number.
        $status = match ($verdict) {
            FiscalHttpVerdicts::ACCEPTED, FiscalHttpVerdicts::REJECTED => $verdict,
            default => in_array($einv, ['SUBMITTED', 'ALREADY_SUBMITTED'], true) ? 'accepted' : 'rejected',
        };
        if ($einv === 'ALREADY_SUBMITTED') {
            $body['already_processed'] = true;
        }

        return [
            'status' => $status,
            'qr' => $body['EINV_QR'] ?? null,
            'registrationNumber' => $body['EINV_INV_UUID'] ?? null,
            'response' => $body,
        ];
    }
}
