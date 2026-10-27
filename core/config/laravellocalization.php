<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

return [

    // The registry of supported locales for mcamara/laravel-localization.
    // If not set, it defaults to all languages. During testing, settings might not be loaded yet,
    // so we provide a safe fallback registry.
    'supportedLocales' => [
        'en' => ['name' => 'English',                'script' => 'Latn', 'native' => 'English', 'regional' => 'en_US'],
        'ar' => ['name' => 'Arabic',                 'script' => 'Arab', 'native' => 'العربية', 'regional' => 'ar_AE'],
    ],

    // rev/seo R2a: metadata merged into the runtime registry built from the
    // languages table (get_active_languages) — mcamara expects script/native/
    // regional per locale, and og:locale / hreflang mapping (R13/R4) reads
    // regional from here.
    'localesMetadata' => [
        'en' => ['script' => 'Latn', 'native' => 'English',   'regional' => 'en_US'],
        'ar' => ['script' => 'Arab', 'native' => 'العربية',   'regional' => 'ar_AE'],
        'de' => ['script' => 'Latn', 'native' => 'Deutsch',   'regional' => 'de_DE'],
        'es' => ['script' => 'Latn', 'native' => 'Español',   'regional' => 'es_ES'],
        'fr' => ['script' => 'Latn', 'native' => 'Français',  'regional' => 'fr_FR'],
        'hi' => ['script' => 'Deva', 'native' => 'हिन्दी',      'regional' => 'hi_IN'],
        'pt' => ['script' => 'Latn', 'native' => 'Português', 'regional' => 'pt_PT'],
        'ru' => ['script' => 'Cyrl', 'native' => 'Русский',   'regional' => 'ru_RU'],
        'tr' => ['script' => 'Latn', 'native' => 'Türkçe',    'regional' => 'tr_TR'],
        'ur' => ['script' => 'Arab', 'native' => 'اردو',      'regional' => 'ur_PK'],
        'zh' => ['script' => 'Hans', 'native' => '简体中文',   'regional' => 'zh_CN'],
    ],

    // rev/seo R2a: OFF — Googlebot crawls without Accept-Language; header-based
    // redirects cloak locale URLs from indexing. Language choice is explicit
    // (URL prefix in include_language_code mode, picker otherwise).
    /*
     * How locale-prefixed routes are registered (see Juwri\Core\Support\LocalizedRoutes): `auto` = per request under
     * php-fpm, for every active language inside an Octane worker; `all` forces the worker behaviour (route:cache for the
     * container); `off` forces php-fpm's; or a comma list of language codes (tests).
     */
    'localeRoutes' => env('JUWRI_LOCALE_ROUTES', 'auto'),

    'useAcceptLanguageHeader' => false,

    'hideDefaultLocaleInURL' => true,

    'localesOrder' => [],

    'localesMapping' => [],

    'utf8suffix' => '.UTF-8',

    'urlsIgnoredRegexp' => [],

];
