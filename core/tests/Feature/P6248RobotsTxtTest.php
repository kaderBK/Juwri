<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** P6-248: robots.txt is dynamic: noindex off production, this host's sitemap. */
class P6248RobotsTxtTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_allows_crawlers_and_names_this_hosts_sitemap(): void
    {
        $this->app['env'] = 'production';

        $res = $this->get('http://shop.localhost/robots.txt')->assertOk();

        $res->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertStringContainsString('Allow: /', $res->getContent());
        $this->assertStringContainsString('Disallow: /admin', $res->getContent());
        $this->assertStringContainsString('Sitemap: http://shop.localhost/sitemap.xml', $res->getContent());
        $this->assertStringNotContainsString('juwri.com', $res->getContent());
        $this->assertFalse($res->headers->has('X-Robots-Tag'));
    }

    public function test_staging_is_fully_disallowed_and_noindexed(): void
    {
        $this->app['env'] = 'staging';

        $res = $this->get('http://staging.localhost/robots.txt')->assertOk();

        $this->assertStringContainsString('Disallow: /', $res->getContent());
        $this->assertStringNotContainsString('Allow: /', $res->getContent());
        $this->assertStringNotContainsString('Sitemap:', $res->getContent());
        $res->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_reseller_host_in_production_gets_its_own_sitemap_line(): void
    {
        $this->app['env'] = 'production';

        $res = $this->get('http://menu.localhost/robots.txt')->assertOk();

        $this->assertStringContainsString('Sitemap: http://menu.localhost/sitemap.xml', $res->getContent());
    }

    public function test_noindex_flag_overrides_production(): void
    {
        $this->app['env'] = 'production';
        config(['seo.noindex' => true]);

        $this->get('http://shop.localhost/robots.txt')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
