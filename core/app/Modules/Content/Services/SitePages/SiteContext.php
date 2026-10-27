<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\SitePages;

use App\Models\Site;
use Illuminate\Support\Facades\Cache;

/**
 * HOME-PAGES HP-11: resolves the active Site for the current request.
 *
 * Resolution order (mirrors Reseller::resolveForRequest mechanics):
 *   1. exact host match against sites.domains (active rows) — dormant in the
 *      4-separate-installs topology, live after host consolidation (HP-63);
 *   2. the single active Site row (the install's default site);
 *   3. null — no Site seeded yet: callers fall through to the legacy Blade home.
 *
 * Cache: site:host:{host} / site:default, 300s, bust in Site::booted().
 * Per-request memo rebinds on \Juwri\Core\Support\RequestEpoch::id() (static-memo cross-test rule).
 */
class SiteContext
{
    private const TTL = 300;

    /** Sentinel distinguishing "not memoised yet" from "memoised null". */
    private const UNSET = 'unset';

    /** @var Site|null|self::UNSET */
    private static mixed $memo = self::UNSET;

    private static ?int $memoApp = null;

    public static function current(): ?Site
    {
        self::rebindMemoOnNewApp();

        if (self::$memo !== self::UNSET) {
            return self::$memo;
        }

        return self::$memo = self::resolve();
    }

    /** Test/edge hook: drop the per-request memo (cache entries stay). */
    public static function flushMemo(): void
    {
        self::$memo = self::UNSET;
    }

    private static function resolve(): ?Site
    {
        $host = strtolower(request()->getHost());

        try {
            // 1) exact host match (consolidated-deploy path; dormant on 4-install topology)
            $byHost = Cache::flexible('site:host:'.$host, [self::TTL, self::TTL * 2], function () use ($host) {
                return Site::active()
                    ->get()
                    ->first(function (Site $s) use ($host) {
                        $domains = array_map('strtolower', array_filter((array) ($s->domains ?? []), 'is_string'));

                        return in_array($host, $domains, true);
                    });
            });
            if ($byHost) {
                return $byHost;
            }

            // 2) the install's single active default site
            return Cache::flexible('site:default', [self::TTL, self::TTL * 2], function () {
                return Site::active()->orderBy('id')->first();
            });
        } catch (\Throwable $e) {
            // sites table may not exist yet (fresh install / pre-migration) —
            // degrade to the legacy Blade home rather than 500 the whole app.
            return null;
        }
    }

    /** Clears ONLY this class's memo — never another class's. */
    private static function rebindMemoOnNewApp(): void
    {
        $appId = \Juwri\Core\Support\RequestEpoch::id();
        if (self::$memoApp !== $appId) {
            self::$memoApp = $appId;
            self::$memo = self::UNSET;
        }
    }
}
