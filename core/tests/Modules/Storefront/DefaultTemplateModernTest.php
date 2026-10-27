<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Services\StorefrontThemeService;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Tests\TestCase;

/**
 * ISS-B-003 — with no configured settings.default_post_template, a brand-new
 * store must seed onto a KEPT MODERN template, not whatever legacy theme sorts
 * first in available() (e.g. 'Ice-cream-theme', which ships 404'd classic
 * assets and renders unstyled). Code-path default only; does not change the
 * app-wide setting.
 */
class DefaultTemplateModernTest extends TestCase
{
    public function test_default_slug_is_a_kept_modern_template_when_unconfigured(): void
    {
        config(['settings.default_post_template' => '']);

        $svc = app(StorefrontThemeService::class);
        $available = array_keys($svc->available());

        // Only meaningful if at least one modern template is installed.
        $modernInstalled = array_values(array_intersect(KeptTemplates::MODERN, $available));
        if (! $modernInstalled) {
            $this->markTestSkipped('no kept modern template installed in this env');
        }

        $slug = $svc->defaultSlug();

        $this->assertTrue(
            KeptTemplates::isModern($slug),
            "defaultSlug() returned '{$slug}', expected a kept modern template (".implode(',', $modernInstalled).')'
        );
    }

    public function test_configured_default_still_wins_when_available(): void
    {
        $svc = app(StorefrontThemeService::class);
        $available = array_keys($svc->available());
        if (! in_array('crave', $available, true)) {
            $this->markTestSkipped('crave not installed in this env');
        }

        config(['settings.default_post_template' => 'crave']);
        $this->assertSame('crave', $svc->defaultSlug());
    }
}
