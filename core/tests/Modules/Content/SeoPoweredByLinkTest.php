<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * rev/seo R22: the "Powered by" branding on storefront themes must be a
 * FOLLOWED link to the landing home — 3,200+ restaurant pages linking to the
 * marketing site is the internal-link flywheel. Asserted at the source level
 * (the rendered link is also browser-verified across 4 themes × EN+AR, see
 * BUILD-LOG) so a future theme edit that drops the link fails CI.
 */
class SeoPoweredByLinkTest extends TestCase
{
    public static function themeProvider(): array
    {
        return [['vivid'], ['pulse'], ['aurora'], ['minimal']];
    }

    #[DataProvider('themeProvider')]
    public function test_powered_by_is_a_followed_home_link(string $theme): void
    {
        $path = resource_path("views/store_templates/$theme/index.blade.php");
        $this->assertFileExists($path);
        $src = File::get($path);

        $this->assertStringContainsString('Powered by', $src, "$theme still shows Powered by branding");
        $this->assertMatchesRegularExpression(
            '/Powered by[^\n]*<a href="\{\{\s*route\(\x27home\x27\)\s*\}\}"/',
            $src,
            "$theme Powered by must link to the localized home via route('home')"
        );
        $this->assertDoesNotMatchRegularExpression(
            '/Powered by[^\n]*<a href="\{\{\s*route\(\x27home\x27\)[^>]*rel="?nofollow/',
            $src,
            "$theme Powered by link must not be nofollow (kills the backlink equity)"
        );
    }
}
