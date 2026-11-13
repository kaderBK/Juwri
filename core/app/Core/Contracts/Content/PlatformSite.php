<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Content;

use Juwri\Core\Editions\Community\NoPlatformSite;

/**
 * The public platform marketing site. Community has none ({@see NoPlatformSite}: the site is not
 * available, and home falls through to login). An edition package may bind a real site. That site
 * stays off unless the install turns it on.
 */
interface PlatformSite
{
    /** True when an edition implementation is bound, not the Community null. */
    public function available(): bool;

    /** True when this install should show the platform marketing site. */
    public function enabled(): bool;

    /** Marketing home response, or null to fall through to login. */
    public function home(): mixed;
}
