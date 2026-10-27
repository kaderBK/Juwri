<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Jofotara;

use Juwri\Core\Models\Credential;
use Juwri\Modules\Fiscal\Contracts\FiscalAdapter;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\Contracts\FiscalDriver;
use Juwri\Modules\Fiscal\Services\FiscalProfile;
use Juwri\Modules\Fiscal\Services\Jofotara\Contracts\JofotaraClient;

/**
 * TAX-RUN J-P2: JoFotara FiscalDriver — strict clear-before-print. Submits the
 * document to the ISTD and, on success, returns the ISTD-issued QR so the
 * receipt can render its final copy. Credentials (Client ID / Secret / Activity
 * Number) come from the encrypted Credential vault (feature = 'jofotara'), never
 * plaintext settings. On an ISTD outage the client throws, the job retries, and
 * the document stays queued — the receipt is provisional until it clears.
 *
 * J25-8: the country's FiscalAdapter (FiscalDriver = the deprecated alias name, kept for BC).
 */
class JofotaraDriver implements FiscalAdapter, FiscalDriver
{
    public function __construct(private JofotaraClient $client) {}

    public function country(): string
    {
        return 'JO';
    }

    public function mode(): string
    {
        return FiscalProfile::MODE_CLEAR;
    }

    public function submit(FiscalDocument $document): array
    {
        $creds = Credential::for('jofotara', $document->store_id) ?? [];
        $envelope = $document->authority_response['envelope'] ?? ['invoiceNumber' => $document->number, 'uuid' => $document->uuid];

        $res = $this->client->submit([
            'clientId' => $creds['client_id'] ?? '',
            'secret' => $creds['secret'] ?? '',
            'activityNumber' => $creds['activity_number'] ?? '',
        ], $envelope);

        $out = ['status' => $res['status'], 'response' => $res['response']];

        if (($res['status'] ?? '') === 'accepted' && ! empty($res['qr'])) {
            // Merge-preserved by SubmitFiscalDocumentJob → receipt renders it.
            $out['response'] = array_merge($res['response'], [
                'istd_qr' => $res['qr'],
                'registration_number' => $res['registrationNumber'] ?? null,
            ]);
        }

        return $out;
    }
}
