<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Contracts;

/**
 * ZATCA EGS units whose private key never leaves the device (offline POS, OP-12, owner Q6 option A): the till generates
 * its key pair and CSR, signs simplified invoices itself and keeps its own ICV / PIH chain. The server only moves the
 * CSR through FATOORA onboarding, checks what the till signed, and reports it. No private key ever reaches this side.
 */
interface ZatcaDeviceUnits
{
    /**
     * Compliance CSID for a CSR made on the device. Throws \InvalidArgumentException for a CSR that is not a PEM
     * certificate request with an EC public key, \RuntimeException when FATOORA refuses (e.g. a bad OTP).
     *
     * @return array{public_pem: string, certificate: string, secret: string, request_id: string}
     */
    public function complianceForCsr(string $csrPem, string $otp): array;

    /**
     * Compliance check of one sample document the device signed. True when FATOORA passes it.
     *
     * @param  array{xml: string, hash: string, uuid?: string}  $document
     */
    public function complianceCheck(string $certificate, string $secret, array $document): bool;

    /** @return array{certificate: string, secret: string, request_id: string} */
    public function production(string $complianceRequestId, string $certificate, string $secret): array;

    /**
     * Check an invoice the device signed: its hash (computed here, never trusted from the device), the device's ECDSA
     * signature over the canonical invoice with the unit's public key, and the ICV / PIH / UUID it carries.
     * Throws \InvalidArgumentException('invalid_xml' | 'invalid_signature' | 'missing_icv' | 'missing_pih').
     *
     * @return array{hash: string, icv: int, pih: string, uuid: string}
     */
    public function verifyInvoice(string $xml, string $signatureB64, string $publicPem): array;

    /** PIH of a unit's first invoice (ZATCA: base64 SHA-256 of "0"). */
    public function firstPih(): string;

    /**
     * Report a simplified invoice. status is accepted | rejected; a transport failure throws.
     *
     * @param  array{xml: string, hash: string, uuid: string}  $document
     * @return array{status: string, response: array<string, mixed>}
     */
    public function report(string $certificate, string $secret, array $document): array;
}
