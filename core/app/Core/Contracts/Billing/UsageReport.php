<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Juwri\Core\Editions\Community\NoUsageReport;

/**
 * P1-13 extension point: metered platform usage of one account (requests, orders, AI credits, storage) over the
 * last N days, keyed by metric. Billing (ee) binds its usage counters; Community meters nothing
 * ({@see NoUsageReport}).
 */
interface UsageReport
{
    /**
     * @param  list<string>  $metrics
     * @return array<string, mixed>
     */
    public function forTenant(int $userId, array $metrics, int $days = 30): array;

    /**
     * P1-13 13g: the accounts with the highest total of one metric over the last $days days (rows with `user_id` and
     * `total`), highest first.
     *
     * @return Collection<int, covariant Model>
     */
    public function topByMetric(string $metric, int $limit = 5, int $days = 1): Collection;
}
