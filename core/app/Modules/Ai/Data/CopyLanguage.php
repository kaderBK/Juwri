<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Data;

/**
 * P1-17 (J27-B): the language an AI marketing draft is written in (menu description, social post, review reply).
 * Arabic or English only; the owner picks one, else the store's default language decides (Arabic when it is an
 * Arabic locale, English otherwise). Public Data part of the Ai module so Menu, Marketing and Integrations share it.
 */
final class CopyLanguage
{
    /** @var list<string> */
    public const CODES = ['ar', 'en'];

    private function __construct(public readonly string $code) {}

    /** The owner's pick when it is a supported code, else the store default. */
    public static function pick(?string $code, int $storeId): self
    {
        $code = strtolower(trim((string) $code));

        return in_array($code, self::CODES, true) ? new self($code) : self::storeDefault($storeId);
    }

    /** The store's default language mapped onto AR / EN (no store setting -> the app locale). */
    public static function storeDefault(int $storeId): self
    {
        $locale = function_exists('store_options') ? (string) (store_options($storeId, 'default_language') ?: '') : '';
        if ($locale === '') {
            $locale = (string) app()->getLocale();
        }

        return new self(str_starts_with(strtolower($locale), 'ar') ? 'ar' : 'en');
    }

    public function isArabic(): bool
    {
        return $this->code === 'ar';
    }

    /** Text direction for the UI field the draft lands in. */
    public function dir(): string
    {
        return $this->isArabic() ? 'rtl' : 'ltr';
    }

    /** The instruction line every marketing prompt ends its system message with. */
    public function instruction(): string
    {
        return $this->isArabic()
            ? 'Write the text in Arabic (clear Modern Standard Arabic that customers in the Gulf and the Levant read naturally). Do not mix in English words unless they are part of a name.'
            : 'Write the text in English.';
    }
}
