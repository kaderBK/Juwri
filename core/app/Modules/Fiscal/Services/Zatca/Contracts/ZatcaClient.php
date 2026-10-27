<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Zatca\Contracts;

/**
 * TAX-RUN Z-P2/Z-P3: ZATCA FATOORA API surface. Implemented by the HTTP client
 * (live/sandbox) and by FakeZatcaClient (fixtures) for tests. No live calls in
 * tests — the Fake is bound in the container.
 */
interface ZatcaClient
{
    /**
     * Compliance CSID: exchange CSR + OTP for a compliance certificate + secret.
     *
     * @return array{request_id:string,certificate:string,secret:string}
     */
    public function complianceCsid(string $csrBase64, string $otp): array;

    /**
     * Run a compliance check for a sample document (called 6× during onboarding).
     *
     * @return array{status:string,validationResults?:array}
     */
    public function complianceCheck(string $certificate, string $secret, array $document): array;

    /**
     * Production CSID: exchange the compliance request id for a production CSID.
     *
     * @return array{request_id:string,certificate:string,secret:string}
     */
    public function productionCsid(string $complianceRequestId, string $certificate, string $secret): array;

    /**
     * Report a simplified (B2C) invoice — 24h SLA, returns accepted / warnings.
     *
     * @return array{status:string,response:array}
     */
    /** Renew the production certificate with a fresh CSR + a new one-time code (authenticated by the current PCSID). */
    /** @return array<string, string> */
    public function renewProductionCsid(string $certificate, string $secret, string $csrBase64, string $otp): array;

    public function reportInvoice(string $certificate, string $secret, array $document): array;

    /**
     * Clear a standard (B2B) invoice — returns the ZATCA-stamped cleared XML.
     *
     * @return array{status:string,response:array,cleared_xml?:string}
     */
    public function clearInvoice(string $certificate, string $secret, array $document): array;
}
