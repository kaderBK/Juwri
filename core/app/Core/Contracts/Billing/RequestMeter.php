<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Billing;

use Illuminate\Http\Request;
use Juwri\Core\Editions\Community\NoRequestMeter;
use Juwri\Core\Http\Middleware\MeterRequests;

/**
 * P1-13 extension point: per-tenant request metering (Abuse Guard P7), called at terminate time by Core's
 * {@see MeterRequests} in the web and api groups. Billing (ee) binds its usage meter;
 * Community meters nothing ({@see NoRequestMeter}).
 */
interface RequestMeter
{
    public function record(Request $request, mixed $response): void;
}
