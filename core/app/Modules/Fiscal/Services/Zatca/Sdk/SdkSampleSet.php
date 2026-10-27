<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Zatca\Sdk;

use Illuminate\Support\Str;
use Juwri\Modules\Fiscal\Data\FiscalLine;
use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Services\FiscalInvoiceMath;
use Juwri\Modules\Fiscal\Services\Zatca\KsaTime;
use Juwri\Modules\Fiscal\Services\Zatca\UblBuilder;
use Juwri\Modules\Fiscal\Services\Zatca\UblInvoice;
use Juwri\Modules\Fiscal\Services\Zatca\Vatex;
use Juwri\Modules\Fiscal\Services\Zatca\XadesStamp;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaComplianceSamples;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaCrypto;

/**
 * P6-150: sample documents for the owner's ZATCA SDK check, built by the app's real UblBuilder and XadesStamp.
 * Signed with a throw-away TEST key and self-signed certificate made in memory for this run. A real device key is
 * never read, written or printed. Expect the SDK to flag the certificate (it is not ZATCA-issued); every other finding is about
 * the app's XML.
 */
class SdkSampleSet
{
    /**
     * P6-176: the sample seller's national address and CRN (SDK check documents only, never a real store's). Every
     * sample carries the seller address; the standard ones (and their credit note) also carry the full buyer
     * ({@see ZatcaComplianceSamples::SAMPLE_BUYER}).
     */
    public const SELLER_ADDRESS = [
        'street' => 'King Fahd Road', 'building_number' => '1234', 'additional_number' => '5678', 'district' => 'Al Olaya',
        'city' => 'Riyadh', 'postal_code' => '12211', 'country' => 'SA',
    ];

    public const SELLER_CRN = '1010010000';

    public function __construct(private ZatcaCrypto $crypto = new ZatcaCrypto) {}

    /**
     * P6-175: the samples go through the same path as a real sale on current main: FiscalLine (as Ordering's
     * OrderFiscalContext::engineLines fills it from the J32 tax engine) → FiscalInvoiceMath → UblBuilder → XadesStamp.
     * Cases: plain standard-rated, a credit note, a line discount + a J32 levy (its own S line, VAT on top), a
     * document discount (allowance), and S + Z + E lines with their VATEX codes.
     *
     * @param  array{private:string, public:string, cert:string}|null  $keys
     * @return list<array{name:string, xml:string}>
     */
    public function build(string $sellerName = 'Juwri SDK Check', string $vat = '399999999900003', ?array $keys = null): array
    {
        // P6-186 (J47): the sandbox gate signs with its compliance CSID key + certificate instead of the throw-away test key
        $keys ??= $this->testKeys();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Riyadh'));
        $out = [];
        foreach (self::plan() as $i => [$name, $type, $lines, $discount, $ref]) {
            // P6-186: 'simplified_credit_note' / 'simplified_debit_note' = a note of a simplified (B2C) invoice (subtype 02)
            $simplifiedNote = str_starts_with($type, 'simplified_');
            $type = $simplifiedNote ? substr($type, strlen('simplified_')) : $type;
            $simplified = $type === 'simplified' || $simplifiedNote;
            $fiscal = FiscalInvoiceMath::compute(self::context($lines, $discount, $now), 2, dropOutOfScope: true);
            $inv = new UblInvoice(
                type: $type, number: 'SDK-'.($i + 1), uuid: $uuid = (string) Str::uuid(), issuedAt: $now,
                currency: 'SAR', sellerName: $sellerName, sellerVat: $vat, sellerCr: self::SELLER_CRN, icv: $i + 1, previousHash: null, // P6-186: the SDK checks every sample's PIH against its own pih.txt (first-document PIH)
                lines: [], total: FiscalInvoiceMath::major($fiscal['payable'], 2), taxTotal: FiscalInvoiceMath::major($fiscal['tax_total'], 2),
                referencedNumber: $ref, simplifiedNote: $simplifiedNote, reason: $ref ? 'SDK check sample' : null, fiscal: $fiscal,
                sellerAddress: self::SELLER_ADDRESS, buyer: $simplified ? null : ZatcaComplianceSamples::SAMPLE_BUYER,
            );
            $qr = $simplified ? ['seller' => $sellerName, 'vat' => $vat, 'timestamp' => KsaTime::qrTimestamp($now),
                'total' => FiscalInvoiceMath::fmt($fiscal['tax_inclusive'], 2), 'vat_amount' => FiscalInvoiceMath::fmt($fiscal['tax_total'], 2)] : null;
            $stamp = (new XadesStamp($this->crypto))->stamp((new UblBuilder)->build($inv), $keys['private'], $keys['public'], $keys['cert'], $qr, $now);
            $out[] = ['name' => $name, 'xml' => $stamp['xml'], 'hash' => $stamp['hash'], 'uuid' => $uuid];
        }

        return $out;
    }

