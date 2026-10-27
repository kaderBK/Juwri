<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

return [

    // P6-248: force Disallow: / + X-Robots-Tag noindex on robots.txt even in production (SEO_NOINDEX=true).
    'noindex' => (bool) env('SEO_NOINDEX', false),

    // Page title overrides by key and language: ['contact' => ['en' => 'Title'], 'compare.<slug>' => [...]]. Titles only,
    // without the brand suffix. Empty = the shipped copy. The old-platform migration fills it (LegacyCompat).
    'title_overrides' => [],

    /*
     * Slugs that can never be claimed by a restaurant. Locale codes become URL
     * prefixes in include_language_code mode (rev/seo R1/R2), and the marketing
     * paths are registered above the {slug} catch-all — a post with one of these
     * slugs would either shadow or be shadowed by a platform URL.
     * Checked case-insensitively (DB collation matches slugs case-insensitively).
     */
    'reserved_slugs' => [
        // locale prefixes (all shipped languages, active or not)
        'en', 'ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh',
        // platform paths registered before the catch-all
        'lang', 'blog', 'studio', 'pricing', 'faq', 'faqs', 'contact',
        'testimonials', 'feedback', 'developers', 'reseller', 'marketplace',
        'page', 'restaurant', 'dashboard', 'admin', 'login', 'register',
        'board', // PARITY-RUN T2: /board/{slug} is registered before the catch-all
        'sitemap.xml', 'robots.txt', 'llms.txt',
        'up', // /up is the container health probe (Phase 2 item 16)
        'terms', 'privacy', 'security', 'legal', 'cookies', // P1-23: website legal pages (/legal/dpa, /legal/aup, …) + cookie list
    ],

    /*
     * rev/seo R20: IndexNow — instant URL submission to Bing + Yandex (and any
     * IndexNow-participating engine). The key is served as a text file at
     * https://<host>/<key>.txt to prove ownership. Override in prod via
     * INDEXNOW_KEY (no default: each deployment proves ownership of its own
     * host). Unset/empty disables the key file and pinging entirely.
     */
    'indexnow_key' => env('INDEXNOW_KEY'),

    'indexnow_endpoints' => [
        'https://api.indexnow.org/indexnow',
        'https://yandex.com/indexnow',
    ],

];
