<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Legal;

use Tests\TestCase;

/**
 * P1-23: lang/<locale>/site.php (website-text.md keys + legal/consent UI lines) exists for all 11 locales with the
 * same keys, the same :placeholders and the same [link](/path) targets as English, and is really translated.
 */
class SiteLangTest extends TestCase
{
    private const LOCALES = ['en', 'ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'];

    /** Values that are correctly the same as English in that language. */
    private const SAME_AS_ENGLISH = ['es' => ['footer.legal'], 'fr' => ['footer.contact']];

    /** @return list<string> */
    private static function placeholders(string $line): array
    {
        preg_match_all('/(?<![\w@]):[a-z_]+\b/', str_replace('legal@juwri.com', '', $line), $m);
        sort($m[0]);

        return $m[0];
    }

    /** @return list<string> */
    private static function links(string $line): array
    {
        preg_match_all('~\]\((/[^)]*)\)~', $line, $m);

        return $m[1];
    }

    public function test_every_locale_has_the_same_keys_placeholders_and_links_and_is_translated(): void
    {
        $en = require lang_path('en/site.php');
        $this->assertCount(53, $en); // 53: reseller rework v2 added footer.free_reseller; P6-121 added legal.updated
        foreach (self::LOCALES as $locale) {
            $lines = require lang_path($locale.'/site.php');
            $this->assertSame(array_keys($en), array_keys($lines), "$locale: keys");
            foreach ($en as $key => $english) {
                $this->assertSame(self::placeholders($english), self::placeholders($lines[$key]), "$locale $key: placeholders");
                $this->assertSame(self::links($english), self::links($lines[$key]), "$locale $key: links");
                if ($locale !== 'en' && ! in_array($key, self::SAME_AS_ENGLISH[$locale] ?? [], true)) {
                    $this->assertNotSame($english, $lines[$key], "$locale $key: still English");
                }
            }
        }
    }

    public function test_the_company_line_keeps_the_registered_facts(): void
    {
        foreach (self::LOCALES as $locale) {
            $company = (require lang_path($locale.'/site.php'))['footer.company'];
            foreach (['JUWRI LTD', '14363043', '128 City Road', 'EC1V 2NX'] as $fact) {
                $this->assertStringContainsString($fact, $company, $locale);
            }
        }
    }
}
