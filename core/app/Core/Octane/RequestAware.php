<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Octane;

/**
 * Phase 2 / item 16: a service provider whose boot() reads the request or settings an admin can change implements this,
 * so a long-lived Octane worker (one boot, many requests) can run that part again for every request.
 * {@see BeginRequest} calls {@see self::forRequest()} first thing in each request, task and tick.
 */
interface RequestAware
{
    public function forRequest(): void;
}
