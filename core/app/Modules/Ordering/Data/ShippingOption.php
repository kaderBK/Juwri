<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Data;

use Carbon\CarbonImmutable;
use Juwri\Modules\Ordering\Models\ShippingMethod;
use Juwri\Modules\Ordering\Models\ShippingZone;

/**
 * P3-08: one shipping method as offered for an address and cart now — its fee for this items subtotal (minor units
 * of `$currency`, 0 when the subtotal reaches the free-shipping threshold), the delivery window (store dates) and
 * the cut-off that window depends on (null when the method has none).
 */
final class ShippingOption
{
    public function __construct(
        public readonly ShippingMethod $method,
        public readonly ?ShippingZone $zone,
        public readonly int $feeMinor,
        public readonly string $currency,
        public readonly CarbonImmutable $earliest,
        public readonly CarbonImmutable $latest,
        public readonly ?CarbonImmutable $cutoffAt,
        public readonly bool $freeByThreshold,
    ) {}

    public function publicId(): string
    {
        return (string) $this->method->public_id;
    }
}