    /**
     * name, type, lines, document discount (VAT inside), credited invoice.
     *
     * @return list<array{0:string, 1:string, 2:list<FiscalLine>, 3:float, 4:?string}>
     */
    public static function plan(): array
    {
        $item = new FiscalLine(name: 'Sample item', quantity: 1.0, unitPrice: 100.0, category: FiscalLine::STANDARD, rate: 15.0, tax: 15.0, amount: 100.0);
        // engine shape: 2 × 50.00 with a 10.00 line discount → 90.00 + 13.50 VAT; a 7% levy on 90.00 = 6.30 + 0.95 VAT
        $levy = [
            new FiscalLine(name: 'Grilled chicken', quantity: 2.0, unitPrice: 50.0, category: FiscalLine::STANDARD, rate: 15.0, discount: 10.0, tax: 13.5, amount: 100.0),
            new FiscalLine(name: 'Municipality fee', quantity: 1.0, unitPrice: 6.3, category: FiscalLine::STANDARD, rate: 15.0, tax: 0.95, amount: 6.3, kind: 'levy'),
        ];
        $z = Vatex::resolve(FiscalLine::ZERO, null, null);
        $e = Vatex::resolve(FiscalLine::EXEMPT, null, null);
        $mixed = [
            new FiscalLine(name: 'Sandwich', quantity: 1.0, unitPrice: 50.0, category: FiscalLine::STANDARD, rate: 15.0, tax: 7.5, amount: 50.0),
            new FiscalLine(name: 'Medicine', quantity: 2.0, unitPrice: 10.0, category: FiscalLine::ZERO, tax: 0.0, amount: 20.0, exemptionCode: $z[0] ?? null, exemptionReason: $z[1] ?? null),
            new FiscalLine(name: 'Card fee', quantity: 1.0, unitPrice: 10.0, category: FiscalLine::EXEMPT, tax: 0.0, amount: 10.0, exemptionCode: $e[0] ?? null, exemptionReason: $e[1] ?? null),
        ];

        // P6-186 (J44): the same discounted basket in both VAT modes — 3 × 1.03 (0.01 discount each) + a taxed 5.00
        // delivery. VAT per line: 0.15 × 3 + 0.75 = 1.20. Whole-order VAT: round(8.06 × 15%) = 1.21, shared by largest
        // remainder 0.16/0.15/0.15/0.75 (as TaxEngine's whole-order mode does).
        $basket = fn (array $tax): array => [
            new FiscalLine(name: 'Coffee', quantity: 1.0, unitPrice: 1.03, category: FiscalLine::STANDARD, rate: 15.0, discount: 0.01, tax: $tax[0], amount: 1.03),
            new FiscalLine(name: 'Tea', quantity: 1.0, unitPrice: 1.03, category: FiscalLine::STANDARD, rate: 15.0, discount: 0.01, tax: $tax[1], amount: 1.03),
            new FiscalLine(name: 'Water', quantity: 1.0, unitPrice: 1.03, category: FiscalLine::STANDARD, rate: 15.0, discount: 0.01, tax: $tax[2], amount: 1.03),
            new FiscalLine(name: 'Delivery', quantity: 1.0, unitPrice: 5.0, category: FiscalLine::STANDARD, rate: 15.0, tax: $tax[3], amount: 5.0, kind: 'fee'),
        ];
        $modes = [];
        foreach (['line' => [0.15, 0.15, 0.15, 0.75], 'order' => [0.16, 0.15, 0.15, 0.75]] as $mode => $tax) {
            $modes[] = ["vat-$mode-simplified", 'simplified', $basket($tax), 0.0, null];
            $modes[] = ["vat-$mode-standard", 'standard', $basket($tax), 0.0, null];
            $modes[] = ["vat-$mode-credit-note", 'credit_note', $basket($tax), 0.0, 'SDK-2'];
            $modes[] = ["vat-$mode-debit-note", 'debit_note', $basket($tax), 0.0, 'SDK-2'];
            $modes[] = ["vat-$mode-simplified-credit-note", 'simplified_credit_note', $basket($tax), 0.0, 'SDK-1'];
            $modes[] = ["vat-$mode-simplified-debit-note", 'simplified_debit_note', $basket($tax), 0.0, 'SDK-1'];
            // S + Z + E on a simplified invoice: the basket plus a zero-rated and an exempt line
            $modes[] = ["vat-$mode-simplified-mixed", 'simplified', [...$basket($tax), $mixed[1], $mixed[2]], 0.0, null];
        }

        return [...[
            ['simplified-invoice', 'simplified', [$item], 0.0, null],
            ['standard-invoice', 'standard', [$item], 0.0, null],
            ['standard-credit-note', 'credit_note', [$item], 0.0, 'SDK-2'],
            ['simplified-levy', 'simplified', $levy, 0.0, null],
            ['simplified-discount', 'simplified', [$item], 11.5, null],
            ['standard-zero-exempt', 'standard', $mixed, 0.0, null],
        ], ...$modes];
    }

    /** @param  list<FiscalLine>  $lines */
    public static function context(array $lines, float $discount, \DateTimeInterface $at): FiscalReceiptContext
    {
        $tax = 0.0;
        $total = 0.0;
        foreach ($lines as $l) {
            $gross = ($l->amount ?? $l->quantity * $l->unitPrice) - $l->discount;
            $tax += (float) $l->tax;
            $total += $gross + ($l->pricesIncludeTax ? 0.0 : (float) $l->tax);
        }

        return new FiscalReceiptContext(sellerName: 'Juwri SDK Check', vatNumber: '399999999900003', crNumber: null,
            total: round($total - $discount, 2), taxAmount: round($tax, 2), issuedAt: $at, currency: 'SAR', lines: $lines,
            discount: $discount, discountIncludesTax: true);
    }

    /** @return array{private:string, public:string, cert:string} in memory only */
    private function testKeys(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp256k1']);
        if ($key === false) {
            throw new \RuntimeException('OpenSSL could not create a secp256k1 test key.');
        }
        $dn = ['domainComponent' => ['local', 'gov', 'extgazt'], 'commonName' => 'JUWRI-SDK-CHECK-TEST-ONLY'];
        $csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
        openssl_pkey_export($key, $private);
        openssl_x509_export($x509, $pem);

        return [
            'private' => $private,
            'public' => (string) openssl_pkey_get_details($key)['key'],
            'cert' => (string) preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem),
        ];
    }
}
