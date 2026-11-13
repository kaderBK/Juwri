<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Orders;


/**
 * ZERO-TOUCH Track C — customer-facing prep-time estimate ("20–30 min").
 *
 * Value object returned by {@see EtaEstimator}. All values are MINUTES.
 * `p25`/`p75` are the friendly (5-min rounded) display bounds, `p50` is the
 * un-rounded midpoint kept for informational surfaces (KDS target hints).
 *
 * NOTE ON NAMING: "Eta" here is strictly the order prep-time estimate. It is
 * unrelated to App\Services\Fiscal\Eta\* (Egyptian Tax Authority) — never mix
 * the two; public-facing keys all use the `prep_eta` prefix for that reason.
 */
class EtaRange
{
    public function __construct(
        public readonly int $p25,
        public readonly int $p50,
        public readonly int $p75,
        public readonly int $samples,
        public readonly string $source = 'heuristic', // 'heuristic' | 'static'
    ) {
    }

    /** Display label without unit, e.g. "20–30" (en dash). */
    public function label(): string
    {
        return $this->p25 . '–' . $this->p75;
    }

    /** Machine-friendly range for APIs, e.g. "20-30" (ASCII hyphen). */
    public function toApiString(): string
    {
        return $this->p25 . '-' . $this->p75;
    }

    /** JSON-safe payload for customer surfaces (tracking page poll + checkout). */
    public function toArray(): array
    {
        return [
            'p25' => $this->p25,
            'p75' => $this->p75,
            'text' => $this->label(),
            'line' => ___('Estimated ready in about :range min', ['range' => $this->label()]),
            'source' => $this->source,
        ];
    }
}
