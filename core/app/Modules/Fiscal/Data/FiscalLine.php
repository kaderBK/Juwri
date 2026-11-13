<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Data;

use Juwri\Modules\Fiscal\Services\FiscalInvoiceMath;

/**
 * P6-99: one line of a sale as the selling module charged it — an item, a fee (delivery, service, tray) or an
 * out-of-scope amount (tip, donation). Amounts are MAJOR units of the sale currency, exactly as charged; Fiscal turns
 * them into invoice lines ({@see FiscalInvoiceMath}) and never reads settings for them.
 *
 * - `category`: VAT category (UNCL5305 subset): S standard, Z zero-rated, E exempt, O out of scope.
 * - `rate`: the percent the checkout applied (0 unless S).
 * - `pricesIncludeTax`: true = `unitPrice`/`amount`/`discount` are gross (VAT inside) — a tax-inclusive store, or a
 *   fee the checkout did not add VAT on top of; false = net (VAT added on top).
 * - `tax`: the VAT the checkout charged for this line (major units). Used as is when set; null = derive it from the
 *   rate (line-level rounding to the currency's minor unit).
 * - `amount`: the line amount before the line discount when it is not exactly quantity × unitPrice (a weighed line).
 * - `exemptionCode` / `exemptionReason`: required by ZATCA for Z/E/O (VATEX-SA-…).
 */
final class FiscalLine
{
    public const STANDARD = 'S';

    public const ZERO = 'Z';

    public const EXEMPT = 'E';

    public const OUT_OF_SCOPE = 'O';

    public function __construct(
        public readonly string $name,
        public readonly float $quantity,
        public readonly float $unitPrice,
        public readonly string $category = self::STANDARD,
        public readonly float $rate = 0.0,
        public readonly float $discount = 0.0,
        public readonly bool $pricesIncludeTax = false,
        public readonly ?float $tax = null,
        public readonly ?float $amount = null,
        public readonly ?string $exemptionCode = null,
        public readonly ?string $exemptionReason = null,
        public readonly string $kind = 'item',
    ) {
        if (! in_array($category, [self::STANDARD, self::ZERO, self::EXEMPT, self::OUT_OF_SCOPE], true)) {
            throw new \InvalidArgumentException("Unknown VAT category {$category}.");
        }
    }
}
