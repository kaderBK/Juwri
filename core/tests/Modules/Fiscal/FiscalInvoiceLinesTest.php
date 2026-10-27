<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use Juwri\Modules\Fiscal\Data\FiscalLine;
use Juwri\Modules\Fiscal\Data\FiscalReceiptContext;
use Juwri\Modules\Fiscal\Services\Eta\ReceiptBuilder;
use Juwri\Modules\Fiscal\Services\Export\FiscalExport;
use Juwri\Modules\Fiscal\Services\Export\TeifExport;
use Juwri\Modules\Fiscal\Services\FiscalInvoiceMath;
use Juwri\Modules\Fiscal\Services\Jofotara\JofotaraBuilder;
use Juwri\Modules\Fiscal\Services\Zatca\InvoiceHash;
use Juwri\Modules\Fiscal\Services\Zatca\UblBuilder;
use Juwri\Modules\Fiscal\Services\Zatca\UblInvoice;
use Juwri\Modules\Fiscal\Services\Zatca\XadesStamp;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaCrypto;
use Tests\TestCase;

/**
 * P6-99: golden orders for per-line fiscal documents — line net/VAT, TaxSubtotal per category, document allowances,
 * rounding, and the identity "invoice payable (+ out-of-scope amounts left off) == sale total". No DB, no network.
 */
class FiscalInvoiceLinesTest extends TestCase
{
    /** @param list<FiscalLine> $lines */
    private function ctx(array $lines, float $total, float $tax = 0.0, float $discount = 0.0, string $currency = 'SAR'): FiscalReceiptContext
    {
        return new FiscalReceiptContext(
            sellerName: 'Riyadh Diner', vatNumber: '300000000000003', crNumber: '1010101010', total: $total, taxAmount: $tax,
            issuedAt: new \DateTimeImmutable('2026-07-12T13:30:00+03:00'), currency: $currency, lines: $lines,
            discount: $discount, discountIncludesTax: true,
        );
    }

    /** @param array<string,mixed> $more */
    private function s(string $name, float $qty, float $price, float $rate = 15, array $more = []): FiscalLine
    {
        return new FiscalLine(...['name' => $name, 'quantity' => $qty, 'unitPrice' => $price, 'category' => 'S', 'rate' => $rate] + $more);
    }

    /** @param array<string,mixed> $f */
    private function zatcaXml(FiscalReceiptContext $ctx, array $f): string
    {
        return new UblBuilder()->build(new UblInvoice(
            type: 'simplified', number: 'SIM-1', uuid: '3cf5ee18-ee25-44ea-a444-2c37ba7f28be', issuedAt: $ctx->issuedAt,
            currency: 'SAR', sellerName: $ctx->sellerName, sellerVat: '300000000000003', sellerCr: '1010101010', icv: 1,
            previousHash: null, lines: [], total: $ctx->total, taxTotal: $f['tax_total'] / 100, fiscal: $f,
        ));
    }

    private function xp(string $xml): \DOMXPath
    {
        $doc = new \DOMDocument;
        $this->assertTrue($doc->loadXML($xml));
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('cac', UblBuilder::CAC);
        $xp->registerNamespace('cbc', UblBuilder::CBC);

        return $xp;
    }

    /** @param array<string,mixed> $f */
    private function assertIdentity(array $f, float $total, int $decimals = 2): void
    {
        $this->assertSame($f['line_extension'] - $f['allowance_total'], $f['tax_exclusive']);
        $this->assertSame(array_sum(array_column($f['lines'], 'net')), $f['line_extension']);
        $this->assertSame(array_sum(array_column($f['subtotals'], 'taxable')), $f['tax_exclusive'], 'TaxSubtotals cover the whole base');
        $this->assertSame(array_sum(array_column($f['subtotals'], 'tax')), $f['tax_total']);
        $this->assertSame($f['tax_exclusive'] + $f['tax_total'] + $f['rounding'], $f['payable']);
        $this->assertSame((int) round($total * 10 ** $decimals), $f['payable'] + $f['excluded'], 'payable == sale total');
    }

