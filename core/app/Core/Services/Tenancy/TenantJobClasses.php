<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\Tenancy;

/**
 * P1-08b: the queued classes modules classified for {@see TenantJobs} from their service providers (Core may not
 * import module classes). A boot-time registry of class names, not database rows: it is not app-scoped on purpose —
 * a provider registers once per application and the names never change.
 */
final class TenantJobClasses
{
    /** @var list<class-string> */
    private static array $platform = [];

    /** @var list<class-string> */
    private static array $tenant = [];

    /**
     * @param  list<class-string>  $platform
     * @param  list<class-string>  $tenant
     */
    public static function add(array $platform, array $tenant): void
    {
        self::$platform = array_values(array_unique([...self::$platform, ...$platform]));
        self::$tenant = array_values(array_unique([...self::$tenant, ...$tenant]));
    }

    /** @return list<class-string> */
    public static function platform(): array
    {
        return self::$platform;
    }

    /** @return list<class-string> */
    public static function tenant(): array
    {
        return self::$tenant;
    }
}
