<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Services\Sso;

use Juwri\Modules\Auth\Contracts\SsoProvider;

/** P1-13 13h Community default of {@see SsoProvider}: no SSO, no SCIM. */
final class NoSsoProvider implements SsoProvider
{
    public function ssoEnabled(int $accountId): bool
    {
        return false;
    }

    public function connections(int $accountId): array
    {
        return [];
    }

    public function scimEnabled(int $accountId): bool
    {
        return false;
    }
}
