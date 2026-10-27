<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Editions\Community;

use Illuminate\Http\Request;
use Juwri\Core\Contracts\Billing\RequestMeter;

/** P1-13 Community default: no per-tenant request metering. */
final class NoRequestMeter implements RequestMeter
{
    public function record(Request $request, mixed $response): void {}
}
