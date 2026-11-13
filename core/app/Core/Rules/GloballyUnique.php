<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Juwri\Core\Services\Tenancy\TenantContext;

/**
 * BL-043: `unique:` for a value that must be unique across EVERY account (store slugs, staff sign-in e-mails).
 * Laravel's `unique:` rule runs inside the request's tenant context, where PostgreSQL RLS hides other accounts'
 * rows: a duplicate would pass (or hit the unique index as a 500, which also tells the caller the value exists in
 * another account). This rule checks under TenantContext::bypass() and answers with the standard "already taken"
 * message only — never which account holds the value.
 */
final class GloballyUnique implements ValidationRule
{
    public function __construct(
        private readonly string $table,
        private readonly string $column,
        private readonly int|string|null $ignoreId = null,
        private readonly string $idColumn = 'id',
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }
        // The app's presence verifier, as `unique:` uses it: e-mail / username / coupon-code columns compare
        // case-insensitively (LOWER, trimmed) like their lowercased unique indexes, every other column as stored.
        $taken = TenantContext::bypass(fn (): bool => app('validation.presence')
            ->getCount($this->table, $this->column, $value, $this->ignoreId, $this->idColumn) > 0);
        if ($taken) {
            $fail('validation.unique')->translate();
        }
    }
}
