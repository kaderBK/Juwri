<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Zatca;

/**
 * TAX-RUN Z-P2 / P6-87: the cryptographic stamp (XAdES-BES enveloped signature) of a ZATCA invoice.
 *
 * Follows ZATCA E-Invoicing "Security Features Implementation Standards" (sections "Cryptographic Stamp",
 * "Generate Invoice Hash", "Generate Digital Signature", "Generate Certificate Hash", "Populate the Signed Properties",
 * "Generate Signed Properties Hash", "QR Code") and the "XML Implementation Standard" (UBL extension layout,
 * BR-KSA-27 QR reference, cac:Signature). Steps:
 *   1. invoice hash  = base64(SHA-256(C14N(invoice minus UBLExtensions, cac:Signature, QR reference)))  {@see InvoiceHash}
 *   2. signature     = ECDSA(secp256k1, SHA-256) over the 32 raw invoice-hash bytes, base64 (DER). This is what the
 *      Fatoora SDK does ("sign the generated invoice hash"): it is NOT the XMLDSig "sign canonical SignedInfo" rule,
 *      and the validator checks it the SDK way. Before P6-87 the canonical XML itself was signed.
 *   3. cert digest   = base64(hex(SHA-256(certificate base64 text))) — the SDK's hex-then-base64 quirk.
 *   4. SignedProperties (SigningTime, SigningCertificate/CertDigest + IssuerSerial) and its digest, computed the same
 *      hex-then-base64 way over the element serialised with only xmlns:xades declared (the SDK / reference libraries).
 *   5. ds:Signature with SignedInfo (two references: the invoice with the three XPath transforms + C14N 1.1, and
 *      #xadesSignedProperties), SignatureValue, KeyInfo/X509Data, Object/QualifyingProperties.
 *   6. for simplified documents: the QR AdditionalDocumentReference (TLV tags 1–9) and cac:Signature are added.
 *      Standard (B2B) documents get cac:Signature but no QR — ZATCA adds the QR on clearance.
 * Before P6-87 the "signature block" was a bare <Signature><DigestValue/><SignatureValue/><X509Certificate/> in an
 * un-namespaced UBLExtensions, without SignedInfo, SignedProperties, cac:Signature, QR reference or QR tag 9.
 */
class XadesStamp
{
    public const NS_SIG = 'urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2';

    public const NS_SAC = 'urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2';

    public const NS_SBC = 'urn:oasis:names:specification:ubl:schema:xsd:SignatureBasicComponents-2';

    public const NS_DS = 'http://www.w3.org/2000/09/xmldsig#';

    public const NS_XADES = 'http://uri.etsi.org/01903/v1.3.2#';

    public const C14N11 = 'http://www.w3.org/2006/12/xml-c14n11';

    public const SHA256 = 'http://www.w3.org/2001/04/xmlenc#sha256';

    public function __construct(private ZatcaCrypto $crypto) {}

