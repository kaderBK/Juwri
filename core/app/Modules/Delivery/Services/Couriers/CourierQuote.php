<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Services\Couriers;

/**
 * T4: a courier's delivery estimate for an order — fee + ETA + an opaque quote id the
 * provider references when the delivery is actually created. Feasible=false means the
 * courier can't serve this drop (out of range / no couriers).
 */
class CourierQuote
{
    public function __construct(
        public string $provider,
        public float $fee,
        public ?int $etaMinutes = null,
        public ?string $quoteId = null,
        public bool $feasible = true,
        public string $currency = 'USD',
    ) {
    }

    public function toArray(): array
    {
        return [
            'provider'    => $this->provider,
            'fee'         => round($this->fee, 2),
            'eta_minutes' => $this->etaMinutes,
            'quote_id'    => $this->quoteId,
            'feasible'    => $this->feasible,
            'currency'    => $this->currency,
        ];
    }
}
