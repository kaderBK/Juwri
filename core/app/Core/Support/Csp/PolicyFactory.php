<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support\Csp;

use InvalidArgumentException;

/** Resolves a {@see Policy} subclass and adds the configured report-uri. */
final class PolicyFactory
{
    public static function create(string $className): Policy
    {
        $policy = app($className);

        if (! $policy instanceof Policy) {
            throw new InvalidArgumentException("[{$className}] is not a CSP policy.");
        }

        if (! empty(config('csp.report_uri'))) {
            $policy->reportTo((string) config('csp.report_uri'));
        }

        return $policy;
    }
}