    public function test_single_rate_tax_exclusive_store_rounds_per_line(): void
    {
        // 2 × 42.50 + 3 × 3.25 at 15 %: line VAT 12.75 + 1.4625→1.46 = 14.21 (= round(94.75 × 15 %))
        $ctx = $this->ctx([$this->s('Kabsa', 2, 42.5), $this->s('Tea', 3, 3.25)], 108.96, 14.21);
        $f = FiscalInvoiceMath::compute($ctx, 2);
        $this->assertSame([1275, 146], array_column($f['lines'], 'tax'));
        $this->assertSame([['category' => 'S', 'rate' => 15.0, 'exemption_code' => null, 'exemption_reason' => null, 'taxable' => 9475, 'tax' => 1421]], $f['subtotals']);
        $this->assertSame(0, $f['rounding']);
        $this->assertIdentity($f, 108.96);

        $xp = $this->xp($this->zatcaXml($ctx, $f));
        $this->assertSame(2.0, $xp->evaluate('count(//cac:InvoiceLine)'));
        $this->assertSame('1.46', $xp->evaluate('string(//cac:InvoiceLine[2]/cac:TaxTotal/cbc:TaxAmount)'));
        $this->assertSame('11.21', $xp->evaluate('string(//cac:InvoiceLine[2]/cac:TaxTotal/cbc:RoundingAmount)'));
        $this->assertSame('3.25', $xp->evaluate('string(//cac:InvoiceLine[2]/cac:Price/cbc:PriceAmount)'));
        $this->assertSame('S', $xp->evaluate('string(//cac:InvoiceLine[1]/cac:Item/cac:ClassifiedTaxCategory/cbc:ID)'));
        $this->assertSame('15.00', $xp->evaluate('string(//cac:InvoiceLine[1]/cac:Item/cac:ClassifiedTaxCategory/cbc:Percent)'));
        $this->assertSame('94.75', $xp->evaluate('string(//cac:TaxTotal/cac:TaxSubtotal/cbc:TaxableAmount)'));
        $this->assertSame('14.21', $xp->evaluate('string(//cac:TaxTotal[1]/cbc:TaxAmount)'));
        $this->assertSame('14.21', $xp->evaluate('string(//cac:TaxTotal[2]/cbc:TaxAmount)'));
        $this->assertSame('108.96', $xp->evaluate('string(//cac:LegalMonetaryTotal/cbc:PayableAmount)'));
        $this->assertSame(0.0, $xp->evaluate('count(//cbc:PayableRoundingAmount)'));
    }

    public function test_mixed_standard_and_zero_rated_lines_get_one_subtotal_each(): void
    {
        $ctx = $this->ctx([
            $this->s('Burger', 1, 100),
            new FiscalLine(name: 'Baby milk', quantity: 2, unitPrice: 5, category: 'Z', exemptionCode: 'VATEX-SA-35', exemptionReason: 'Medicines and medical equipment'),
        ], 125.0, 15.0);
        $f = FiscalInvoiceMath::compute($ctx, 2);
        $this->assertCount(2, $f['subtotals']);
        $this->assertSame([10000, 1000], array_column($f['subtotals'], 'taxable'));
        $this->assertSame([1500, 0], array_column($f['subtotals'], 'tax'));
        $this->assertIdentity($f, 125.0);

        $xp = $this->xp($this->zatcaXml($ctx, $f));
        $this->assertSame(2.0, $xp->evaluate('count(//cac:TaxTotal[1]/cac:TaxSubtotal)'));
        $this->assertSame('Z', $xp->evaluate('string(//cac:TaxSubtotal[2]/cac:TaxCategory/cbc:ID)'));
        $this->assertSame('0.00', $xp->evaluate('string(//cac:TaxSubtotal[2]/cac:TaxCategory/cbc:Percent)'));
        $this->assertSame('VATEX-SA-35', $xp->evaluate('string(//cac:TaxSubtotal[2]/cac:TaxCategory/cbc:TaxExemptionReasonCode)'));
        $this->assertSame(0.0, $xp->evaluate('count(//cac:TaxSubtotal[1]/cac:TaxCategory/cbc:TaxExemptionReasonCode)'), 'S carries no exemption');
    }

