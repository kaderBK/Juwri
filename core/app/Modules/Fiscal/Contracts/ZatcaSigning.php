<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Contracts;

/**
 * ZATCA signing primitives for the juwri-hosted ZATCA signing service (hosted.gate:zatca): onboard an EGS unit (key
 * pair + CSR + compliance CSID), move it to production, stamp an invoice with its key and report / clear it with
 * FATOORA. Callers keep the keys in their own secret store; nothing here logs or returns a private key except
 * newUnit()'s result, which the caller stores encrypted and never echoes.
 */
interface ZatcaSigning
{
    /**
     * @param  array<string, string>  $egs  common_name, organization, organization_unit, vat, serial, …
     * @return array{private_pem: string, public_pem: string, certificate: string, secret: string, request_id: string}
     */
    public function newUnit(string $otp, array $egs): array;

    /** @return array{certificate: string, secret: string, request_id: string} */
    public function production(string $complianceRequestId, string $certificate, string $secret): array;

    /** @return array{xml: string, hash: string, signature: string, public_key: string} */
    public function stamp(string $xml, string $privatePem, string $publicPem): array;

    /**
     * @param  array{xml: string, uuid?: string, hash: string}  $document
     * @return array{status: string, response: array<string, mixed>, cleared_xml?: string|null}
     */
    public function submit(string $type, string $certificate, string $secret, array $document): array;
}
