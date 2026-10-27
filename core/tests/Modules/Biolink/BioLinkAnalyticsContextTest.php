<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use Juwri\Modules\Biolink\Models\BioClick;
use Juwri\Modules\Biolink\Support\BioClickContext;
use App\Support\BioLinkOutbound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * bio-t15 Lane D — SPEC §7. BioClickContext::capture() is the single, PII-free
 * deriver F0 wires into the record call sites (BioPublicController, a Lane B
 * file). Its source/device classification must match BioPublicController's
 * existing G5 logic byte-for-byte (so wiring it in changes nothing there);
 * country/ref are new. BioLinkOutbound::withUtm() is D3's pure UTM-tagging
 * helper (see its docblock for why BioLinkOutbound is NOT the actual outbound
 * render seam — logged in LEDGER "§ Contract changes").
 */
class BioLinkAnalyticsContextTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------ source */

    public function test_explicit_src_query_wins(): void
    {
        $req = Request::create('/x/bio?src=share', 'GET');
        $this->assertSame('share', BioClickContext::classifySource($req));
    }

    public function test_qr_id_query_classifies_qr(): void
    {
        $req = Request::create('/x/bio?qr-id=42', 'GET');
        $this->assertSame('qr', BioClickContext::classifySource($req));
    }

    public function test_social_referrer_classifies_social(): void
    {
        $req = Request::create('/x/bio', 'GET', [], [], [], ['HTTP_REFERER' => 'https://www.instagram.com/store']);
        $this->assertSame('social', BioClickContext::classifySource($req));
    }

    public function test_no_referrer_is_direct(): void
    {
        $req = Request::create('/x/bio', 'GET');
        $this->assertSame('direct', BioClickContext::classifySource($req));
    }

    public function test_unknown_external_referrer_is_other(): void
    {
        $req = Request::create('/x/bio', 'GET', [], [], [], ['HTTP_REFERER' => 'https://some-random-blog.example/post']);
        $this->assertSame('other', BioClickContext::classifySource($req));
    }

    /* ------------------------------------------------------------ device */

    public function test_device_buckets(): void
    {
        $mobile = Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) AppleWebKit']);
        $tablet = Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (iPad; CPU OS 17_0) AppleWebKit']);
        $android = Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 13) AppleWebKit Mobile']);
        $desktop = Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit']);
        // Request::create() defaults HTTP_USER_AGENT to "Symfony" when not given —
        // force a truly empty UA to exercise the "no signal at all" branch.
        $none = Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => '']);

        $this->assertSame('mobile', BioClickContext::classifyDevice($mobile));
        $this->assertSame('tablet', BioClickContext::classifyDevice($tablet));
        $this->assertSame('mobile', BioClickContext::classifyDevice($android));
        $this->assertSame('desktop', BioClickContext::classifyDevice($desktop));
        $this->assertSame('other', BioClickContext::classifyDevice($none));
    }

    /* --------------------------------------------- referrer host bucket (D2) */

    public function test_referrer_bucket_matches_allowlisted_host_and_subdomain(): void
    {
        $req = Request::create('/', 'GET', [], [], [], ['HTTP_REFERER' => 'https://l.instagram.com/?x=1']);
        $this->assertSame('instagram.com', BioClickContext::classifyReferrer($req));
    }

    public function test_referrer_bucket_strips_www(): void
    {
        $req = Request::create('/', 'GET', [], [], [], ['HTTP_REFERER' => 'https://www.google.com/search?q=x']);
        $this->assertSame('google.com', BioClickContext::classifyReferrer($req));
    }

    public function test_referrer_bucket_unknown_host_never_leaks_the_raw_host_or_path(): void
    {
        $req = Request::create('/', 'GET', [], [], [], ['HTTP_REFERER' => 'https://someones-personal-blog.example/post/123?secret=abc']);
        $ref = BioClickContext::classifyReferrer($req);

        $this->assertSame(BioClick::REF_OTHER, $ref);
        $this->assertStringNotContainsString('someones-personal-blog', $ref);
        $this->assertStringNotContainsString('secret', $ref);
    }

    public function test_referrer_bucket_no_referrer_is_none_sentinel(): void
    {
        $req = Request::create('/', 'GET');
        $this->assertSame(BioClick::REF_NONE, BioClickContext::classifyReferrer($req));
    }

    /* --------------------------------------------------- country (D1, geo seam) */

    public function test_country_is_unknown_sentinel_when_geo_is_dark(): void
    {
        config(['geoip.trust_cf_country' => false, 'geoip.mmdb_path' => null]);
        $this->swapRequest([]);

        $this->assertSame(BioClick::COUNTRY_UNKNOWN, BioClickContext::classifyCountry());
    }

    public function test_country_is_unknown_when_cf_header_untrusted(): void
    {
        config(['geoip.trust_cf_country' => true]);
        $this->swapRequest(['HTTP_CF_IPCOUNTRY' => 'DE'], []); // no trusted proxy configured — spoofable, must reject

        $this->assertSame(BioClick::COUNTRY_UNKNOWN, BioClickContext::classifyCountry());
    }

    public function test_country_resolves_from_trusted_cf_header(): void
    {
        config(['geoip.trust_cf_country' => true]);
        $this->swapRequest(['HTTP_CF_IPCOUNTRY' => 'de', 'REMOTE_ADDR' => '10.0.0.9'], ['10.0.0.9']);

        $this->assertSame('DE', BioClickContext::classifyCountry(), 'uppercased 2-letter code');
    }

    /* ------------------------------------------------------- capture() end-to-end */

    public function test_capture_derives_all_four_buckets_together(): void
    {
        config(['geoip.trust_cf_country' => true]);
        $req = $this->swapRequest([
            'HTTP_CF_IPCOUNTRY' => 'FR', 'REMOTE_ADDR' => '10.0.0.9',
            'HTTP_REFERER' => 'https://www.tiktok.com/@store', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone)',
        ], ['10.0.0.9']);

        $ctx = BioClickContext::capture($req);

        $this->assertSame('social', $ctx->source);
        $this->assertSame('mobile', $ctx->device);
        $this->assertSame('FR', $ctx->country);
        $this->assertSame('tiktok.com', $ctx->ref);
    }

    public function test_capture_degrades_cleanly_with_no_signals_at_all(): void
    {
        config(['geoip.trust_cf_country' => false, 'geoip.mmdb_path' => null]);
        // No referrer, no ?src=, no CF header, no geo source — but the request still
        // carries Request::create()'s own default "Symfony" User-Agent, which is a
        // real (non-empty) UA that just doesn't match any device pattern, so it
        // degrades to 'desktop' exactly as BioPublicController's identical logic
        // would for any unrecognised bot/tool UA.
        $req = $this->swapRequest([]);

        $ctx = BioClickContext::capture($req);

        $this->assertSame('direct', $ctx->source);
        $this->assertSame('desktop', $ctx->device);
        $this->assertSame(BioClick::COUNTRY_UNKNOWN, $ctx->country);
        $this->assertSame(BioClick::REF_NONE, $ctx->ref);
    }

    /** Bind (and return) a request carrying the given server vars + trusted proxies (mirrors AutoLocaleBackendTest::swapRequest). */
    private function swapRequest(array $server, array $trustedProxies = []): Request
    {
        Request::setTrustedProxies($trustedProxies, Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST);
        $req = Request::create('/', 'GET', [], [], [], $server + ['REMOTE_ADDR' => '10.0.0.9']);
        $this->app->instance('request', $req);

        return $req;
    }

    /* ---------------------------------------------------------- D3 UTM auto-tag */

    public function test_utm_appended_to_plain_url(): void
    {
        $out = BioLinkOutbound::withUtm('https://instagram.com/thestore', 'social', 'the-slug');

        $this->assertStringContainsString('utm_source=bio', $out);
        $this->assertStringContainsString('utm_medium=social', $out);
        $this->assertStringContainsString('utm_campaign=the-slug', $out);
        $this->assertStringStartsWith('https://instagram.com/thestore?', $out);
    }

    public function test_utm_respects_existing_query_string(): void
    {
        $out = BioLinkOutbound::withUtm('https://example.com/menu?ref=qr', 'qr', 'slug1');

        $this->assertStringContainsString('ref=qr', $out);
        $this->assertStringContainsString('utm_source=bio', $out);
        $this->assertStringContainsString('utm_medium=qr', $out);
    }

    public function test_utm_never_double_appends_on_an_already_tagged_url(): void
    {
        $already = 'https://example.com/menu?utm_source=newsletter&utm_medium=email';

        $out = BioLinkOutbound::withUtm($already, 'social', 'slug1');

        $this->assertSame($already, $out, 'a manually-tagged link is left untouched, never re-tagged');
    }

    public function test_utm_skips_tel_mailto_sms_and_wa_me(): void
    {
        $this->assertSame('tel:+123456', BioLinkOutbound::withUtm('tel:+123456', 'direct', 'slug'));
        $this->assertSame('mailto:hi@x.com', BioLinkOutbound::withUtm('mailto:hi@x.com', 'direct', 'slug'));
        $this->assertSame('sms:+123456', BioLinkOutbound::withUtm('sms:+123456', 'direct', 'slug'));
        $this->assertSame('https://wa.me/123456?text=hi', BioLinkOutbound::withUtm('https://wa.me/123456?text=hi', 'direct', 'slug'));
    }

    public function test_utm_unknown_source_falls_back_to_other_medium(): void
    {
        $out = BioLinkOutbound::withUtm('https://example.com', 'bogus-source', 'slug1');

        $this->assertStringContainsString('utm_medium=other', $out);
    }

    public function test_utm_empty_url_is_returned_as_is(): void
    {
        $this->assertSame('', BioLinkOutbound::withUtm('', 'direct', 'slug'));
        $this->assertSame('#', BioLinkOutbound::withUtm('#', 'direct', 'slug'));
    }
}