    /**
     * $certificateBody: base64 DER of the issued (compliance/production) certificate. $qrCore: QR tags 1–5; given →
     * simplified document, the 9-tag QR is built and embedded.
     *
     * @param  array{seller:string,vat:string,timestamp:string,total:string,vat_amount:string}|null  $qrCore
     * @return array{xml:string,hash:string,signature:string,public_key:string,ca_signature:string,tlv:?string}
     */
    public function stamp(string $xml, string $privatePem, string $publicPem, ?string $certificateBody = null,
        ?array $qrCore = null, ?\DateTimeInterface $signingTime = null): array
    {
        $doc = $this->load($xml);
        $root = $doc->documentElement;
        if (! $root) {
            throw new \RuntimeException('ZATCA stamp: invalid invoice XML.');
        }
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ext', InvoiceHash::NS_EXT);
        $this->removeOld($doc);

        // 1–2: hash + signature (the hash ignores everything this method adds).
        $hashRaw = InvoiceHash::raw((string) $doc->saveXML());
        $hashB64 = base64_encode($hashRaw);
        $signature = $this->crypto->sign($hashRaw, $privatePem);
        $publicDer = $this->crypto->publicKeyDer($publicPem);
        $cert = $certificateBody !== null ? (string) preg_replace('/\s+/', '', $certificateBody) : '';
        $caSignature = $cert !== '' ? (string) ZatcaCertificate::signatureValue($cert) : '';
        // Tag 8 is the public key of the certificate; our key pair is the one the certificate was issued for.
        $tlv = $qrCore !== null ? TlvQr::nineTag($qrCore, ['hash' => $hashB64, 'signature' => $signature,
            'public_key' => $publicDer, 'ca_signature' => $caSignature]) : null;

        // 3–4: signed properties.
        $time = KsaTime::local($signingTime ?? new \DateTimeImmutable)->format('Y-m-d\TH:i:s');
        $issuer = $cert !== '' ? ZatcaCertificate::issuerSerial($cert) : null;
        $certDigest = base64_encode(hash('sha256', $cert !== '' ? $cert : $publicDer));
        $props = self::signedPropertiesXml($time, $certDigest, $issuer['issuer'] ?? '', $issuer['serial'] ?? '0');

        // 5–6: assemble.
        if ($tlv !== null) {
            $this->insertQr($doc, $tlv);
        }
        $this->insertUblSignature($doc);
        $ext = $this->extensions($doc, $hashB64, $signature, $cert !== '' ? $cert : $publicDer, $props);
        $root->insertBefore($ext, $root->firstChild);
        // P6-117: digest the SignedProperties AS EMBEDDED — the node in its final place, canonicalised (inclusive C14N,
        // so the namespaces it inherits from the document are part of it, as a validator dereferencing
        // #xadesSignedProperties sees them) — never the pre-insertion string, whose bytes can differ.
        $this->setPropsDigest($doc);

        return [
            'xml' => (string) $doc->saveXML(),
            'hash' => $hashB64,
            'signature' => $signature,
            'public_key' => $publicDer,
            'ca_signature' => base64_encode($caSignature),
            'tlv' => $tlv,
        ];
    }

    /** The SignedProperties digest of a stamped document: base64(hex(SHA-256(C14N(embedded node)))) (SDK hex quirk). */
    public static function signedPropertiesDigest(\DOMDocument $doc): string
    {
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('xades', self::NS_XADES);
        $node = $xp->query("//xades:SignedProperties[@Id='xadesSignedProperties']")->item(0);
        if (! $node instanceof \DOMElement) {
            throw new \RuntimeException('ZATCA stamp: SignedProperties missing.');
        }

        return base64_encode(hash('sha256', (string) $node->C14N(false, false)));
    }

    private function setPropsDigest(\DOMDocument $doc): void
    {
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('ds', self::NS_DS);
        $dv = $xp->query("//ds:Reference[@URI='#xadesSignedProperties']/ds:DigestValue")->item(0);
        if (! $dv instanceof \DOMElement) {
            throw new \RuntimeException('ZATCA stamp: SignedProperties reference missing.');
        }
        $dv->textContent = self::signedPropertiesDigest($doc);
    }

    /** Verify a stamp: recompute the invoice hash of the stamped XML and check the ECDSA signature over it. */
    public function verify(string $stampedXml, string $signatureB64, string $publicPem): bool
    {
        return $this->crypto->verify(InvoiceHash::raw($stampedXml), $signatureB64, $publicPem);
    }

