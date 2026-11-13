<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Drops;

use Keepsuit\Liquid\Drop;

/**
 * Theme Studio B0.6: the `announcement` global (null when the owner has none
 * active). `key` is the text fingerprint the Blade banner uses for dismissal,
 * so a dismissed announcement stays dismissed across theme switches.
 */
class AnnouncementDrop extends Drop
{
    /** @param  array<string,mixed>  $data  built by App\Storefront\StorefrontExtras */
    public function __construct(protected array $data = []) {}

    /** Announcement text (escape it when printing). */
    public function text(): string
    {
        return (string) ($this->data['text'] ?? '');
    }

    /** Dismissal fingerprint (crc32b of the text, 8 chars). */
    public function key(): string
    {
        return (string) ($this->data['key'] ?? '');
    }
}
