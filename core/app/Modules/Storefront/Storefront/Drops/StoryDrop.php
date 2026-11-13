<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Drops;

use Keepsuit\Liquid\Drop;
use Juwri\Core\Support\SafeUrl;

/**
 * Theme Studio B0.6: one entry of the `stories` global — the store's visible
 * stories (active, inside their schedule window, owner-sorted), images only.
 */
class StoryDrop extends Drop
{
    /** @param  array<string,mixed>  $data  built by App\Storefront\StorefrontExtras */
    public function __construct(protected array $data = []) {}

    public function id(): int
    {
        return (int) ($this->data['id'] ?? 0);
    }

    /** Full image URL. */
    public function image_url(): ?string
    {
        return isset($this->data['image_url']) ? (string) $this->data['image_url'] : null;
    }

    public function caption(): ?string
    {
        return isset($this->data['caption']) ? (string) $this->data['caption'] : null;
    }

    /** Button label ("Order now" when a link is set without a label). */
    public function cta_label(): ?string
    {
        return isset($this->data['cta_label']) ? (string) $this->data['cta_label'] : null;
    }

    /** Locale-aware link, or null. */
    public function cta_url(): ?string
    {
        // Owner-typed; stories saved before the write-side check may hold a javascript:/data: link.
        return isset($this->data['cta_url']) ? SafeUrl::link((string) $this->data['cta_url']) : null;
    }
}