    public function test_exempt_item(): void
    {
        $ctx = $this->ctx([
            $this->s('Burger', 1, 100),
            new FiscalLine(name: 'Gift card', quantity: 1, unitPrice: 50, category: 'E', exemptionCode: 'VATEX-SA-29', exemptionReason: 'Financial services'),
        ], 165.0, 15.0);
        $f = FiscalInvoiceMath::compute($ctx, 2);
        $this->assertSame(['S', 'E'], array_column($f['subtotals'], 'category'));
        $this->assertSame(0, $f['lines'][1]['tax']);
        $this->assertIdentity($f, 165.0);
        $this->assertSame('VATEX-SA-29', $this->xp($this->zatcaXml($ctx, $f))->evaluate("string(//cac:TaxSubtotal[cac:TaxCategory/cbc:ID='E']/cac:TaxCategory/cbc:TaxExemptionReasonCode)"));
    }

    public function test_line_discount_is_a_line_allowance(): void
    {
        $ctx = $this->ctx([$this->s('Burger', 2, 50, 15, ['discount' => 10.0])], 103.5, 13.5);
        $f = FiscalInvoiceMath::compute($ctx, 2);
        $this->assertSame(['gross' => 10000, 'discount' => 1000, 'net' => 9000, 'tax' => 1350], array_intersect_key($f['lines'][0], array_flip(['gross', 'discount', 'net', 'tax'])));
        $this->assertSame([], $f['allowances']);
        $this->assertIdentity($f, 103.5);

        $xp = $this->xp($this->zatcaXml($ctx, $f));
        // P6-186 (ZATCA SDK 203): an even per-unit discount is a price allowance — net price 45.00, 5.00 off per unit
        $this->assertSame(0, $xp->query('//cac:InvoiceLine/cac:AllowanceCharge')->length);
        $this->assertSame('5.00', $xp->evaluate('string(//cac:InvoiceLine/cac:Price/cac:AllowanceCharge/cbc:Amount)'));
        $this->assertSame('false', $xp->evaluate('string(//cac:InvoiceLine/cac:Price/cac:AllowanceCharge/cbc:ChargeIndicator)'));
        $this->assertSame('90.00', $xp->evaluate('string(//cac:InvoiceLine/cbc:LineExtensionAmount)'));
        $this->assertSame('45.00', $xp->evaluate('string(//cac:InvoiceLine/cac:Price/cbc:PriceAmount)'));

        // an uneven per-unit discount (10.01 over 2) stays a line allowance (BG-27)
        $ctx = $this->ctx([$this->s('Burger', 2, 50, 15, ['discount' => 10.01])], 103.49, 13.5);
        $xp = $this->xp($this->zatcaXml($ctx, FiscalInvoiceMath::compute($ctx, 2)));
        $this->assertSame('10.01', $xp->evaluate('string(//cac:InvoiceLine/cac:AllowanceCharge/cbc:Amount)'));
        $this->assertSame(0, $xp->query('//cac:InvoiceLine/cac:Price/cac:AllowanceCharge')->length);
    }

