<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Zatca;

use Juwri\Modules\Fiscal\Services\Zatca\Contracts\ZatcaClient;

/**
 * TAX-RUN Z-P2/Z-P3: in-memory ZATCA client returning realistic fixture
 * responses modelled on the FATOORA sandbox. Deterministic; drives onboarding +
 * submission tests without any network. A caller can force rejection by setting
 * $rejectNext (e.g. to exercise the resubmit flow).
 */
class FakeZatcaClient implements ZatcaClient
{
    public bool $rejectNext = false;
    public bool $withWarnings = false;
    public array $calls = [];

    public function complianceCsid(string $csrBase64, string $otp): array
    {
        $this->calls[] = 'complianceCsid';
        if ($otp === '' || strlen($otp) < 6) {
            throw new \RuntimeException('ZATCA OTP invalid or expired.');
        }

        return [
            'request_id' => 'CMP-' . substr(md5($csrBase64), 0, 10),
            'certificate' => base64_encode('COMPLIANCE-CERT'),
            'secret' => 'compliance-secret',
        ];
    }

    /** @return array<string, string> */
    public function renewProductionCsid(string $certificate, string $secret, string $csrBase64, string $otp): array
    {
        $this->calls[] = 'renewProductionCsid';
        if ($otp === '' || strlen($otp) < 6) {
            throw new \RuntimeException('ZATCA OTP invalid or expired.');
        }

        return [
            'request_id' => 'REN-' . substr(md5($csrBase64), 0, 10),
            'certificate' => base64_encode('RENEWED-CERT'),
            'secret' => 'renewed-secret',
        ];
    }

    public function complianceCheck(string $certificate, string $secret, array $document): array
    {
        $this->calls[] = 'complianceCheck';

        return ['status' => 'PASS', 'validationResults' => ['status' => 'PASS']];
    }

    public function productionCsid(string $complianceRequestId, string $certificate, string $secret): array
    {
        $this->calls[] = 'productionCsid';

        return [
            'request_id' => 'PRD-' . substr(md5($complianceRequestId), 0, 10),
            'certificate' => base64_encode('PRODUCTION-CERT'),
            'secret' => 'production-secret',
        ];
    }

    public function reportInvoice(string $certificate, string $secret, array $document): array
    {
        $this->calls[] = 'reportInvoice';
        if ($this->rejectNext) {
            $this->rejectNext = false;

            return ['status' => 'rejected', 'response' => [
                'reportingStatus' => 'ERROR',
                'validationResults' => ['errorMessages' => [['code' => 'BR-KSA-XX', 'message' => 'Sample rejection']]],
            ]];
        }

        return ['status' => 'accepted', 'response' => [
            'reportingStatus' => $this->withWarnings ? 'REPORTED_WITH_WARNINGS' : 'REPORTED',
            'validationResults' => $this->withWarnings
                ? ['warningMessages' => [['code' => 'W1', 'message' => 'Sample warning']]]
                : ['status' => 'PASS'],
        ]];
    }

    public function clearInvoice(string $certificate, string $secret, array $document): array
    {
        $this->calls[] = 'clearInvoice';
        if ($this->rejectNext) {
            $this->rejectNext = false;

            return ['status' => 'rejected', 'response' => ['clearanceStatus' => 'NOT_CLEARED']];
        }

        $xml = $document['xml'] ?? '<Invoice/>';

        return [
            'status' => 'accepted',
            'response' => ['clearanceStatus' => 'CLEARED'],
            'cleared_xml' => "<!-- ZATCA-CLEARED -->\n" . $xml,
        ];
    }
}
