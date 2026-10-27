<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support\Csp;

use Illuminate\Support\Str;

/** Per-request CSP nonce: 32 random alphanumerics. */
final class RandomNonce
{
    public function generate(): string
    {
        return Str::random(32);
    }
}
