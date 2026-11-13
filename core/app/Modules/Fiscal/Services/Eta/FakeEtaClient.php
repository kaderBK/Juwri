<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Eta;

use Juwri\Modules\Fiscal\Services\Eta\Contracts\EtaClient;

/**
 * TAX-RUN E-P2: in-memory ETA client. Tracks the token lifecycle and returns
 * accepted/rejected fixtures. Set $rejectNext to exercise the referenceOldUUID
 * resubmit flow. Async validation is modelled as "valid" by default; the
 * notification callback path is tested via the controller endpoint directly.
 */
class FakeEtaClient implements EtaClient
{
    public bool $rejectNext = false;
    public int $authCount = 0;
    public array $calls = [];
    public string $lookupBase = 'https://preprod.eta.gov.eg/documents/';

    public function authenticatePos(array $creds): array
    {
        $this->authCount++;
        $this->calls[] = 'authenticatePos';
        if (($creds['rin'] ?? '') === '' || ($creds['clientId'] ?? '') === '') {
            throw new \RuntimeException('ETA POS authentication failed.');
        }

        return ['token' => 'TKN-' . substr(md5($creds['rin'] . $this->authCount), 0, 12), 'expiresIn' => 3600];
    }

    public function submitReceipts(string $token, array $receipts): array
    {
        $this->calls[] = 'submitReceipts';
        if ($this->rejectNext) {
            $this->rejectNext = false;

            return [
                'status' => 'rejected',
                'acceptedDocuments' => [],
                'rejectedDocuments' => array_map(fn ($r) => ['uuid' => $r['header']['uuid'] ?? '', 'error' => ['code' => 'E1']], $receipts),
                'response' => ['submissionId' => null],
            ];
        }

        return [
            'status' => 'accepted',
            'acceptedDocuments' => array_map(fn ($r) => ['uuid' => $r['header']['uuid'] ?? ''], $receipts),
            'rejectedDocuments' => [],
            'response' => ['submissionId' => 'SUB-' . substr(md5($token), 0, 8)],
        ];
    }

    public function lookupUrl(string $uuid): string
    {
        return $this->lookupBase . $uuid . '/share';
    }
}
