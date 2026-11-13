<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Drops;

use App\Models\MenuBanner;
use Keepsuit\Liquid\Drop;

/** P2-2: a promo/hero banner (active + position-ordered by ThemeContext). */
class BannerDrop extends Drop
{
    public function __construct(protected MenuBanner $banner) {}

    public function id(): int
    {
        return (int) $this->banner->id;
    }

    public function title(): ?string
    {
        return $this->banner->title;
    }

    public function subtitle(): ?string
    {
        return $this->banner->subtitle;
    }

    /**
     * Localized at the DROP, not left to the theme.
     *
     * Banner links are stored as the owner typed them, which is nearly always
     * unprefixed (`/{slug}?item=12`) — and in URL-prefix mode an unprefixed URL
     * IS the default-locale page, so an English diner who tapped a banner was
     * thrown into the store's Arabic default. The Blade themes had the same bug.
     *
     * A partner theme author cannot be expected to remember a `| localized_url`
     * filter, and the linter cannot see a runtime value, so the only guard that
     * actually holds is doing it here: every Liquid theme reads the link through
     * this method. External links and non-http schemes are returned untouched.
     */
    public function link_url(): ?string
    {
        return storefront_localized_link($this->banner->link_url);
    }

    /** Raw stored filename. */
    public function image(): ?string
    {
        return $this->banner->image;
    }

    /** Fully-resolved URL (MenuBanner::getImageUrlAttribute). */
    public function image_url(): ?string
    {
        return $this->banner->image_url;
    }
}
