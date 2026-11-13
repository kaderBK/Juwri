<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Zatca;

use Illuminate\Support\Str;

/**
 * RB-11: the six sample documents ZATCA's compliance check wants before it issues a production certificate
 * (standard + simplified, each as invoice / credit note / debit note). Each is built with the device's own key, so the
 * check exercises the same builder and stamp as real sales. Payload shape matches FiscalDocument: xml, uuid, hash.
 */
class ZatcaComplianceSamples
{
    /**
     * P6-176: the buyer of the three STANDARD compliance samples. These documents go only to ZATCA's compliance check
     * (never to a customer, never reported); a standard document must name a buyer, so the sample uses this one, in the
     * style of the Fatoora SDK sample invoices. Real standard invoices only ever carry the buyer the merchant entered.
     */
    public const SAMPLE_BUYER = [
        'name' => 'Compliance Sample Buyer', 'vat' => '399999999800003', 'street' => 'Prince Sultan', 'building_number' => '2322',
        'additional_number' => '8228', 'district' => 'Al-Murabba', 'city' => 'Riyadh', 'postal_code' => '23333', 'country' => 'SA',
        'region' => 'Riyadh Region', // P6-186: BR-KSA-63 additional number; SDK 203 BR-KSA-10 CountrySubentity
    ];

    public function __construct(private ZatcaCrypto $crypto) {}

    /**
     * @param  array{private:string, public:string}  $keys  the device key pair
     * @param  array<string,string>  $sellerAddress  P6-176: the store's national address (required by the standard samples)
     * @return list<array{xml:string, uuid:string, hash:string, type:string}>
     */
    public function build(string $sellerName, string $vat, array $keys, ?string $certificateBody = null, array $sellerAddress = [], ?string $sellerCr = null): array
    {
        $plan = [
            ['standard', false], ['standard', true, 'credit_note'], ['standard', true, 'debit_note'],
            ['simplified', false], ['simplified', true, 'credit_note'], ['simplified', true, 'debit_note'],
        ];
        $out = [];
        $prev = null;
        foreach ($plan as $i => $p) {
            $isNote = $p[1];
            $type = $isNote ? $p[2] : $p[0];
            $uuid = (string) Str::uuid();
            $inv = new UblInvoice(
                type: $type, number: 'SAMPLE-'.($i + 1), uuid: $uuid, issuedAt: new \DateTimeImmutable('now', new \DateTimeZone('Asia/Riyadh')),
                currency: 'SAR', sellerName: $sellerName, sellerVat: $vat, sellerCr: $sellerCr, icv: $i + 1, previousHash: $prev,
                lines: [['name' => 'Sample item', 'qty' => 1.0, 'unit_price' => 100.0, 'tax_rate' => 15, 'line_total' => 100.0, 'tax_amount' => 15.0]],
                total: 115.0, taxTotal: 15.0,
                referencedNumber: $isNote ? 'SAMPLE-'.($p[0] === 'standard' ? 1 : 4) : null,
                simplifiedNote: $p[0] === 'simplified', reason: $isNote ? 'Compliance sample' : null,
                sellerAddress: $sellerAddress, buyer: $p[0] === 'standard' ? self::SAMPLE_BUYER : null,
            );
            $xml = (new UblBuilder())->build($inv);
            // P6-87: simplified samples (and their notes) carry the 9-tag QR in the XML, as Fatoora's compliance check expects.
            $qr = $inv->typeSubCode() === '0200000' ? ['seller' => $sellerName, 'vat' => $vat, 'timestamp' => KsaTime::qrTimestamp($inv->issuedAt),
                'total' => '115.00', 'vat_amount' => '15.00'] : null;
            $stamp = (new XadesStamp($this->crypto))->stamp($xml, $keys['private'], $keys['public'], $certificateBody, $qr, $inv->issuedAt);
            $prev = $stamp['hash'];
            $out[] = ['xml' => $stamp['xml'], 'uuid' => $uuid, 'hash' => $stamp['hash'], 'type' => $type];
        }

        return $out;
    }
}
