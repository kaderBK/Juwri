<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Jofotara;

use Juwri\Modules\Fiscal\Services\Jofotara\Contracts\JofotaraClient;

/**
 * TAX-RUN J-P1: in-memory JoFotara client with realistic accepted/rejected
 * fixtures. Set $rejectNext to exercise the correction flow, or $unreachable to
 * simulate an ISTD outage (provisional-receipt path).
 */
class FakeJofotaraClient implements JofotaraClient
{
    public bool $rejectNext = false;
    public bool $unreachable = false;
    public array $calls = [];

    public function submit(array $creds, array $document): array
    {
        $this->calls[] = 'submit';

        if ($this->unreachable) {
            throw new \RuntimeException('JoFotara ISTD unreachable.');
        }

        if ($this->rejectNext) {
            $this->rejectNext = false;

            return ['status' => 'rejected', 'response' => [
                'EINV_STATUS' => 'REJECTED',
                'EINV_RESULTS' => ['errors' => [['code' => 'E-001', 'message' => 'Sample rejection']]],
            ]];
        }

        $number = $document['invoiceNumber'] ?? 'INV-1';

        return [
            'status' => 'accepted',
            'qr' => 'ISTD-QR:' . base64_encode($number . '|' . ($document['uuid'] ?? '')),
            'registrationNumber' => 'REG-' . substr(md5($number), 0, 8),
            'response' => ['EINV_STATUS' => 'SUBMITTED', 'EINV_NUM' => $number],
        ];
    }
}
