<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Eta;

use Juwri\Core\Models\Credential;
use Juwri\Modules\Fiscal\Contracts\FiscalAdapter;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\Contracts\FiscalDriver;
use Juwri\Modules\Fiscal\Services\Eta\Contracts\EtaClient;
use Juwri\Modules\Fiscal\Services\FiscalProfile;

/**
 * TAX-RUN E-P2: ETA FiscalDriver — report-after-print within 24h. Authenticates
 * the POS device, submits the receipt, and stores the anonymous lookup URL so
 * the printed receipt QR resolves. Async validation results (valid/invalid)
 * arrive later via the notification callback and flip the final status.
 * Credentials from the encrypted Credential vault (feature = 'eta').
 *
 * J25-8: the country's FiscalAdapter (FiscalDriver = the deprecated alias name, kept for BC).
 */
class EtaDriver implements FiscalAdapter, FiscalDriver
{
    public function __construct(private EtaClient $client) {}

    public function country(): string
    {
        return 'EG';
    }

    public function mode(): string
    {
        return FiscalProfile::MODE_REPORT;
    }

    public function submit(FiscalDocument $document): array
    {
        $creds = Credential::for('eta', $document->store_id) ?? [];
        $receipt = $document->authority_response['receipt'] ?? [];

        $token = $this->client->authenticatePos([
            'rin' => $creds['rin'] ?? '',
            'clientId' => $creds['client_id'] ?? '',
            'secret' => $creds['secret'] ?? '',
        ])['token'];

        $res = $this->client->submitReceipts($token, [$receipt]);

        if (($res['status'] ?? '') === 'accepted') {
            return ['status' => 'accepted', 'response' => array_merge($res['response'], [
                'lookup_url' => $this->client->lookupUrl((string) $document->uuid),
                'submission_id' => $res['response']['submissionId'] ?? null,
            ])];
        }

        return ['status' => 'rejected', 'response' => $res];
    }
}
