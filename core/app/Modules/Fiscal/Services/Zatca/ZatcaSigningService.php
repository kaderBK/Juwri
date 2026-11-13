<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Zatca;

use Juwri\Modules\Fiscal\Contracts\ZatcaSigning;
use Juwri\Modules\Fiscal\Services\Zatca\Contracts\ZatcaClient;

/** {@see ZatcaSigning} over the shipped crypto, XAdES stamp and FATOORA client (the fake one unless configured). */
final class ZatcaSigningService implements ZatcaSigning
{
    public function __construct(private readonly ZatcaCrypto $crypto, private readonly XadesStamp $xades, private readonly ZatcaClient $client) {}

    public function newUnit(string $otp, array $egs): array
    {
        $keys = $this->crypto->generateKeyPair();
        $csr = $this->crypto->generateCsr($keys['private'], $egs + ['vat' => $egs['vat'] ?? '']);
        $ccsid = $this->client->complianceCsid(base64_encode($csr), $otp);

        return ['private_pem' => $keys['private'], 'public_pem' => $keys['public'], 'certificate' => (string) $ccsid['certificate'],
            'secret' => (string) $ccsid['secret'], 'request_id' => (string) $ccsid['request_id']];
    }

    public function production(string $complianceRequestId, string $certificate, string $secret): array
    {
        $p = $this->client->productionCsid($complianceRequestId, $certificate, $secret);

        return ['certificate' => (string) $p['certificate'], 'secret' => (string) $p['secret'], 'request_id' => (string) $p['request_id']];
    }

    public function stamp(string $xml, string $privatePem, string $publicPem): array
    {
        $s = $this->xades->stamp($xml, $privatePem, $publicPem);

        return ['xml' => $s['xml'], 'hash' => $s['hash'], 'signature' => $s['signature'], 'public_key' => $s['public_key']];
    }

    public function submit(string $type, string $certificate, string $secret, array $document): array
    {
        return $type === 'standard'
            ? $this->client->clearInvoice($certificate, $secret, $document)
            : $this->client->reportInvoice($certificate, $secret, $document);
    }
}
