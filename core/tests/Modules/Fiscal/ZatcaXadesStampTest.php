<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use Juwri\Modules\Fiscal\Services\Zatca\InvoiceHash;
use Juwri\Modules\Fiscal\Services\Zatca\TlvQr;
use Juwri\Modules\Fiscal\Services\Zatca\UblBuilder;
use Juwri\Modules\Fiscal\Services\Zatca\UblInvoice;
use Juwri\Modules\Fiscal\Services\Zatca\XadesStamp;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaCertificate;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaCrypto;
use Tests\TestCase;

/**
 * P6-87: the XAdES stamp against ZATCA's Security Features Implementation Standards, with a generated TEST-ONLY key and
 * self-signed certificate (never a ZATCA-issued certificate). No DB, no network.
 */
class ZatcaXadesStampTest extends TestCase
{
    private const CORE = ['seller' => 'Riyadh Diner', 'vat' => '300000000000003', 'timestamp' => '2026-07-12T13:30:00', 'total' => '115.00', 'vat_amount' => '15.00'];

    private function invoice(string $type = 'simplified'): string
    {
        return (new UblBuilder)->build(new UblInvoice(
            type: $type, number: 'SIM-000001', uuid: '3cf5ee18-ee25-44ea-a444-2c37ba7f28be',
            issuedAt: new \DateTimeImmutable('2026-07-12T13:30:00+03:00'), currency: 'SAR', sellerName: 'Riyadh Diner',
            sellerVat: '300000000000003', sellerCr: '1010101010', icv: 5, previousHash: 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==',
            lines: [['name' => 'Kabsa', 'qty' => 1, 'unit_price' => 100, 'tax_rate' => 15, 'line_total' => 100, 'tax_amount' => 15]],
            total: 115, taxTotal: 15,
            sellerAddress: ZatcaUblTest::SELLER_ADDRESS, buyer: $type === 'standard' ? ZatcaUblTest::BUYER : null, // P6-176
        ));
    }

    /** @var ?array{private:string,public:string,cert:string} */
    private static ?array $keys = null;

