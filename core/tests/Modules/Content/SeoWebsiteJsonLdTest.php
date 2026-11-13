<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Models\Language;
use Tests\TestCase;

/**
 * Google "site names" (https://developers.google.com/search/docs/appearance/site-names)
 * reads a WebSite structured-data node to pick the site name shown in results.
 * It must be a SINGLE node placed on the homepage (root URI) only — subpages
 * must not carry their own. seo_website_jsonld() emits it and home/index pushes
 * it once; no SearchAction (the marketing site has no public search endpoint).
 */
class SeoWebsiteJsonLdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);

        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    public function test_helper_emits_a_valid_single_website_node(): void
    {
        $html = seo_website_jsonld();

        $this->assertStringContainsString('"@type":"WebSite"', $html);

        preg_match('#<script type="application/ld\+json">(.*)</script>#s', $html, $m);
        $json = json_decode($m[1], true);
        $this->assertSame('WebSite', $json['@type']);
        $this->assertNotEmpty($json['name']);
        $this->assertNotEmpty($json['url']);
        $this->assertArrayHasKey('alternateName', $json, 'platform host advertises brand aliases');
    }

    public function test_home_carries_the_website_node_but_a_subpage_does_not(): void
    {
        $this->pitchOrSkip();
        $home = $this->get(route('home'));
        $home->assertOk();
        $home->assertSeeHtml('"@type":"WebSite"');

        $features = $this->get(route('features'));
        $features->assertOk();
        $features->assertDontSeeHtml('"@type":"WebSite"');
    }

    private function pitchOrSkip(): void
    {
        if (! Route::has('pricing') && ! Route::has('guides.show')) {
            $this->markTestSkipped('pitch routes belong to the edition package');
        }
        config(['settings.platform_marketing' => true]);
    }
}
