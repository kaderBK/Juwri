<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Zatca;

use Juwri\Modules\Fiscal\Contracts\ZatcaDeviceUnits;
use Juwri\Modules\Fiscal\Services\Zatca\Contracts\ZatcaClient;

/** ZatcaDeviceUnits over the FATOORA client (FakeZatcaClient in tests). */
class ZatcaDeviceUnitService implements ZatcaDeviceUnits
{
    public function __construct(private readonly ZatcaClient $client, private readonly ZatcaCrypto $crypto) {}

    public function complianceForCsr(string $csrPem, string $otp): array
    {
        $csrPem = trim($csrPem);
        if (! str_starts_with($csrPem, '-----BEGIN CERTIFICATE REQUEST-----')) {
            throw new \InvalidArgumentException('invalid_csr');
        }
        $key = @openssl_csr_get_public_key($csrPem);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC) {
            throw new \InvalidArgumentException('invalid_csr');
        }
        $ccsid = $this->client->complianceCsid(base64_encode($csrPem), $otp);

        return ['public_pem' => (string) $details['key'], 'certificate' => $ccsid['certificate'], 'secret' => $ccsid['secret'], 'request_id' => $ccsid['request_id']];
    }

    public function complianceCheck(string $certificate, string $secret, array $document): bool
    {
        return $this->client->complianceCheck($certificate, $secret, $document)['status'] === 'PASS';
    }

    public function production(string $complianceRequestId, string $certificate, string $secret): array
    {
        $p = $this->client->productionCsid($complianceRequestId, $certificate, $secret);

        return ['certificate' => $p['certificate'], 'secret' => $p['secret'], 'request_id' => $p['request_id']];
    }

    public function verifyInvoice(string $xml, string $signatureB64, string $publicPem): array
    {
        $doc = new \DOMDocument;
        if ($xml === '' || ! @$doc->loadXML($xml, LIBXML_NONET)) {
            throw new \InvalidArgumentException('invalid_xml');
        }
        $canonical = InvoiceHash::canonicalize($xml);
        // P6-87: the stamp signs the raw invoice-hash bytes (Fatoora SDK), not the canonical XML.
        if (! $this->crypto->verify(hash('sha256', $canonical, true), $signatureB64, $publicPem)) {
            throw new \InvalidArgumentException('invalid_signature');
        }
        $xp = new \DOMXPath($doc);
        $ref = fn (string $id, string $path) => trim((string) $xp->evaluate("string(//*[local-name()='AdditionalDocumentReference'][*[local-name()='ID' and normalize-space(.)='$id']]/$path)"));
        $icv = $ref('ICV', "*[local-name()='UUID']");
        $pih = $ref('PIH', "*[local-name()='Attachment']/*[local-name()='EmbeddedDocumentBinaryObject']");
        if (! ctype_digit($icv) || (int) $icv < 1) {
            throw new \InvalidArgumentException('missing_icv');
        }
        if ($pih === '' || base64_decode($pih, true) === false) {
            throw new \InvalidArgumentException('missing_pih');
        }
        $uuid = trim((string) $xp->evaluate("string(/*/*[local-name()='UUID'])"));

        return ['hash' => base64_encode(hash('sha256', $canonical, true)), 'icv' => (int) $icv, 'pih' => $pih, 'uuid' => $uuid];
    }

    public function firstPih(): string
    {
        return \Juwri\Modules\Fiscal\Services\Zatca\InvoiceHash::FIRST_PIH;
    }

    public function report(string $certificate, string $secret, array $document): array
    {
        $r = $this->client->reportInvoice($certificate, $secret, $document);

        return ['status' => $r['status'] === 'accepted' ? 'accepted' : 'rejected', 'response' => $r['response']];
    }
}
