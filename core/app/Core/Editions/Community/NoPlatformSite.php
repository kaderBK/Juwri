<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Editions\Community;

use Juwri\Core\Contracts\Content\PlatformSite;

/** Community default: no platform marketing site. Guests go to login. */
final class NoPlatformSite implements PlatformSite
{
    public function available(): bool
    {
        return false;
    }

    public function enabled(): bool
    {
        return false;
    }

    public function home(): mixed
    {
        return null;
    }
}
