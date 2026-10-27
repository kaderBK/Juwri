<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support\Csp;

/** CSP source keywords; rendered single-quoted. */
final class Keyword
{
    public const NONE = 'none';

    public const REPORT_SAMPLE = 'report-sample';

    public const SELF = 'self';

    public const STRICT_DYNAMIC = 'strict-dynamic';

    public const UNSAFE_EVAL = 'unsafe-eval';

    public const UNSAFE_HASHES = 'unsafe-hashes';

    public const UNSAFE_INLINE = 'unsafe-inline';

    public const WASM_UNSAFE_EVAL = 'wasm-unsafe-eval';

    public static function isKeyword(string $value): bool
    {
        return in_array($value, new \ReflectionClass(self::class)->getConstants(), true);
    }
}
