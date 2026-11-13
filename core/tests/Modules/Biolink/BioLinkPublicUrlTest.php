<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Tests\TestCase;

/**
 * BIOLINK-TPL A1: the bio_public_url() helper every non-bio surface (QR, campaigns,
 * GBP, Wallet, directory) uses to link to a store's bio page with an attribution
 * bucket. Domain-correct origin (via effective_public_url) + a sanitised src param.
 */
class BioLinkPublicUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_the_slug_bio_url_for_a_post(): void
    {
        $store = Store::factory()->create();

        $url = bio_public_url($store);

        $this->assertStringEndsWith('/'.$store->slug.'/bio', $url);
        $this->assertStringNotContainsString('src=', $url);
    }

    public function test_appends_the_src_attribution_param(): void
    {
        $store = Store::factory()->create();

        $this->assertStringContainsString('?src=share', bio_public_url($store, 'share'));
        $this->assertStringContainsString('?src=qr', bio_public_url($store, 'qr'));
        $this->assertStringContainsString('?src=social', bio_public_url($store, 'social'));
    }

    public function test_matches_the_named_bio_route_on_the_platform_host(): void
    {
        $store = Store::factory()->create();

        // With no custom domain, the origin is the platform-host slug route, so the
        // helper is byte-identical to route('bio.public', slug).
        $this->assertSame(route('bio.public', $store->slug), bio_public_url($store));
    }

    public function test_accepts_a_raw_slug_string(): void
    {
        $url = bio_public_url('some-store-slug', 'direct');

        $this->assertStringContainsString('/some-store-slug/bio', $url);
        $this->assertStringContainsString('src=direct', $url);
    }

    public function test_sanitises_a_hostile_src_value(): void
    {
        $store = Store::factory()->create();

        $url = bio_public_url($store, 'sh are"><script>');

        // Non-word chars are stripped before the value is url-encoded into the query.
        $this->assertStringContainsString('src=sharescript', $url);
        $this->assertStringNotContainsString('<script>', $url);
    }
}
