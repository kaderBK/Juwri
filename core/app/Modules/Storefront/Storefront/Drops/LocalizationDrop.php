<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Drops;

use Juwri\Core\Models\Language;
use Keepsuit\Liquid\Drop;

/**
 * P2-2: the `localization` global — available menu languages, the current one,
 * and a `direction` shortcut so themes lay out RTL correctly.
 */
class LocalizationDrop extends Drop
{
    /**
     * @param  iterable<Language>  $languages
     */
    /**
     * @param  iterable<Language>  $languages
     * @param  list<string>  $currencies  display currency codes, base first (empty = single currency)
     */
    public function __construct(
        protected iterable $languages = [],
        protected ?Language $current = null,
        protected array $currencies = [],
    ) {}

    /**
     * P8-8: available display currencies (base first). Empty/one when no manual
     * rates are configured, so themes hide the switcher. `code` only — the
     * conversion rates live in the commerce runtime register, never here.
     *
     * @return list<array{code:string}>
     */
    public function currencies(): array
    {
        return array_map(fn ($c) => ['code' => $c], array_values($this->currencies));
    }

    public function languages(): array
    {
        $out = [];
        foreach ($this->languages as $language) {
            $out[] = new LanguageDrop($language);
        }

        return $out;
    }

    public function current(): ?LanguageDrop
    {
        return $this->current ? new LanguageDrop($this->current) : null;
    }

    /** 'rtl' | 'ltr' — the current language's direction (defaults ltr). */
    public function direction(): string
    {
        return $this->current && $this->current->direction === 'rtl' ? 'rtl' : 'ltr';
    }
}
