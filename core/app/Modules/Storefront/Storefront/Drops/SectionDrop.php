<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Drops;

use Keepsuit\Liquid\Drop;

/**
 * P2-3: the `section` global inside a section render (OS 2.0). Settings are
 * the merged scalar map (schema defaults ← template values ← merchant values);
 * blocks come pre-ordered by block_order.
 */
class SectionDrop extends Drop
{
    /**
     * @param  array<string,mixed>  $settings
     * @param  array<int,BlockDrop>  $blocks
     */
    public function __construct(
        protected string $id,
        protected string $type,
        protected array $settings = [],
        protected array $blocks = [],
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function settings(): SettingsDrop
    {
        return new SettingsDrop($this->settings);
    }

    public function blocks(): array
    {
        return $this->blocks;
    }
}
