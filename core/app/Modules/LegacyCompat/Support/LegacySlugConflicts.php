<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Http\Hosts\HostMap;
use Juwri\Core\Rules\ReservedSlug;
use Juwri\Modules\LegacyCompat\Import\RowLoader;

/**
 * J52b plan §1.1 rule 4, §5.4 (R4, R9): imported slugs are kept byte-for-byte, even when this install would not serve
 * `/{slug}` as the store page — a reserved word (locale code, platform path) or a newer route that matches the path
 * first. Such slugs are listed in `legacy_slug_conflicts` ({@see scan()} / {@see persist()}, run by `legacy:import` and
 * `legacy:slug-conflicts`) and get explicit store-page routes registered BEFORE every other route
 * ({@see registerRoutes()}), bound to the old platform's hosts only — so the old printed URL wins for that one slug
 * there, while this install's own hosts keep their own paths. The list is read once per cache period (R9), never per request.
 *
 * Not covered (reported): a conflicting slug's sub-paths (`/{slug}/…`), and its store's custom domain root.
 */
final class LegacySlugConflicts
{
    public const TABLE = 'legacy_slug_conflicts';

    /** Name prefix of the generated routes; ends in `.publicView` so the page middleware treats them as the store page. */
    public const ROUTE_PREFIX = 'legacy_compat.slug_conflict.';

    public const CACHE_KEY = 'legacy_compat:slug_conflicts';

    public const CACHE_SECONDS = 600;

    /** Slugs per generated route pattern (keeps each route regex small). */
    private const PER_ROUTE = 100;

    /**
     * Every imported store whose slug this install would not serve at `/{slug}`.
     *
     * @return list<array{store_id: int, slug: string, reason: string}>
     */
    public static function scan(?Connection $db = null): array
    {
        $db ??= DB::connection();
        if (! $db->getSchemaBuilder()->hasTable(RowLoader::SIDE_TABLE)) {
            return [];
        }
        $router = app(Router::class);
        $out = [];
        $db->table(RowLoader::SIDE_TABLE)->where('target_table', 'stores')->select(['id', 'target_id'])
            ->chunkById(500, function ($records) use ($db, $router, &$out): void {
                $ids = array_map(static fn ($r): int => (int) $r->target_id, $records->all());
                foreach ($db->table('stores')->whereIn('id', $ids)->orderBy('id')->get(['id', 'slug']) as $store) {
                    $slug = (string) $store->slug;
                    $reason = self::reasonFor($slug, $router);
                    if ($reason !== null) {
                        $out[] = ['store_id' => (int) $store->id, 'slug' => $slug, 'reason' => $reason];
                    }
                }
            });

        return $out;
    }

    /** Why `/{slug}` would not open the store on this install, or null when it does. */
    public static function reasonFor(string $slug, ?Router $router = null): ?string
    {
        if (! self::routable($slug)) {
            return 'not routable as one path segment';
        }
        if (ReservedSlug::isReserved($slug)) {
            return 'reserved word';
        }
        $route = self::firstMatch('/'.rawurlencode($slug), $router ?? app(Router::class));
        if ($route === null) {
            return 'no route matches';
        }

        return self::isStorePage($route) ? null : 'shadowed by route '.$route->uri();
    }

    /**
     * Replace the stored list with $conflicts and drop the cached copy.
     *
     * @param  list<array{store_id: int, slug: string, reason: string}>  $conflicts
     */
    public static function persist(array $conflicts, ?Connection $db = null): void
    {
        $db ??= DB::connection();
        $now = now()->utc()->format('Y-m-d H:i:s');
        $rows = [];
        foreach ($conflicts as $c) {
            $rows[$c['slug']] = ['slug' => $c['slug'], 'reason' => mb_substr($c['reason'], 0, 255), 'created_at' => $now, 'updated_at' => $now];
        }
        $db->transaction(function () use ($db, $rows): void {
            $db->table(self::TABLE)->delete();
            foreach (array_chunk(array_values($rows), 200) as $batch) {
                $db->table(self::TABLE)->insert($batch);
            }
        });
        self::flush();
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The stored conflicting slugs (cached). Empty while the module is off or before the table exists.
     *
     * @phpstan-impure
     *
     * @return list<string>
     */
    public static function slugs(): array
    {
        if (! LegacyCompatRoutes::enabled()) {
            return [];
        }
        try {
            /** @var list<string> $slugs */
            $slugs = Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, static function (): array {
                if (! Schema::hasTable(self::TABLE)) {
                    return [];
                }

                return array_values(array_unique(array_map('strval', DB::table(self::TABLE)->orderBy('id')->pluck('slug')->all())));
            });

            return $slugs;
        } catch (\Throwable) {
            return []; // no database yet (install, migrate): no conflict routes
        }
    }

    /**
     * Hosts the conflict routes are bound to: the old platform's hosts and their `www.` twins ONLY (plan §3, §5.4) —
     * printed old URLs point there. Never this install's own hosts: on the storefront host an imported slug such as
     * `pricing` would otherwise take that page over for every visitor.
     *
     * @return list<string>
     */
    public static function hosts(): array
    {
        $hosts = [];
        foreach (LegacyHosts::platformHosts() as $host) {
            $hosts[] = $host;
            $hosts[] = 'www.'.$host;
        }

        return array_values(array_unique($hosts));
    }

    /** Can $slug be one literal path segment of a route pattern (no `/`, no surrounding space, not empty)? */
    public static function routable(string $slug): bool
    {
        return $slug !== '' && ! str_contains($slug, '/') && $slug === trim($slug);
    }

    /**
     * Register the explicit store-page routes for the conflicting slugs. $define registers one route
     * (host, slug pattern, route name) with the store page's action and middleware (routes/web.php owns those).
     *
     * @param  Closure(string, string, string): mixed  $define
     * @return list<string> names of the routes added
     */
    public static function registerRoutes(Closure $define): array
    {
        // Unroutable slugs stay in the report/table but never in a route pattern.
        $slugs = array_values(array_filter(self::slugs(), self::routable(...)));
        $hosts = $slugs === [] ? [] : self::hosts();
        $names = [];
        foreach ($hosts as $h => $host) {
            foreach (array_chunk($slugs, self::PER_ROUTE) as $c => $chunk) {
                $pattern = '(?:'.implode('|', array_map(static fn (string $s): string => preg_quote($s, '#'), $chunk)).')';
                $name = self::ROUTE_PREFIX.$h.'_'.$c.'.publicView';
                $define($host, $pattern, $name);
                $names[] = $name;
            }
        }

        return $names;
    }

    private static function isStorePage(Route $route): bool
    {
        $name = (string) $route->getName();

        return $name === 'publicView' || (str_ends_with($name, '.publicView') && ! str_starts_with($name, self::ROUTE_PREFIX));
    }

    /** The first GET route matching $path on the storefront host, in the router's own order, ignoring conflict routes. */
    private static function firstMatch(string $path, Router $router): ?Route
    {
        $host = HostMap::storefrontHost() ?: 'localhost';
        $request = Request::create('http://'.$host.$path, 'GET');
        $routes = $router->getRoutes()->get('GET');
        $ordered = [...array_filter($routes, static fn (Route $r): bool => ! $r->isFallback), ...array_filter($routes, static fn (Route $r): bool => $r->isFallback)];
        foreach ($ordered as $route) {
            if (str_starts_with((string) $route->getName(), self::ROUTE_PREFIX)) {
                continue;
            }
            if ($route->matches($request)) {
                return $route;
            }
        }

        return null;
    }
}
