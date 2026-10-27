<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use Juwri\Core\Settings\PlatformSettings;

/**
 * F5-5 (feature-platform gap-fix): the single source of truth for "is this
 * store a developer sandbox?".
 *
 * Sandbox stores are created one-per-developer by the partner dashboard's "create sandbox" action (Enterprise
 * package, AppStore partner apps) so partner
 * apps have something real to install against. They are ordinary `posts` rows —
 * the sandbox marker lives in the `options` table under the name
 * `post_{id}_is_sandbox` (value '1'). Before this class the flag was
 * WRITE-ONLY: nothing ever read it, so sandbox stores were counted and exposed
 * exactly like paying customers' stores.
 *
 * Deliberately NOT a global scope. Sandbox stores must stay fully visible on
 * the surfaces that exist to serve them — the developer's own dashboard, the
 * partner test console, the license/install APIs. A global scope would hide
 * them there too and silently break the partner flow. Every exclusion is
 * therefore explicit, at the consumer, via `Post::excludingSandbox()` or
 * {@see self::exclude()}.
 *
 * No raw SQL and no hardcoded table names here (prefixed DBs would 500).
 */
class SandboxStores
{
    /** Option-name suffix that marks a post as a sandbox store. */
    public const OPTION_SUFFIX = '_is_sandbox';

    /** Container binding used to memoize the id list for one request/test. */
    private const MEMO = 'sandbox_stores.ids';

    /**
     * Ids of every store flagged as a sandbox.
     *
     * Memoized per request (container-bound, so it never leaks between tests
     * the way a PHP static would). Always mutate the flag through
     * {@see self::flag()} so the memo is invalidated.
     *
     * @return list<int>
     */
    public static function ids(): array
    {
        if (app()->bound(self::MEMO)) {
            return app(self::MEMO);
        }

        $ids = [];
        try {
            // 'post%_is_sandbox' keeps LIKE-wildcard escaping out of the picture;
            // the regex below is what actually decides membership.
            $names = PlatformSettings::namesWithPrefix('post_');

            foreach ($names as $name) {
                $value = PlatformSettings::legacy($name);
                if (preg_match('/^post_(\d+)'.preg_quote(self::OPTION_SUFFIX, '/').'$/', (string) $name, $m)
                    && filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                    $ids[] = (int) $m[1];
                }
            }
        } catch (\Throwable $e) {
            // A metrics/catalog query must never die because options are unreadable.
            report($e);

            return [];
        }

        $ids = array_values(array_unique($ids));
        app()->instance(self::MEMO, $ids);

        return $ids;
    }

    /** Is this store a developer sandbox? */
    public static function isSandbox(int|string|null $storeId): bool
    {
        return $storeId !== null && in_array((int) $storeId, self::ids(), true);
    }

    /** Flag (or unflag) a store as a sandbox and bust the memo. */
    public static function flag(int $storeId, bool $on = true): void
    {
        PlatformSettings::set('post_'.$storeId.self::OPTION_SUFFIX, $on ? '1' : '0');
        self::forget();
    }

    /** Drop the memoized id list (call after writing the option by hand). */
    public static function forget(): void
    {
        if (app()->bound(self::MEMO)) {
            app()->forgetInstance(self::MEMO);
        }
    }

    /**
     * Exclude sandbox stores from any query by the column that holds the store
     * id (`store_id`, …). Qualify the column with the model's
     * own table name — never a literal — so prefixed databases keep working.
     *
     * @template TQuery of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function exclude($query, string $column)
    {
        $ids = self::ids();
        if ($ids === []) {
            return $query;
        }

        if (method_exists($query, 'getModel') && ! str_contains($column, '.')) {
            $column = $query->getModel()->getTable().'.'.$column;
        }

        return $query->whereNotIn($column, $ids);
    }
}