    /**
     * The xades:SignedProperties element exactly as hashed and embedded (no whitespace; only xmlns:xades declared
     * here — in the document the ds prefix is inherited from ds:Signature).
     */
    public static function signedPropertiesXml(string $signingTime, string $certDigest, string $issuer, string $serial): string
    {
        $e = fn (string $v) => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<xades:SignedProperties xmlns:xades="'.self::NS_XADES.'" Id="xadesSignedProperties">'
            .'<xades:SignedSignatureProperties>'
            .'<xades:SigningTime>'.$e($signingTime).'</xades:SigningTime>'
            .'<xades:SigningCertificate><xades:Cert><xades:CertDigest>'
            .'<ds:DigestMethod xmlns:ds="'.self::NS_DS.'" Algorithm="'.self::SHA256.'"/>'
            .'<ds:DigestValue xmlns:ds="'.self::NS_DS.'">'.$e($certDigest).'</ds:DigestValue>'
            .'</xades:CertDigest><xades:IssuerSerial>'
            .'<ds:X509IssuerName xmlns:ds="'.self::NS_DS.'">'.$e($issuer).'</ds:X509IssuerName>'
            .'<ds:X509SerialNumber xmlns:ds="'.self::NS_DS.'">'.$e($serial).'</ds:X509SerialNumber>'
            .'</xades:IssuerSerial></xades:Cert></xades:SigningCertificate>'
            .'</xades:SignedSignatureProperties></xades:SignedProperties>';
    }

