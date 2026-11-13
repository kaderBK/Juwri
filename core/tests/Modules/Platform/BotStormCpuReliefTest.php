<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Tests\TestCase;

/**
 * PROD CPU (2026-09-17): the box sat at 163-197% of its 400% CloudLinux limit
 * with 188 throttle faults in 7h (baseline: 31 in 24h). Cause was not traffic
 * growth but two shapes of crawler load hitting PHP:
 *
 *   1. 256,288 requests in 6h for menu images lost in the July media damage.
 *      A missing file falls through to index.php, so each 404 booted the whole
 *      framework. Apache can answer those for free.
 *   2. meta-externalads (Facebook's ADS crawler) re-scraping those same dead
 *      og:image URLs: 304,415 of 377,887 requests (81%), essentially all 404s.
 *
 * Both fixes live in .htaccess, which no PHP test can execute — so guard the
 * rules textually, and guard the ORDER, which is the part that silently breaks:
 * the storage rule is only free if it precedes the front-controller fallback.
 */
class BotStormCpuReliefTest extends TestCase
{
    private function htaccess(): string
    {
        $body = @file_get_contents(dirname(base_path()).'/.htaccess');
        $this->assertNotFalse($body, '.htaccess should exist at repo root');

        return (string) $body;
    }

    public function test_missing_storage_files_404_from_apache_before_reaching_php(): void
    {
        $htaccess = $this->htaccess();

        $this->assertMatchesRegularExpression(
            '/RewriteRule\s+\^storage\/\s+-\s+\[R=404,L\]/',
            $htaccess,
            'missing files under /storage/ must 404 from Apache, not boot Laravel'
        );

        $storageRule = strpos($htaccess, 'RewriteRule ^storage/ - [R=404,L]');
        $frontController = strpos($htaccess, 'RewriteRule ^ index.php [L]');
        $this->assertNotFalse($storageRule);
        $this->assertNotFalse($frontController);
        $this->assertLessThan(
            $frontController,
            $storageRule,
            'the /storage/ 404 rule is inert unless it precedes the front-controller fallback'
        );
    }

    public function test_facebook_ads_crawler_is_denied_but_the_link_preview_crawler_is_not(): void
    {
        $htaccess = $this->htaccess();

        $this->assertStringContainsString('meta-externalads', $htaccess);
        $this->assertMatchesRegularExpression(
            '/RewriteCond\s+%\{HTTP_USER_AGENT\}\s+meta-externalads\s+\[NC\]\s*\R\s*RewriteRule\s+\^\s+-\s+\[F,L\]/',
            $htaccess,
            'meta-externalads must be denied outright'
        );

        /* facebookexternalhit renders the link previews people see when they
           share a menu on Facebook/Messenger/WhatsApp. Denying it would break a
           feature diners actually see, so it must NOT be caught by this rule. */
        $this->assertStringNotContainsString(
            'RewriteCond %{HTTP_USER_AGENT} facebookexternalhit',
            $htaccess,
            'facebookexternalhit powers share previews and must keep working'
        );
    }
}
