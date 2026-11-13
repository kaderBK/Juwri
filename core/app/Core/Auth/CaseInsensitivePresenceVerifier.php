<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Auth;

use Illuminate\Validation\DatabasePresenceVerifier;

/**
 * P6-40 (PG-SQL-AUDIT O1): `unique:` / `exists:` rules on an `email` or `username` column compare
 * case-insensitively on every driver, so PostgreSQL cannot register `User@X.com` beside `user@x.com`.
 * Coupon codes likewise. Every other column (tokens, signatures, slugs) keeps the driver's own comparison.
 */
class CaseInsensitivePresenceVerifier extends DatabasePresenceVerifier
{
    /** @param  array<string, mixed>  $extra */
    public function getCount($collection, $column, $value, $excludeId = null, $idColumn = null, array $extra = [])
    {
        if (! $this->isCaseInsensitive($collection, $column)) {
            return parent::getCount($collection, $column, $value, $excludeId, $idColumn, $extra);
        }

        $query = $this->table($collection);
        $query->whereRaw('LOWER('.$query->getGrammar()->wrap($column).') = ?', [mb_strtolower(trim((string) $value))]);
        if (! in_array((string) $excludeId, ['', 'NULL'], true)) { // the parent skips null and the literal 'NULL' alike
            $query->where($idColumn ?: 'id', '<>', $excludeId);
        }

        return $this->addConditions($query, $extra)->count();
    }

    /**
     * @param  array<int, mixed>  $values
     * @param  array<string, mixed>  $extra
     */
    public function getMultiCount($collection, $column, array $values, array $extra = [])
    {
        if (! $this->isCaseInsensitive($collection, $column)) {
            return parent::getMultiCount($collection, $column, $values, $extra);
        }

        $query = $this->table($collection);
        $lower = array_map(fn ($v) => mb_strtolower(trim((string) $v)), $values);
        $query->whereIn($query->raw('LOWER('.$query->getGrammar()->wrap($column).')'), $lower);

        return $this->addConditions($query, $extra)->distinct()->count($query->raw('LOWER('.$query->getGrammar()->wrap($column).')'));
    }

    /** Coupon codes are typed by customers in any case; every other `code` column keeps the driver's comparison. */
    private const TABLE_COLUMNS = ['coupons.code'];

    private function isCaseInsensitive(string $collection, string $column): bool
    {
        return in_array($column, CaseInsensitiveUserProvider::COLUMNS, true)
            || in_array($collection.'.'.$column, self::TABLE_COLUMNS, true);
    }
}
