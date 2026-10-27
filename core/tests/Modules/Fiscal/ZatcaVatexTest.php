<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use Juwri\Modules\Fiscal\Services\Zatca\InvoiceHash;
use Juwri\Modules\Fiscal\Services\Zatca\UblBuilder;
use Juwri\Modules\Fiscal\Services\Zatca\UblInvoice;
use Juwri\Modules\Fiscal\Services\Zatca\Vatex;
use Tests\TestCase;

/** P6-117: tax categories and VATEX codes on every ZATCA line and subtotal. No DB, no network. */
class ZatcaVatexTest extends TestCase
{
    /** @param  list<array<string,mixed>>  $lines */
    private function xml(array $lines, float $tax): \DOMXPath
    {
        $net = array_sum(array_column($lines, 'line_total'));
        $xml = (new UblBuilder)->build(new UblInvoice(
            type: 'simplified', number: 'SIM-1', uuid: '3cf5ee18-ee25-44ea-a444-2c37ba7f28be',
            issuedAt: new \DateTimeImmutable('2026-07-12T13:30:00+03:00'), currency: 'SAR', sellerName: 'Pharma',
            sellerVat: '300000000000003', sellerCr: null, icv: 1, previousHash: null, lines: $lines, total: $net + $tax, taxTotal: $tax,
        ));
        $doc = new \DOMDocument;
        $doc->loadXML($xml);
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('cac', InvoiceHash::NS_CAC);
        $xp->registerNamespace('cbc', InvoiceHash::NS_CBC);

        return $xp;
    }

    public function test_every_line_and_subtotal_carries_its_category_and_vatex(): void
    {
        $xp = $this->xml([
            ['name' => 'Bread', 'qty' => 1, 'unit_price' => 10, 'tax_rate' => 15, 'line_total' => 10, 'tax_amount' => 1.5],
            ['name' => 'Insulin', 'qty' => 1, 'unit_price' => 20, 'tax_rate' => 0, 'line_total' => 20, 'tax_amount' => 0, 'tax_category' => 'Z'],
            ['name' => 'Card fee', 'qty' => 1, 'unit_price' => 5, 'tax_rate' => 0, 'line_total' => 5, 'tax_amount' => 0, 'tax_category' => 'E'],
            ['name' => 'Tip', 'qty' => 1, 'unit_price' => 3, 'tax_rate' => 0, 'line_total' => 3, 'tax_amount' => 0, 'tax_category' => 'O'],
        ], 1.5);
        $this->assertSame(4, $xp->query('//cac:InvoiceLine/cac:Item/cac:ClassifiedTaxCategory[cbc:ID and cbc:Percent and cac:TaxScheme/cbc:ID="VAT"]')->length);
        $this->assertSame(['S', 'Z', 'E', 'O'], array_map(fn ($n) => $n->textContent, iterator_to_array($xp->query('//cac:InvoiceLine/cac:Item/cac:ClassifiedTaxCategory/cbc:ID'))));
        $this->assertSame('15.00', $xp->evaluate('string(//cac:InvoiceLine[1]//cac:ClassifiedTaxCategory/cbc:Percent)'), 'food is S 15%');
        $sub = fn (string $c, string $f) => $xp->evaluate("string(//cac:TaxSubtotal/cac:TaxCategory[cbc:ID='$c']/cbc:$f)");
        $this->assertSame('', $sub('S', 'TaxExemptionReasonCode'));
        $this->assertSame('VATEX-SA-35', $sub('Z', 'TaxExemptionReasonCode'));
        $this->assertSame('Medicines and medical equipment', $sub('Z', 'TaxExemptionReason'));
        $this->assertSame('VATEX-SA-29', $sub('E', 'TaxExemptionReasonCode'));
        $this->assertSame('VATEX-SA-OOS', $sub('O', 'TaxExemptionReasonCode'));
        $this->assertSame('Not subject to VAT', $sub('O', 'TaxExemptionReason'));
    }

    public function test_a_code_from_another_category_is_refused(): void
    {
        $this->assertSame(['VATEX-SA-30', Vatex::CODES['VATEX-SA-30'][1]], Vatex::resolve('E', 'VATEX-SA-30', null));
        $this->assertNull(Vatex::resolve('S', 'VATEX-SA-35', null));
        $this->expectException(\RuntimeException::class);
        $this->xml([['name' => 'Pills', 'qty' => 1, 'unit_price' => 20, 'tax_rate' => 0, 'line_total' => 20, 'tax_amount' => 0,
            'tax_category' => 'Z', 'exemption_code' => 'VATEX-SA-29']], 0);
    }
}
