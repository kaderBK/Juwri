<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Drops;

use Juwri\Core\Models\Store;
use Keepsuit\Liquid\Drop;

/**
 * P2-2: the `restaurant` global. Presentation fields of a Post only.
 *
 * The Post model is held `protected` so it is never exposed to Liquid (only
 * public members reach a template — see keepsuit DropMetadata). Relations like
 * `user` (→ credentials) or `options` are unreachable: a theme referencing
 * `restaurant.user` gets null (live) or errors (upload), never the model graph.
 */
class StoreDrop extends Drop
{
    public function __construct(protected Store $store) {}

    public function id(): int
    {
        return (int) $this->store->id;
    }

    public function title(): ?string
    {
        return $this->store->title;
    }

    public function slug(): ?string
    {
        return $this->store->slug;
    }

    /**
     * The vertical discriminator (restaurant|retail|cosmetic|clothing|carparts|
     * laundry|pharmacy|hotel). Themes branch storefront layout on this or, for
     * copy, on {{ vocab.group }} / {{ vocab.* }}. Legacy stores read 'restaurant'.
     */
    public function store_type(): string
    {
        return (string) ($this->store->store_type ?? 'restaurant');
    }

    public function sub_title(): ?string
    {
        return $this->store->sub_title;
    }

    public function description(): ?string
    {
        return $this->store->description;
    }

    public function address(): ?string
    {
        return $this->store->address;
    }

    public function phone(): ?string
    {
        return $this->store->phone;
    }

    /** Brand colour (hex) chosen by the owner. */
    public function color(): ?string
    {
        return $this->store->color;
    }

    /** Raw stored filenames — themes build URLs via the `media_url` filter (P2-2b). */
    public function logo(): ?string
    {
        return $this->store->main_image;
    }

    public function main_image(): ?string
    {
        return $this->store->main_image;
    }

    public function cover(): ?string
    {
        // The store's real cover image. Historically this returned the legacy
        // welcome-page logo (`bio_main_image`, a storage/store/logo/ file) —
        // wrong on two counts (a logo, not a cover) and a legacy field slated for
        // removal. `cover_image` is the actual store cover.
        return $this->store->cover_image;
    }
}
