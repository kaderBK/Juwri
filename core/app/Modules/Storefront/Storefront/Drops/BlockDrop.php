<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Drops;

use Keepsuit\Liquid\Drop;

/**
 * P2-3: one block inside a section (OS 2.0). Exposes only id/type/settings —
 * settings values are scalars validated upstream against the section schema.
 */
class BlockDrop extends Drop
{
    /** @param  array<string,mixed>  $settings */
    public function __construct(
        protected string $id,
        protected string $type,
        protected array $settings = [],
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
}