    public function test_order_discount_is_a_document_allowance_with_its_vat_share(): void
    {
        // 100 + 15 VAT, 11.50 off the tax-inclusive total (as the checkout takes it) → allowance 10.00 net + 1.50 VAT
        $ctx = $this->ctx([$this->s('Burger', 1, 100)], 103.5, 15.0, 11.5);
        $f = FiscalInvoiceMath::compute($ctx, 2);
        $this->assertSame([['category' => 'S', 'rate' => 15.0, 'exemption_code' => null, 'exemption_reason' => null, 'amount' => 1000, 'tax' => 150]], $f['allowances']);
        $this->assertSame([9000], array_column($f['subtotals'], 'taxable'));
        $this->assertSame([1350], array_column($f['subtotals'], 'tax'));
        $this->assertIdentity($f, 103.5);

        $xp = $this->xp($this->zatcaXml($ctx, $f));
        $this->assertSame('10.00', $xp->evaluate('string(/*/cac:AllowanceCharge/cbc:Amount)'));
        $this->assertSame('S', $xp->evaluate('string(/*/cac:AllowanceCharge/cac:TaxCategory/cbc:ID)'));
        $this->assertSame('10.00', $xp->evaluate('string(//cac:LegalMonetaryTotal/cbc:AllowanceTotalAmount)'));
        $this->assertSame('90.00', $xp->evaluate('string(//cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount)'));
        $this->assertSame('103.50', $xp->evaluate('string(//cac:LegalMonetaryTotal/cbc:PayableAmount)'));
    }

    public function test_order_discount_over_two_rates_splits_by_largest_remainder(): void
    {
        $ctx = $this->ctx([
            $this->s('Burger', 1, 100),
            new FiscalLine(name: 'Water', quantity: 1, unitPrice: 15, category: 'Z', exemptionCode: 'VATEX-SA-35', exemptionReason: 'x'),
        ], 120.0, 15.0, 10.0);
        $f = FiscalInvoiceMath::compute($ctx, 2);
        // weights 11500 : 1500 → 884.6 / 115.4 → 885 + 115
        $this->assertSame(1000, array_sum(array_map(fn ($a) => $a['amount'] + $a['tax'], $f['allowances'])));
        $this->assertSame([770, 115], array_column($f['allowances'], 'amount'));
        $this->assertIdentity($f, 120.0);
    }

    public function test_delivery_fee_taxed_inside_the_fee(): void
    {
        $ctx = $this->ctx([
            $this->s('Burger', 1, 100),
            new FiscalLine(name: 'Delivery', quantity: 1, unitPrice: 11.5, category: 'S', rate: 15, pricesIncludeTax: true, kind: 'fee'),
        ], 126.5, 15.0);
        $f = FiscalInvoiceMath::compute($ctx, 2);
        $this->assertSame(['net' => 1000, 'tax' => 150], array_intersect_key($f['lines'][1], array_flip(['net', 'tax'])));
        $this->assertSame(1650, $f['tax_total']);
        $this->assertIdentity($f, 126.5);
    }

    public function test_tip_is_out_of_scope_and_left_off_a_zatca_invoice(): void
    {
        $tip = new FiscalLine(name: 'Tip', quantity: 1, unitPrice: 5, category: 'O', exemptionCode: 'VATEX-SA-OOS', exemptionReason: 'Not subject to VAT', kind: 'tip');
        $ctx = $this->ctx([$this->s('Burger', 1, 100), $tip], 120.0, 15.0, 0.0);

        $z = FiscalInvoiceMath::compute($ctx, 2, dropOutOfScope: true);
        $this->assertSame(500, $z['excluded']);
        $this->assertSame(11500, $z['payable']);
        $this->assertIdentity($z, 120.0);
        $xp = $this->xp($this->zatcaXml($ctx, $z));
        $this->assertSame(1.0, $xp->evaluate('count(//cac:InvoiceLine)'));
        $this->assertSame(0.0, $xp->evaluate("count(//cac:TaxCategory[cbc:ID='O'])"), 'ZATCA: no O next to S (BR-O)');

        // elsewhere the tip is an O line with no VAT, and the discount never reduces it
        $all = FiscalInvoiceMath::compute($this->ctx([$this->s('Burger', 1, 100), $tip], 108.5, 15.0, 11.5), 2);
        $this->assertSame(0, $all['excluded']);
        $this->assertSame(['S', 'O'], array_column($all['subtotals'], 'category'));
        $this->assertSame([9000, 500], array_column($all['subtotals'], 'taxable'));
        $this->assertIdentity($all, 108.5);
    }

