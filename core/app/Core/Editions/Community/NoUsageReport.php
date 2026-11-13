<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Editions\Community;

use Illuminate\Support\Collection;
use Juwri\Core\Contracts\Billing\UsageReport;

/** P1-13 Community default: no platform usage metering. */
final class NoUsageReport implements UsageReport
{
    public function forTenant(int $userId, array $metrics, int $days = 30): array
    {
        return [];
    }

    public function topByMetric(string $metric, int $limit = 5, int $days = 1): Collection
    {
        return collect();
    }
}
