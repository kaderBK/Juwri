<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Auth;

use App\Models\StaffMember;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * P6-40 (PG-SQL-AUDIT O1): the `eloquent` provider, but `email` and `username` credentials match
 * case-insensitively (`LOWER(col) = ?`) on every driver. On MySQL this is what the ci collation already did;
 * on PostgreSQL/sqlite `User@X.com` would otherwise fail to sign in or to get a reset link.
 * P6-98: if legacy rows differ only in case, an exact-case match wins; otherwise sign-in is refused with a support message.
 */
class CaseInsensitiveUserProvider extends EloquentUserProvider
{
    /** Credential keys compared case-insensitively. */
    public const COLUMNS = ['email', 'username'];

    /**
     * P6-220: a staff member switched off (by the owner or by a plan downgrade) loses an open session too, not only
     * the next sign-in — the session guard re-reads the member by id on every request.
     */
    public function retrieveById($identifier)
    {
        $user = parent::retrieveById($identifier);

        return $user instanceof StaffMember && ! $user->active ? null : $user;
    }

    /** P6-220: the same for a remember-me cookie. */
    public function retrieveByToken($identifier, #[\SensitiveParameter] $token)
    {
        $user = parent::retrieveByToken($identifier, $token);

        return $user instanceof StaffMember && ! $user->active ? null : $user;
    }

    /** @param  array<string, mixed>  $credentials */
    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials)
    {
        $credentials = array_filter($credentials, fn ($key) => ! str_contains((string) $key, 'password'), ARRAY_FILTER_USE_KEY);
        if ($credentials === []) {
            return null;
        }

        $query = $this->newModelQuery();
        foreach ($credentials as $key => $value) {
            if (in_array($key, self::COLUMNS, true) && is_string($value)) {
                $query->whereRaw('LOWER('.$query->getQuery()->getGrammar()->wrap($key).') = ?', [Str::lower(trim($value))]);
            } elseif (is_array($value) || $value instanceof Arrayable) {
                $query->whereIn($key, $value);
            } elseif ($value instanceof \Closure) {
                $value($query);
            } else {
                $query->where($key, $value);
            }
        }

        $matches = $query->limit(2)->get();
        if ($matches->count() > 1) {
            $user = $this->exactCaseMatch($matches->all(), $credentials);
            if ($user === null) {
                // P6-98: legacy rows differing only in case (before the unique migration) — never pick one arbitrarily.
                throw ValidationException::withMessages([array_key_first($credentials) => __('auth.case_duplicate')]);
            }
        } else {
            $user = $matches->first();
        }

        return $user instanceof Authenticatable && $user instanceof Model ? $user : null;
    }

    /**
     * The one candidate whose email/username equal the typed value exactly (case included), else null.
     *
     * @param  array<int, mixed>  $candidates
     * @param  array<string, mixed>  $credentials
     */
    private function exactCaseMatch(array $candidates, array $credentials): ?Authenticatable
    {
        $exact = array_values(array_filter($candidates, function ($user) use ($credentials) {
            foreach ($credentials as $key => $value) {
                if (in_array($key, self::COLUMNS, true) && is_string($value) && $user->getAttribute($key) !== trim($value)) {
                    return false;
                }
            }

            return true;
        }));

        return count($exact) === 1 && $exact[0] instanceof Authenticatable ? $exact[0] : null;
    }
}
