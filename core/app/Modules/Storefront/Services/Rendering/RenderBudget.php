<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Rendering;

/**
 * P1-3: resource ceiling for a single untrusted render. Output size and
 * wall-clock are enforced by RenderClient; memory + render/assign work are
 * enforced by Liquid's ResourceLimits at the engine layer (Phase 2).
 */
class RenderBudget
{
    public function __construct(
        public int $maxOutputBytes = 1_000_000,
        public int $maxWallSeconds = 5,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            (int) config('platform.render_budget.max_output_bytes', 1_000_000),
            (int) config('platform.render_budget.max_wall_seconds', 5),
        );
    }
}
