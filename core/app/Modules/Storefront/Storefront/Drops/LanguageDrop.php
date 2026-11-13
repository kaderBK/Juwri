<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Drops;

use Juwri\Core\Models\Language;
use Keepsuit\Liquid\Drop;

/** P2-2: a menu language option. */
class LanguageDrop extends Drop
{
    public function __construct(protected Language $language) {}

    public function code(): ?string
    {
        return $this->language->code;
    }

    public function name(): ?string
    {
        return $this->language->name;
    }

    /** 'rtl' | 'ltr'. */
    public function direction(): string
    {
        return $this->language->direction === 'rtl' ? 'rtl' : 'ltr';
    }
}
