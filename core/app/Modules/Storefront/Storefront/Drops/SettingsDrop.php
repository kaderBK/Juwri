<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Drops;

use Keepsuit\Liquid\Drop;
use Keepsuit\Liquid\Exceptions\UndefinedDropMethodException;

/**
 * P2-2: the `settings` global — the owner's saved theme-customizer values
 * (from the theme's settings_schema). Keys are dynamic per theme, so lookups
 * resolve against a flat scalar map. A key that isn't set behaves like any
 * other undefined variable (blank in live render, error at upload) — the map
 * never exposes anything but the scalar values it was built with.
 */
class SettingsDrop extends Drop
{
    /** @param  array<string,scalar|null>  $values */
    public function __construct(protected array $values = []) {}

    protected function liquidMethodMissing(string $name): mixed
    {
        if (array_key_exists($name, $this->values)) {
            return $this->values[$name];
        }

        throw new UndefinedDropMethodException($name);
    }
}
