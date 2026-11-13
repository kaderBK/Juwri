<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

use Closure;

/**
 * Hosts the platform owns besides the app host (P1-08a): a module registers a source from its
 * provider (Storefront: verified store domains; Reseller: active reseller custom domains), so Core's
 * external-redirect loop guard never imports a higher layer. Keyed by name: a provider booting
 * again (every test application) replaces its own source.
 */
final class TrustedHostSources
{
    /** @var array<string, Closure(): iterable<string>> */
    private static array $sources = [];

    /** @param  Closure(): iterable<string>  $source */
    public static function add(string $name, Closure $source): void
    {
        self::$sources[$name] = $source;
    }

    /**
     * Every registered host, lower-cased. A failing source is skipped (partial list; the
     * app host is still enforced by the caller).
     *
     * @return list<string>
     */
    public static function hosts(): array
    {
        $hosts = [];
        foreach (self::$sources as $source) {
            try {
                foreach ($source() as $host) {
                    $hosts[] = strtolower(trim((string) $host));
                }
            } catch (\Throwable $e) {
                // a DB blip must not block a legitimate save
            }
        }

        return $hosts;
    }
}