    /** A fresh TEST-ONLY secp256k1 key and self-signed certificate, generated once per run (no key material in the repo). */
    private function keys(): array
    {
        if (self::$keys) {
            return self::$keys;
        }
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp256k1']);
        $dn = ['domainComponent' => ['local', 'gov', 'extgazt'], 'commonName' => 'JUWRI-TEST-ONLY-CA'];
        $csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256'], hexdec('1A2B3C4D5E6F'));
        openssl_pkey_export($key, $private);
        openssl_x509_export($x509, $pem);

        return self::$keys = [
            'private' => $private,
            'public' => (string) openssl_pkey_get_details($key)['key'],
            'cert' => (string) preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem),
        ];
    }

    /** @return array{xml:string,hash:string,signature:string,public_key:string,ca_signature:string,tlv:?string} */
    private function stamp(string $type = 'simplified'): array
    {
        $k = $this->keys();

        return (new XadesStamp(new ZatcaCrypto))->stamp($this->invoice($type), $k['private'], $k['public'], $k['cert'],
            $type === 'simplified' ? self::CORE : null, new \DateTimeImmutable('2026-07-12T13:30:05+03:00'));
    }

    private function xp(string $xml): \DOMXPath
    {
        $doc = new \DOMDocument;
        $doc->loadXML($xml);
        $xp = new \DOMXPath($doc);
        foreach (['ext' => InvoiceHash::NS_EXT, 'cac' => InvoiceHash::NS_CAC, 'cbc' => InvoiceHash::NS_CBC, 'sig' => XadesStamp::NS_SIG,
            'sac' => XadesStamp::NS_SAC, 'ds' => XadesStamp::NS_DS, 'xades' => XadesStamp::NS_XADES] as $p => $ns) {
            $xp->registerNamespace($p, $ns);
        }

        return $xp;
    }

    public function test_golden_invoice_hash_is_deterministic_and_matches_the_signed_xml(): void
    {
        $s = $this->stamp();
        // Golden: the unsigned invoice hash; the stamp must not change it (it only adds excluded nodes).
        $this->assertSame(InvoiceHash::base64($this->invoice()), $s['hash']);
        $this->assertSame(InvoiceHash::base64($s['xml']), $s['hash']);
        $this->assertSame($s['hash'], $this->stamp()['hash'], 'deterministic');
        $this->assertSame($s['hash'], $this->xp($s['xml'])->evaluate("string(//ds:Reference[@Id='invoiceSignedData']/ds:DigestValue)"));
    }

    public function test_signature_verifies_with_openssl_and_tamper_fails(): void
    {
        $s = $this->stamp();
        $k = $this->keys();
        $raw = base64_decode($s['hash'], true);
        $this->assertSame(1, openssl_verify((string) $raw, (string) base64_decode($s['signature'], true), $k['public'], OPENSSL_ALGO_SHA256));
        // The public key inside the certificate verifies too (what Fatoora uses).
        $this->assertSame(1, openssl_verify((string) $raw, (string) base64_decode($s['signature'], true), openssl_pkey_get_public(ZatcaCertificate::pem($k['cert'])), OPENSSL_ALGO_SHA256));
        $stamper = new XadesStamp(new ZatcaCrypto);
        $this->assertTrue($stamper->verify($s['xml'], $s['signature'], $k['public']));
        $this->assertFalse($stamper->verify(str_replace('Kabsa', 'Kabsb', $s['xml']), $s['signature'], $k['public']));
    }

    public function test_xades_structure_and_signed_properties(): void
    {
        $s = $this->stamp();
        $k = $this->keys();
        $xp = $this->xp($s['xml']);
        $base = '/*/ext:UBLExtensions/ext:UBLExtension/ext:ExtensionContent/sig:UBLDocumentSignatures/sac:SignatureInformation/ds:Signature';
        $this->assertSame(1, $xp->query($base)->length);
        $this->assertSame('UBLExtensions', $xp->evaluate('local-name(/*/*[1])'), 'extensions are the first child');
        $this->assertSame(2, $xp->query("$base/ds:SignedInfo/ds:Reference")->length);
        $this->assertSame(InvoiceHash::TRANSFORMS, array_map(fn ($n) => $n->textContent, iterator_to_array($xp->query("$base/ds:SignedInfo/ds:Reference[1]/ds:Transforms/ds:Transform/ds:XPath"))));
        $this->assertSame($s['signature'], $xp->evaluate("string($base/ds:SignatureValue)"));
        $this->assertSame($k['cert'], $xp->evaluate("string($base/ds:KeyInfo/ds:X509Data/ds:X509Certificate)"));

        $props = "$base/ds:Object/xades:QualifyingProperties/xades:SignedProperties[@Id='xadesSignedProperties']/xades:SignedSignatureProperties";
        $this->assertSame('2026-07-12T13:30:05', $xp->evaluate("string($props/xades:SigningTime)"));
        $certDigest = base64_encode(hash('sha256', $k['cert']));
        $this->assertSame($certDigest, $xp->evaluate("string($props/xades:SigningCertificate/xades:Cert/xades:CertDigest/ds:DigestValue)"));
        $issuer = (string) ZatcaCertificate::issuerSerial($k['cert'])['issuer'];
        $this->assertStringContainsString('CN=JUWRI-TEST-ONLY-CA', $issuer);
        $this->assertSame($issuer, $xp->evaluate("string($props/xades:SigningCertificate/xades:Cert/xades:IssuerSerial/ds:X509IssuerName)"));
        $this->assertSame((string) hexdec('1A2B3C4D5E6F'), $xp->evaluate("string($props/xades:SigningCertificate/xades:Cert/xades:IssuerSerial/ds:X509SerialNumber)"));
        // P6-117: second reference = hex-then-base64 SHA-256 of the C14N of the SignedProperties node as it sits in the
        // FINAL document — recomputed here independently from the serialised XML (fresh parse, own C14N).
        $node = $xp->query("$base/ds:Object/xades:QualifyingProperties/xades:SignedProperties")->item(0);
        $c14n = (string) $node->C14N(false, false);
        $this->assertStringStartsWith('<xades:SignedProperties xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"', $c14n, 'inherited namespaces are in the digest input');
        $this->assertSame(base64_encode(hash('sha256', $c14n)), $xp->evaluate("string($base/ds:SignedInfo/ds:Reference[@URI='#xadesSignedProperties']/ds:DigestValue)"));
        // UBL cac:Signature.
        $this->assertSame('urn:oasis:names:specification:ubl:signature:Invoice', $xp->evaluate('string(/*/cac:Signature/cbc:ID)'));
    }

    /** P6-117: the digest survives a save/reload round trip and changes when a signed property is tampered with. */
    public function test_signed_properties_digest_matches_the_reparsed_document_and_detects_tamper(): void
    {
        foreach (['simplified', 'standard'] as $type) {
            $xml = $this->stamp($type)['xml'];
            $doc = new \DOMDocument;
            $doc->loadXML($xml);
            $stored = $this->xp($xml)->evaluate("string(//ds:Reference[@URI='#xadesSignedProperties']/ds:DigestValue)");
            $this->assertSame($stored, XadesStamp::signedPropertiesDigest($doc), $type);
            $tampered = new \DOMDocument;
            $tampered->loadXML(str_replace('2026-07-12T13:30:05', '2026-07-12T13:30:06', $xml));
            $this->assertNotSame($stored, XadesStamp::signedPropertiesDigest($tampered), $type);
        }
    }

    /** P6-117: SignatureValue checked with the openssl CLI against the invoice hash recomputed from the final XML. */
    public function test_signature_value_verifies_with_openssl_cli_from_the_final_xml(): void
    {
        if (! is_executable('/usr/bin/openssl') && trim((string) shell_exec('command -v openssl')) === '') {
            $this->markTestSkipped('openssl CLI not installed');
        }
        $s = $this->stamp();
        $xp = $this->xp($s['xml']);
        $dir = sys_get_temp_dir().'/zatca-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/hash.bin", InvoiceHash::raw($s['xml']));
        file_put_contents("$dir/sig.der", base64_decode($xp->evaluate('string(//ds:SignatureValue)'), true));
        $cert = $xp->evaluate('string(//ds:X509Certificate)');
        file_put_contents("$dir/cert.pem", ZatcaCertificate::pem($cert));
        exec('openssl x509 -in '.escapeshellarg("$dir/cert.pem").' -pubkey -noout > '.escapeshellarg("$dir/pub.pem"));
        $out = (string) shell_exec('openssl dgst -sha256 -verify '.escapeshellarg("$dir/pub.pem").' -signature '.escapeshellarg("$dir/sig.der").' '.escapeshellarg("$dir/hash.bin").' 2>&1');
        array_map('unlink', glob("$dir/*") ?: []);
        rmdir($dir);
        $this->assertStringContainsString('Verified OK', $out);
    }

    public function test_hash_exclusions_are_exactly_the_three_xpath_nodes(): void
    {
        $s = $this->stamp();
        $c = InvoiceHash::canonicalize($s['xml']);
        $this->assertStringNotContainsString('UBLExtensions', $c);
        $this->assertStringNotContainsString('cac:Signature', $c);
        $this->assertStringNotContainsString('<cbc:ID>QR</cbc:ID>', $c);
        // ICV and PIH stay inside the hash (before P6-87 they were wrongly stripped).
        $this->assertStringContainsString('<cbc:ID>ICV</cbc:ID>', $c);
        $this->assertStringContainsString('<cbc:ID>PIH</cbc:ID>', $c);
        $other = str_replace('<cbc:UUID>5</cbc:UUID>', '<cbc:UUID>6</cbc:UUID>', $s['xml']);
        $this->assertNotSame($s['hash'], InvoiceHash::base64($other), 'changing the ICV changes the hash');
    }

    public function test_simplified_qr_round_trip_with_tag_9_from_the_certificate(): void
    {
        $s = $this->stamp();
        $k = $this->keys();
        $embedded = $this->xp($s['xml'])->evaluate("string(/*/cac:AdditionalDocumentReference[cbc:ID='QR']/cac:Attachment/cbc:EmbeddedDocumentBinaryObject)");
        $this->assertSame($s['tlv'], $embedded);
        $d = TlvQr::decode((string) $s['tlv']);
        $this->assertSame(range(1, 9), array_keys($d));
        $this->assertSame(['Riyadh Diner', '300000000000003', '2026-07-12T13:30:00', '115.00', '15.00'], [$d[1], $d[2], $d[3], $d[4], $d[5]]);
        $this->assertSame($s['hash'], $d[6]);
        $this->assertSame($s['signature'], $d[7]);
        $this->assertSame(base64_decode($s['public_key']), $d[8]);
        // Tag 8 is the certificate's SubjectPublicKeyInfo.
        $certKey = openssl_pkey_get_details(openssl_pkey_get_public(ZatcaCertificate::pem($k['cert'])))['key'];
        $this->assertSame(base64_decode((string) preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $certKey)), $d[8]);
        // Tag 9: the certificate's signatureValue — check it is the CA signature over the TBSCertificate.
        $this->assertNotSame('', $d[9]);
        $this->assertSame(base64_encode($d[9]), $s['ca_signature']);
        $der = (string) base64_decode($k['cert']);
        $tbs = $this->tbs($der);
        $this->assertSame(1, openssl_verify($tbs, $d[9], $k['public'], OPENSSL_ALGO_SHA256), 'tag 9 is the CA signature of the certificate');
        // QR + cac:Signature placed after the PIH reference, before the supplier party (UBL order).
        $names = array_map(fn ($n) => $n->localName, iterator_to_array($this->xp($s['xml'])->query('/*/*')));
        $this->assertLessThan(array_search('AccountingSupplierParty', $names, true), array_search('Signature', $names, true));
    }

    public function test_standard_invoice_is_signed_without_qr(): void
    {
        $s = $this->stamp('standard');
        $xp = $this->xp($s['xml']);
        $this->assertNull($s['tlv']);
        $this->assertSame(0, $xp->query("/*/cac:AdditionalDocumentReference[cbc:ID='QR']")->length, 'ZATCA adds the QR on clearance');
        $this->assertSame(1, $xp->query('/*/ext:UBLExtensions//ds:SignatureValue')->length);
        $this->assertSame(1, $xp->query('/*/cac:Signature')->length);
    }

    public function test_restamping_replaces_rather_than_duplicates(): void
    {
        $k = $this->keys();
        $s = $this->stamp();
        $again = (new XadesStamp(new ZatcaCrypto))->stamp($s['xml'], $k['private'], $k['public'], $k['cert'], self::CORE);
        $xp = $this->xp($again['xml']);
        $this->assertSame($s['hash'], $again['hash']);
        $this->assertSame(1, $xp->query('/*/ext:UBLExtensions')->length);
        $this->assertSame(1, $xp->query("/*/cac:AdditionalDocumentReference[cbc:ID='QR']")->length);
        $this->assertSame(1, $xp->query('/*/cac:Signature')->length);
    }

    public function test_certificate_signature_value_rejects_garbage(): void
    {
        $this->assertNull(ZatcaCertificate::signatureValue('not-base64!!'));
        $this->assertNull(ZatcaCertificate::signatureValue(base64_encode('PRODUCTION-CERT')));
        $this->assertNull(ZatcaCertificate::issuerSerial(base64_encode('PRODUCTION-CERT')));
    }

    /** The raw TBSCertificate TLV (first element of the outer SEQUENCE). */
    private function tbs(string $der): string
    {
        $len = function (int $pos) use ($der): array {
            $l = ord($der[$pos + 1]);
            if ($l < 0x80) {
                return [$l, 2];
            }
            $n = $l & 0x7F;

            return [(int) hexdec(bin2hex(substr($der, $pos + 2, $n))), 2 + $n];
        };
        [, $h] = $len(0);
        [$l2, $h2] = $len($h);

        return substr($der, $h, $h2 + $l2);
    }
}
