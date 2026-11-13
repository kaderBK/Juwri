<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models\Concerns;

/**
 * Storefront-language values for models with a `translations` column
 * (cast to object: `{ "<lang>": { "<field>": value } }`). The storefront
 * language switch stores the visitor's language in the
 * `Quick_user_lang_code` cookie; the dashboard never sets it, so owners
 * always see the base values.
 */
trait TranslatesForStorefront
{
    /** The visitor's storefront language code, or null outside the storefront. */
    protected function storefrontLanguage(): ?string
    {
        $language = $_COOKIE['Quick_user_lang_code'] ?? null;

        return empty($language) ? null : (string) $language;
    }

    /** `$field` in the visitor's language, or null when there is no non-empty translation. */
    protected function storefrontTranslation(string $field): mixed
    {
        $language = $this->storefrontLanguage();
        if ($language === null) {
            return null;
        }

        $value = $this->translations->{$language}->{$field} ?? null;

        return empty($value) ? null : $value;
    }
}