    private function load(string $xml): \DOMDocument
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = false;
        $prev = libxml_use_internal_errors(true);
        $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $doc;
    }

    /** Re-stamping replaces, never duplicates, the extension / QR / cac:Signature nodes. */
    private function removeOld(\DOMDocument $doc): void
    {
        $xp = $this->xpath($doc);
        $old = [];
        foreach (["/*/*[local-name()='UBLExtensions']", '/*/cac:Signature', "/*/cac:AdditionalDocumentReference[normalize-space(cbc:ID)='QR']"] as $q) {
            foreach ($xp->query($q) ?: [] as $n) {
                $old[] = $n;
            }
        }
        foreach ($old as $n) {
            $n->parentNode?->removeChild($n);
        }
    }

    /** BR-KSA-27: the QR travels as cac:AdditionalDocumentReference[ID=QR] right after the PIH reference. */
    private function insertQr(\DOMDocument $doc, string $tlv): void
    {
        $ref = $doc->createElementNS(InvoiceHash::NS_CAC, 'cac:AdditionalDocumentReference');
        $ref->appendChild($doc->createElementNS(InvoiceHash::NS_CBC, 'cbc:ID', 'QR'));
        $att = $doc->createElementNS(InvoiceHash::NS_CAC, 'cac:Attachment');
        $bin = $doc->createElementNS(InvoiceHash::NS_CBC, 'cbc:EmbeddedDocumentBinaryObject', $tlv);
        $bin->setAttribute('mimeCode', 'text/plain');
        $att->appendChild($bin);
        $ref->appendChild($att);
        $this->insertAfterLastRef($doc, $ref);
    }

    /** cac:Signature (UBL order: after the AdditionalDocumentReferences, before AccountingSupplierParty). */
    private function insertUblSignature(\DOMDocument $doc): void
    {
        $sig = $doc->createElementNS(InvoiceHash::NS_CAC, 'cac:Signature');
        $sig->appendChild($doc->createElementNS(InvoiceHash::NS_CBC, 'cbc:ID', 'urn:oasis:names:specification:ubl:signature:Invoice'));
        $sig->appendChild($doc->createElementNS(InvoiceHash::NS_CBC, 'cbc:SignatureMethod', 'urn:oasis:names:specification:ubl:dsig:enveloped:xades'));
        $this->insertAfterLastRef($doc, $sig);
    }

    private function insertAfterLastRef(\DOMDocument $doc, \DOMElement $node): void
    {
        $root = $doc->documentElement;
        $refs = $this->xpath($doc)->query('/*/cac:AdditionalDocumentReference | /*/cac:Signature');
        $last = $refs !== false && $refs->length ? $refs->item($refs->length - 1) : null;
        $supplier = $this->xpath($doc)->query('/*/cac:AccountingSupplierParty');
        $anchor = $last !== null ? $last->nextSibling : ($supplier !== false ? $supplier->item(0) : null);
        $anchor ? $root?->insertBefore($node, $anchor) : $root?->appendChild($node);
    }

    private function extensions(\DOMDocument $doc, string $hashB64, string $signature, string $cert, string $props): \DOMElement
    {
        /** @param array<string,string> $attrs */
        $add = function (?\DOMElement $parent, string $ns, string $name, ?string $text = null, array $attrs = []) use ($doc): \DOMElement {
            $el = $doc->createElementNS($ns, $name);
            if ($text !== null) {
                $el->appendChild($doc->createTextNode($text));
            }
            foreach ($attrs as $k => $v) {
                $el->setAttribute($k, $v);
            }
            $parent?->appendChild($el);

            return $el;
        };
        $ext = InvoiceHash::NS_EXT;
        $ds = self::NS_DS;

        $exts = $add(null, $ext, 'ext:UBLExtensions');
        $one = $add($exts, $ext, 'ext:UBLExtension');
        $add($one, $ext, 'ext:ExtensionURI', 'urn:oasis:names:specification:ubl:dsig:enveloped:xades');
        $content = $add($one, $ext, 'ext:ExtensionContent');
        $docSigs = $add($content, self::NS_SIG, 'sig:UBLDocumentSignatures');
        $docSigs->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sac', self::NS_SAC);
        $docSigs->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sbc', self::NS_SBC);
        $info = $add($docSigs, self::NS_SAC, 'sac:SignatureInformation');
        $add($info, InvoiceHash::NS_CBC, 'cbc:ID', 'urn:oasis:names:specification:ubl:signature:1');
        $add($info, self::NS_SBC, 'sbc:ReferencedSignatureID', 'urn:oasis:names:specification:ubl:signature:Invoice');

        $sig = $add($info, $ds, 'ds:Signature', null, ['Id' => 'signature']);
        $si = $add($sig, $ds, 'ds:SignedInfo');
        $add($si, $ds, 'ds:CanonicalizationMethod', null, ['Algorithm' => self::C14N11]);
        $add($si, $ds, 'ds:SignatureMethod', null, ['Algorithm' => 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256']);

        // Reference 1: the invoice, with the three exclusions of InvoiceHash, then C14N 1.1.
        $r1 = $add($si, $ds, 'ds:Reference', null, ['Id' => 'invoiceSignedData', 'URI' => '']);
        $tr = $add($r1, $ds, 'ds:Transforms');
        foreach (InvoiceHash::TRANSFORMS as $expr) {
            $t = $add($tr, $ds, 'ds:Transform', null, ['Algorithm' => 'http://www.w3.org/TR/1999/REC-xpath-19991116']);
            $add($t, $ds, 'ds:XPath', $expr);
        }
        $add($tr, $ds, 'ds:Transform', null, ['Algorithm' => self::C14N11]);
        $add($r1, $ds, 'ds:DigestMethod', null, ['Algorithm' => self::SHA256]);
        $add($r1, $ds, 'ds:DigestValue', $hashB64);

        // Reference 2: the xades:SignedProperties.
        $r2 = $add($si, $ds, 'ds:Reference', null, ['Type' => 'http://www.w3.org/2000/09/xmldsig#SignatureProperties', 'URI' => '#xadesSignedProperties']);
        $add($r2, $ds, 'ds:DigestMethod', null, ['Algorithm' => self::SHA256]);
        $add($r2, $ds, 'ds:DigestValue', ''); // set by setPropsDigest() once embedded

        $add($sig, $ds, 'ds:SignatureValue', $signature);
        $add($add($add($sig, $ds, 'ds:KeyInfo'), $ds, 'ds:X509Data'), $ds, 'ds:X509Certificate', $cert);

        $qp = $add($add($sig, $ds, 'ds:Object'), self::NS_XADES, 'xades:QualifyingProperties', null, ['Target' => 'signature']);
        $frag = new \DOMDocument;
        $frag->loadXML($props, LIBXML_NONET);
        if ($frag->documentElement) {
            $qp->appendChild($doc->importNode($frag->documentElement, true));
        }

        return $exts;
    }

    private function xpath(\DOMDocument $doc): \DOMXPath
    {
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('cac', InvoiceHash::NS_CAC);
        $xp->registerNamespace('cbc', InvoiceHash::NS_CBC);

        return $xp;
    }
}