    public function test_tax_inclusive_store_carves_vat_out_per_line(): void
    {
        $ctx = $this->ctx([
            $this->s('Burger', 1, 115, 15, ['pricesIncludeTax' => true]),
            $this->s('Tea', 3, 3.5, 15, ['pricesIncludeTax' => true]),
        ], 125.5);
        $f = FiscalInvoiceMath::compute($ctx, 2);
        // Tea 10.50 gross: VAT round(1050 × 15/115) = 137, net 913
        $this->assertSame([1500, 137], array_column($f['lines'], 'tax'));
        $this->assertSame([10000, 913], array_column($f['lines'], 'net'));
        $this->assertSame(1637, $f['tax_total']);
        $this->assertIdentity($f, 125.5);
        $this->assertSame('3.043333', FiscalInvoiceMath::unitPrice($f['lines'][1], 2), 'a net unit price that is not a whole minor unit keeps its precision');
    }

    public function test_payable_rounding_amount_closes_a_one_unit_gap_and_a_bigger_gap_hard_fails(): void
    {
        // the checkout rounded VAT on the total (14.2125 → 14.22 by a different rule): one halala, a rounding amount
        $f = FiscalInvoiceMath::compute($this->ctx([$this->s('Kabsa', 2, 42.5), $this->s('Tea', 3, 3.25)], 108.97), 2);
        $this->assertSame(1, $f['rounding']);
        $this->assertIdentity($f, 108.97);
        $ctx = $this->ctx([$this->s('Kabsa', 2, 42.5), $this->s('Tea', 3, 3.25)], 108.97);
        $this->assertSame('0.01', $this->xp($this->zatcaXml($ctx, $f))->evaluate('string(//cbc:PayableRoundingAmount)'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Fiscal reconciliation failed');
        FiscalInvoiceMath::compute($this->ctx([$this->s('Kabsa', 2, 42.5)], 150.0), 2);
    }

    public function test_three_decimal_currencies(): void
    {
        // JOD 16 %: 12.750 → 2.040; 2 × 0.875 = 1.750 → 0.280
        $jo = $this->ctx([$this->s('Mansaf', 1, 12.75, 16), $this->s('Laban', 2, 0.875, 16)], 16.82, 2.32, 0.0, 'JOD');
        $out = new JofotaraBuilder()->build($jo, ['number' => 'INV-1']);
        $this->assertSame('14.500', $out['json']['totals']['taxExclusive']);
        $this->assertSame('2.320', $out['json']['totals']['taxAmount']);
        $this->assertSame('16.820', $out['json']['totals']['payable']);
        $this->assertSame(['2.040', '0.280'], array_column($out['json']['lines'], 'taxAmount'));
        $this->assertSame('0.875', $out['json']['lines'][1]['unitPrice']);
        $this->assertStringContainsString('<cbc:PayableAmount>16.820</cbc:PayableAmount>', $out['ubl']);
        $this->assertSame(2, substr_count($out['ubl'], '<cac:InvoiceLine>'));

        // OMR 5 %: 4.125 → 0.20625 → 0.206
        $om = $this->ctx([$this->s('Shuwa', 1, 4.125, 5)], 4.331, 0.206, 0.0, 'OMR');
        $xp = $this->xp(FiscalExport::ubl($om));
        $this->assertSame('0.206', $xp->evaluate('string(//cac:TaxTotal/cbc:TaxAmount)'));
        $this->assertSame('4.331', $xp->evaluate('string(//cac:LegalMonetaryTotal/cbc:PayableAmount)'));
        $this->assertSame('4.125', $xp->evaluate('string(//cac:InvoiceLine/cac:Price/cbc:PriceAmount)'));
        $this->assertSame('4.331', FiscalExport::omanTdd($om)['total']);

        $tn = new \DOMDocument;
        $this->assertTrue($tn->loadXML(TeifExport::teif($this->ctx([$this->s('Couscous', 1, 10.0, 19)], 11.9, 1.9, 0.0, 'TND'))));
        $this->assertSame('1.900', $tn->getElementsByTagName('TaxAmount')->item(1)?->textContent);
    }

    public function test_eta_receipt_lines_and_after_tax_receipt_discount(): void
    {
        $ctx = $this->ctx([$this->s('Koshari', 2, 55, 14), $this->s('Tea', 1, 10, 14)], 132.0, 16.8, 4.8, 'EGP');
        $r = new ReceiptBuilder()->build($ctx, ['rin' => '1', 'branchCode' => '0', 'deviceSerial' => 'P', 'receiptNumber' => '1']);
        $receipt = $r['receipt'];
        $this->assertCount(2, $receipt['itemData']);
        $this->assertSame('15.40', $receipt['itemData'][0]['taxableItems'][0]['amount']);
        $this->assertSame('16.80', $receipt['taxTotals'][0]['amount'], 'item VAT; the receipt discount is after tax');
        $this->assertSame('4.80', $receipt['extraReceiptDiscountData'][0]['amount']);
        $this->assertSame('120.00', $receipt['netAmount']);
        $this->assertSame('132.00', $receipt['totalAmount']);
        $this->assertSame(16.8, ReceiptBuilder::vat($ctx));
    }

    public function test_legacy_context_without_lines_is_one_line_with_the_given_totals(): void
    {
        $f = FiscalInvoiceMath::compute($this->ctx([], 115.0, 15.0), 2);
        $this->assertCount(1, $f['lines']);
        $this->assertSame(['net' => 10000, 'tax' => 1500, 'category' => 'S', 'rate' => 15.0], array_intersect_key($f['lines'][0], array_flip(['net', 'tax', 'category', 'rate'])));
        $this->assertIdentity($f, 115.0);

        $zero = FiscalInvoiceMath::compute($this->ctx([], 0.0, 0.0), 2);
        $this->assertSame('O', $zero['lines'][0]['category']);
        $this->assertSame(0, $zero['payable']);
    }

    public function test_multi_line_invoice_with_allowance_still_signs_and_verifies(): void
    {
        // Generated TEST-ONLY secp256k1 key and self-signed certificate (no key material in the repo).
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp256k1']);
        $csr = openssl_csr_new(['domainComponent' => ['local', 'gov', 'extgazt'], 'commonName' => 'JUWRI-TEST-ONLY-CA'], $key, ['digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256'], hexdec('1A2B3C4D5E6F'));
        openssl_pkey_export($key, $private);
        openssl_x509_export($x509, $pemCert);
        $public = (string) openssl_pkey_get_details($key)['key'];
        $cert = (string) preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pemCert);

        $ctx = $this->ctx([$this->s('Kabsa', 2, 42.5), $this->s('Tea', 3, 3.25, 15, ['discount' => 1.0]),
            new FiscalLine(name: 'Delivery', quantity: 1, unitPrice: 11.5, category: 'S', rate: 15, pricesIncludeTax: true, kind: 'fee')], 114.31, 0.0, 5.0);
        $f = FiscalInvoiceMath::compute($ctx, 2);
        $this->assertIdentity($f, 114.31);
        $xml = $this->zatcaXml($ctx, $f);
        $core = ['seller' => 'Riyadh Diner', 'vat' => '300000000000003', 'timestamp' => '2026-07-12T13:30:00',
            'total' => FiscalInvoiceMath::fmt($f['tax_inclusive'], 2), 'vat_amount' => FiscalInvoiceMath::fmt($f['tax_total'], 2)];
        $stamper = new XadesStamp(new ZatcaCrypto);
        $s = $stamper->stamp($xml, $private, $public, $cert, $core, new \DateTimeImmutable('2026-07-12T13:30:05+03:00'));

        $this->assertSame(InvoiceHash::base64($xml), $s['hash']);
        $this->assertTrue($stamper->verify($s['xml'], $s['signature'], $public));
        $this->assertFalse($stamper->verify(str_replace('>Tea<', '>Tee<', $s['xml']), $s['signature'], $public));
    }
}
