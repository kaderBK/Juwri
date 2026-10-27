<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Zatca;

/**
 * TAX-RUN Z-P1: canonical SHA-256 hash of a ZATCA UBL invoice.
 *
 * P6-87 (RB-11 follow-up) — ZATCA Security Features Implementation Standards, "Generate Invoice Hash": the hash input
 * is the invoice with exactly these three nodes removed (the XSL transforms the SDK applies, also listed as the
 * ds:Transforms of the invoice ds:Reference):
 *   not(//ancestor-or-self::ext:UBLExtensions)
 *   not(//ancestor-or-self::cac:Signature)
 *   not(//ancestor-or-self::cac:AdditionalDocumentReference[cbc:ID='QR'])
 * then canonicalised with C14N 1.1 (http://www.w3.org/2006/12/xml-c14n11), SHA-256, base64.
 *
 * Before P6-87 every AdditionalDocumentReference (so also ICV and PIH) and every node named "Signature" was removed,
 * so the ICV/PIH chain was outside the hash — Fatoora recomputes the hash and would reject it.
 *
 * PHP's DOM offers C14N 1.0 (inclusive) only. C14N 1.1 differs from 1.0 solely in how xml:id / xml:base / xml:lang
 * attributes are inherited into a document subset; we canonicalise the whole document and UBL invoices carry no xml:*
 * attributes, so the two outputs are byte-identical here. Whitespace-only text nodes are dropped on load; the stamped
 * XML we submit is serialised without them, so the hash Fatoora computes from the submitted bytes is the same.
 */
class InvoiceHash
{
    /**
     * P6-186 (ZATCA SDK pih.txt, KSA-13): the PIH of a device's first document — base64 of the hex SHA-256 of "0"
     * (NWZlY2Vi…). It was base64 of the raw digest, which the SDK rejects ("PIH is inValid").
     */
    public const FIRST_PIH = 'NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';

    public const NS_EXT = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';

    public const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    public const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    /** The ds:Transforms XPath expressions, in the order ZATCA lists them. */
    public const TRANSFORMS = [
        'not(//ancestor-or-self::ext:UBLExtensions)',
        'not(//ancestor-or-self::cac:Signature)',
        "not(//ancestor-or-self::cac:AdditionalDocumentReference[cbc:ID='QR'])",
    ];

    public static function base64(string $xml): string
    {
        return base64_encode(self::raw($xml));
    }

    public static function hex(string $xml): string
    {
        return bin2hex(self::raw($xml));
    }

    /** Raw 32-byte SHA-256 digest of the canonicalized invoice. */
    public static function raw(string $xml): string
    {
        $canonical = self::canonicalize($xml);

        return hash('sha256', $canonical, true);
    }

    public static function canonicalize(string $xml): string
    {
        $doc = new \DOMDocument;
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = false;
        // Suppress libxml warnings on malformed fragments; caller passes valid UBL.
        $prev = libxml_use_internal_errors(true);
        $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('ext', self::NS_EXT);
        $xp->registerNamespace('cac', self::NS_CAC);
        $xp->registerNamespace('cbc', self::NS_CBC);
        // Only direct children of the root are excluded (cac:Signature inside ds:Signature does not exist; a nested
        // AdditionalDocumentReference is not the QR one). Legacy un-namespaced <UBLExtensions> is removed too.
        $remove = [];
        foreach (['/*/ext:UBLExtensions', "/*/*[local-name()='UBLExtensions']", '/*/cac:Signature', "/*/cac:AdditionalDocumentReference[normalize-space(cbc:ID)='QR']"] as $q) {
            foreach ($xp->query($q) ?: [] as $n) {
                $remove[] = $n;
            }
        }
        foreach ($remove as $n) {
            $n->parentNode?->removeChild($n);
        }

        return (string) $doc->C14N();
    }
}
