<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * Vertical / store-type global helpers.
 *
 * WI-M15-style split (the H11/M15 precedent, exactly like SeoHelper.php /
 * WhatsAppHelper.php / NavHelper.php): extracted verbatim from Helper.php to keep
 * that god-file under its shrinking line ceiling. Global helpers, autoloaded via
 * composer "files" — every call site is unchanged.
 */

use App\Services\Verticals\VerticalRegistry;
use Juwri\Core\Models\Store;

/**
 * The store-ENTITY noun ("Restaurant"/"Store"/"Business"/"Hotel") for the store
 * in context, in the requested grammatical slot and the current UI locale.
 *
 * Powers store-type-aware dashboard chrome (Vertical-Neutral Copy Audit): a
 * food owner reads "Restaurant settings", a pharmacy owner "Store settings",
 * from ONE ":noun" placeholder in the lang string. English varies by case /
 * number; Arabic by grammatical role. Forms live in config('verticals.noun').
 *
 * $slot:
 *   'title'  — leading noun: en Title-case ("Restaurant"), ar definite ("المطعم")
 *   'lower'  — mid-sentence definite: en lower ("restaurant"), ar definite
 *   'poss'   — for "your :noun": en lower, ar possessive ("مطعمك")
 *   'indef'  — for "a :noun": en lower, ar indefinite ("مطعم")
 *   'plural' — en plural ("restaurants"), ar definite plural ("المطاعم")
 *   'gen'    — "of/for this :noun" where the language has a genitive (ru); else = 'lower'
 *   'poss_gen' — "of your :noun" (de/ru/tr genitive, pt "do seu"); else = 'poss'
 *
 * Other locales (de es fr pt ru tr hi ur zh): config/store_nouns.php, with the
 * demonstrative/possessive/article inside the form so the sentence never has to
 * agree in gender or case. A locale with no entry falls back to English.
 *
 * A null store (nothing in context) or an unknown store_type resolves to the
 * neutral "Business" / متجر set, so the chrome is never wrong, only generic.
 */
function store_noun(?Store $store = null, string $slot = 'title'): string
{
    $store = $store ?? dashboard_current_store();

    // Only trust the vertical group for a REGISTERED store_type — VerticalRegistry
    // defaults any unknown/corrupt type to food, which would wrongly say
    // "Restaurant" instead of the neutral fallback.
    $group = null;
    $type = $store?->store_type;
    if ($type && array_key_exists($type, (array) config('verticals.types', []))) {
        $group = app(VerticalRegistry::class)
            ->capability($type, 'group', null);
    }

    $sets = (array) config('verticals.noun', []);
    $key = ($group !== null && array_key_exists($group, $sets)) ? $group : '_generic';
    $set = $sets[$key] ?? [];

    $locale = get_lang();

    if ($locale === 'ar') {
        return match ($slot) {
            'plural' => $set['ar_plural'] ?? '',
            'poss', 'poss_gen' => $set['ar_poss'] ?? '',
            'indef' => $set['ar_indef'] ?? '',
            'lower', 'gen' => $set['ar_def'] ?? '',
            default => $set['ar_def'] ?? '',
        };
    }

    // Every other non-English locale: forms in config/store_nouns.php. A locale
    // without an entry (e.g. an admin-added language) falls through to English.
    $base = strtolower((string) preg_split('/[-_]/', $locale)[0]);
    $forms = $base === 'en' ? [] : (array) config("store_nouns.{$base}.{$key}", []);
    if ($forms !== []) {
        $fallback = ['gen' => 'lower', 'poss_gen' => 'poss'];
        $slot = array_key_exists($slot, ['title' => 1, 'lower' => 1, 'poss' => 1, 'indef' => 1, 'plural' => 1] + $fallback) ? $slot : 'title';

        return (string) ($forms[$slot] ?? $forms[$fallback[$slot] ?? 'title'] ?? $forms['title'] ?? '');
    }

    return match ($slot) {
        'plural' => $set['plural'] ?? '',
        'poss', 'poss_gen', 'indef', 'lower', 'gen' => $set['lower'] ?? '',
        default => $set['title'] ?? '',
    };
}
