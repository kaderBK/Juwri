<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ReservedSlug implements ValidationRule
{
    public static function isReserved(?string $slug): bool
    {
        if ($slug === null || $slug === '') {
            return false;
        }

        return in_array(mb_strtolower($slug), config('seo.reserved_slugs', []), true);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::isReserved(is_string($value) ? $value : null)) {
            $fail(___('This link name is reserved by the platform, please choose another one.'));
        }
    }
}
