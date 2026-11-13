<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink\Bio;

use App\Models\StoreOption;
use App\Services\FeatureRegistry;
use App\Support\BioAppearance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\EntitlementWriter;
use Tests\TestCase;

/**
 * NOTE on FeatureRegistry::clearCache(): FeatureRegistry::$tableCache is a
 * per-app-instance memo (rebindMemoOnNewApp()), but `bio_pro`/`bio_analytics_pro`
 * have NO PHP-catalog fallback entry (deliberately — FeatureRegistry.php is
 * outside this lane's file fence; see the B9 migration docblock), so unlike
 * `biolink`/`ai_biolink` (which ARE in that fallback and so read correctly
 * either way) this test is the only place a stale memo from test-process
 * reuse would be visible. clearCache() makes the read deterministic.
 */

/**
 * bio-t15 Lane B (SPEC §3–§9): the appearance sanitizer/token-mapping contract,
 * the B9 ship-migration guardrail, and the public-page render pipeline (CSP,
 * backgrounds, fonts, OG/SEO, avatar shape, contrast guard).
 */
class BioAppearanceTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string,mixed>  $bioSettingsOverrides */
    private function enableBio(Store $store, array $bioSettingsOverrides = []): void
    {
        config(['settings.admin_allow_biolink' => true]);
        EntitlementWriter::grant($store->user, 'biolink');
        StoreOption::updateStoreOption($store->id, 'bio_settings', json_encode(array_merge([
            'enabled' => true, 'preset' => 'classic_flat', 'mode' => 'menu_first',
        ], $bioSettingsOverrides)));
        StoreOption::flushOptionMemo();
    }

    private function enableBioPro(): void
    {
        config(['settings.admin_allow_bio_pro' => true]);
    }

    // ── B9: the ship migration ──────────────────────────────────────────

    public function test_bio_pro_and_analytics_pro_ship_dark(): void
    {
        FeatureRegistry::clearCache();
        $keyed = collect(FeatureRegistry::all())->keyBy('key');

        foreach (['bio_pro', 'bio_analytics_pro'] as $key) {
            $this->assertTrue($keyed->has($key), "$key registry row present");
            $this->assertFalse($keyed[$key]['default'], "$key kill-switch OFF by default (dark)");
            $this->assertTrue($keyed[$key]['shipped'], "$key shipped=true so the gate is toggleable");
            $this->assertFalse(FeatureRegistry::isEnabled($key), "$key resolves OFF with no admin override");
        }

        $shipped = array_map(fn ($e) => $e['key'], FeatureRegistry::shipped());
        $this->assertContains('bio_pro', $shipped);
        $this->assertContains('bio_analytics_pro', $shipped);
    }

    // ── B1 sanitizer: whitelist + validation ────────────────────────────

    public function test_sanitize_rejects_unsafe_color_values(): void
    {
        $out = BioAppearance::sanitize([
            'accent' => 'url(javascript:alert(1))',
            'ink' => '#123;background:url(x)',
            'muted' => 'red', // named colors are not the whitelisted #rrggbb form
            'btn_bg' => '#ABCDEF', // valid, mixed case
        ]);

        $this->assertArrayNotHasKey('accent', $out);
        $this->assertArrayNotHasKey('muted', $out);
        $this->assertArrayNotHasKey('ink', $out, 'a malformed value never survives as the raw attacker string');
        $this->assertSame('#abcdef', $out['btn_bg']);
    }

    public function test_sanitize_rejects_unknown_enum_values(): void
    {
        $out = BioAppearance::sanitize([
            'btn_style' => 'javascript:alert(1)',
            'btn_radius' => 'huge',
            'avatar_shape' => 'triangle',
            'bg_type' => 'not-a-type',
        ]);

        $this->assertNull($out['btn_style']);
        $this->assertNull($out['btn_radius']);
        $this->assertNull($out['avatar_shape']);
        $this->assertNull($out['bg_type']);
    }

    public function test_sanitize_clamps_numbers(): void
    {
        $out = BioAppearance::sanitize([
            'bg_scrim' => 9999,
            'bg_gradient' => ['from' => '#111111', 'to' => '#eeeeee', 'angle' => -50, 'type' => 'linear'],
        ]);
        $this->assertSame(100, $out['bg_scrim']);

        $out2 = BioAppearance::sanitize([
            'bg_gradient' => ['from' => '#111111', 'to' => '#eeeeee', 'angle' => 999, 'type' => 'linear'],
        ]);
        $this->assertSame(360, $out2['bg_gradient']['angle'], 'an out-of-range angle clamps to the nearest boundary (0-360)');
    }

    public function test_sanitize_rejects_font_not_in_curated_list(): void
    {
        $out = BioAppearance::sanitize(['font_display' => 'Comic Sans MS']);
        $this->assertNull($out['font_display']);

        $out2 = BioAppearance::sanitize(['font_display' => 'Poppins']);
        $this->assertSame('Poppins', $out2['font_display']);
    }

    public function test_sanitize_rejects_path_traversal_in_media_filenames(): void
    {
        $out = BioAppearance::sanitize([
            'bg_image' => '../../etc/passwd',
            'cover_image' => 'http://evil.example/x.png',
            'og' => ['image' => 'a/b.png'],
        ], ['bio_pro' => true]);

        $this->assertNull($out['bg_image']);
        $this->assertNull($out['cover_image']);
        $this->assertNull($out['og']['image']);
    }

    // ── B8 gating: PRO stripped server-side unless entitled ────────────

    public function test_image_background_stripped_without_bio_pro(): void
    {
        $out = BioAppearance::sanitize(['bg_type' => 'image', 'bg_image' => 'bg.jpg'], ['bio_pro' => false]);
        $this->assertNull($out['bg_type']);
        $this->assertNull($out['bg_image']);
    }

    public function test_image_background_allowed_with_bio_pro(): void
    {
        $out = BioAppearance::sanitize(['bg_type' => 'image', 'bg_image' => 'bg.jpg'], ['bio_pro' => true]);
        $this->assertSame('image', $out['bg_type']);
        $this->assertSame('bg.jpg', $out['bg_image']);
    }

    public function test_font_pairing_beyond_one_requires_bio_pro(): void
    {
        $raw = ['font_display' => 'Poppins', 'font_body' => 'Lora'];

        $free = BioAppearance::sanitize($raw, ['bio_pro' => false]);
        $this->assertSame('Poppins', $free['font_display']);
        $this->assertNull($free['font_body'], 'pairing two DIFFERENT curated families is PRO');

        $pro = BioAppearance::sanitize($raw, ['bio_pro' => true]);
        $this->assertSame('Poppins', $pro['font_display']);
        $this->assertSame('Lora', $pro['font_body']);
    }

    public function test_deferred_video_and_uploaded_font_never_render(): void
    {
        $out = BioAppearance::sanitize([
            'bg_type' => 'video', 'bg_video' => 'clip.mp4',
            'font_upload' => 'MyFont.woff2',
        ], ['bio_pro' => true]); // even WITH bio_pro — these are infra-deferred, not plan-gated

        $this->assertNull($out['bg_type'], 'video bg is gated off pending the owner infra follow-up (SPEC §9)');
        $this->assertNull($out['font_upload']);

        $tokens = BioAppearance::toTokens(['bg_type' => 'video'], ['bio_pro' => true]);
        $this->assertArrayNotHasKey('--bg', $tokens);
    }

    // ── B7 contrast guard ───────────────────────────────────────────────

    public function test_contrast_guard_catches_white_on_white(): void
    {
        $out = BioAppearance::sanitize(['ink' => '#ffffff', 'bg_type' => 'solid', 'bg_solid' => '#ffffff']);

        $this->assertTrue(
            BioAppearance::passesAA($out['ink'], '#ffffff'),
            'the sanitizer must have nudged ink to something that actually reads on white'
        );
        $this->assertNotSame('#ffffff', $out['ink']);
    }

    public function test_contrast_guard_leaves_a_passing_pair_untouched(): void
    {
        $out = BioAppearance::sanitize(['ink' => '#111111', 'bg_type' => 'solid', 'bg_solid' => '#ffffff']);
        $this->assertSame('#111111', $out['ink']);
    }

    // ── B4 fonts / B5 avatar / B3 backgrounds — the token map itself ────

    public function test_to_tokens_maps_the_full_spec_table(): void
    {
        $tokens = BioAppearance::toTokens([
            'accent' => '#0f7d5a', 'accent_ink' => '#ffffff',
            'ink' => '#111111', 'muted' => '#666666',
            'btn_bg' => '#ffffff', 'btn_fg' => '#111111',
            'btn_style' => 'outline', 'btn_radius' => 'pill', 'btn_align' => 'center',
            'show_icons' => false, 'show_arrows' => false,
            'avatar_shape' => 'square',
            'font_display' => 'Fraunces', 'font_body' => 'Fraunces',
        ]);

        $this->assertSame('#0f7d5a', $tokens['--accent']);
        $this->assertSame('#ffffff', $tokens['--accent-ink']);
        $this->assertSame('#111111', $tokens['--ink']);
        $this->assertSame('#666666', $tokens['--muted']);
        $this->assertSame('#ffffff', $tokens['--btn-bg']);
        $this->assertSame('#ffffff', $tokens['--card']);
        $this->assertSame('#111111', $tokens['--btn-fg']);
        $this->assertSame('1.5px', $tokens['--btn-border-width']);
        $this->assertSame('999px', $tokens['--radius']);
        $this->assertSame('center', $tokens['--link-justify']);
        $this->assertSame('none', $tokens['--icon-display']);
        $this->assertSame('none', $tokens['--arrow-display']);
        $this->assertSame('0px', $tokens['--avatar-radius']);
        $this->assertStringContainsString('Fraunces', $tokens['--font-display']);
    }

    public function test_pattern_background_token_survives_the_style_stripper(): void
    {
        $tokens = BioAppearance::toTokens(['bg_type' => 'pattern', 'bg_pattern' => 'zellige']);
        $this->assertArrayHasKey('--bg', $tokens);

        // bio.layout strips <, >, {, }, ; and newlines out of every token VALUE
        // before emitting the one nonced <style> — a raw <svg> would be mangled.
        foreach (['<', '>', '{', '}', ';', "\n", "\r"] as $bad) {
            $this->assertStringNotContainsString($bad, $tokens['--bg'], "pattern token must not contain '$bad'");
        }
        $this->assertStringStartsWith("url('data:image/svg+xml,", $tokens['--bg']);
    }

    public function test_gradient_background_token(): void
    {
        $tokens = BioAppearance::toTokens([
            'bg_type' => 'gradient',
            'bg_gradient' => ['from' => '#111111', 'to' => '#eeeeee', 'angle' => 45, 'type' => 'linear'],
        ]);
        $this->assertSame('linear-gradient(45deg, #111111, #eeeeee)', $tokens['--bg']);
    }

    public function test_image_background_is_not_a_token(): void
    {
        $tokens = BioAppearance::toTokens(['bg_type' => 'image', 'bg_image' => 'a.jpg'], ['bio_pro' => true]);
        $this->assertArrayNotHasKey('--bg', $tokens, 'SPEC §3: image bg is an inline style, never a --bg token');
        $this->assertArrayHasKey('--bg-scrim', $tokens);
    }

    // ── Full render pipeline (HTTP through the real public route) ──────

    public function test_public_page_applies_appearance_tokens_inside_the_one_nonced_style(): void
    {
        $store = Store::factory()->create();
        $this->enableBio($store, [
            'appearance' => [
                'accent' => '#ff6600',
                'btn_radius' => 'pill',
                'avatar_shape' => 'square',
            ],
        ]);

        $html = $this->get('/'.$store->slug.'/bio')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<style nonce='), 'exactly one nonced <style> (CSP)');
        $this->assertSame(1, substr_count($html, '<script nonce='), 'exactly one nonced <script> (CSP)');
        $this->assertStringContainsString('--accent:#ff6600', $html);
        $this->assertStringContainsString('--radius:999px', $html);
        $this->assertStringContainsString('--avatar-radius:0px', $html);
    }

    public function test_public_page_never_leaks_raw_user_css_into_a_token(): void
    {
        $store = Store::factory()->create();
        $this->enableBio($store, [
            'appearance' => [
                'accent' => '}</style><script>alert(1)</script>',
            ],
        ]);

        $html = $this->get('/'.$store->slug.'/bio')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<style nonce='));
        $this->assertSame(1, substr_count($html, '<script nonce='));
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_image_background_requires_bio_pro_on_the_public_page(): void
    {
        $store = Store::factory()->create();
        $this->enableBio($store, [
            'appearance' => ['bg_type' => 'image', 'bg_image' => 'hero.jpg'],
        ]);

        // Not entitled: no image style at all.
        $html = $this->get('/'.$store->slug.'/bio')->assertOk()->getContent();
        $this->assertStringNotContainsString('background-image:url', $html);

        // Entitled: the inline style appears on .bio-body.
        $this->enableBioPro();
        $html = $this->get('/'.$store->slug.'/bio')->assertOk()->getContent();
        $this->assertStringContainsString('background-image:url', $html);
        $this->assertStringContainsString('hero.jpg', $html);
        // …and the body carries bio-body--img, the hook appearance.css uses to make
        // the .bio column transparent so the image shows THROUGH on mobile (without
        // it the opaque column covers the image on any viewport <= its width).
        $this->assertStringContainsString('bio-body--img', $html);
    }

    public function test_curated_fonts_are_appended_to_the_google_fonts_link(): void
    {
        $store = Store::factory()->create();
        $this->enableBio($store, [
            'appearance' => ['font_display' => 'Sora'],
        ]);

        $html = $this->get('/'.$store->slug.'/bio')->assertOk()->getContent();

        $this->assertStringContainsString('fonts.googleapis.com/css2', $html);
        $this->assertStringContainsString('family=Sora', $html);
    }

    public function test_og_and_seo_meta_render_in_head(): void
    {
        $store = Store::factory()->create(['title' => 'Test Diner']);
        $this->enableBio($store, [
            'title' => 'Test Diner Bio',
            'appearance' => ['og' => ['title' => 'Custom OG Title', 'description' => 'Great food.']],
        ]);

        $html = $this->get('/'.$store->slug.'/bio')->assertOk()->getContent();

        $this->assertStringContainsString('<title>Custom OG Title</title>', $html);
        $this->assertStringContainsString('property="og:title" content="Custom OG Title"', $html);
        $this->assertStringContainsString('Great food.', $html);
        $this->assertStringContainsString('name="twitter:card"', $html);
    }

    public function test_hide_toggles_remove_tagline_status_and_verified(): void
    {
        $store = Store::factory()->create();
        $this->enableBio($store, [
            'tagline' => 'Best tacos in town',
            'status_label' => 'Open now',
            'verified' => true,
            'appearance' => ['hide' => ['tagline' => true, 'status' => true, 'verified' => true]],
        ]);

        $html = $this->get('/'.$store->slug.'/bio')->assertOk()->getContent();

        $this->assertStringNotContainsString('Best tacos in town', $html);
        $this->assertStringNotContainsString('Open now', $html);
    }

    // ── profile header: display name / tagline / avatar (drive .bio-name / .bio-profile) ──

    public function test_sanitize_keeps_profile_name_tagline_and_avatar(): void
    {
        $out = BioAppearance::sanitize([
            'display_name' => "  My Bio\x00Page  ",  // control char stripped, trimmed
            'tagline' => 'Tap to order',
            'avatar_image' => 'avatar.png',
        ]);

        $this->assertSame('My BioPage', $out['display_name']);
        $this->assertSame('Tap to order', $out['tagline']);
        $this->assertSame('avatar.png', $out['avatar_image']);
    }

    public function test_sanitize_rejects_a_url_or_traversal_as_avatar(): void
    {
        // avatar_image is a bare library filename only — never a pasted URL or a path.
        $this->assertNull(BioAppearance::sanitize(['avatar_image' => 'https://evil.example/a.png'])['avatar_image']);
        $this->assertNull(BioAppearance::sanitize(['avatar_image' => '../../etc/passwd'])['avatar_image']);
    }

    public function test_display_name_and_tagline_override_the_store_name_on_the_public_page(): void
    {
        $store = Store::factory()->create(['title' => 'Legal Store Name LLC']);
        $this->enableBio($store, [
            'appearance' => ['display_name' => 'Sunset Cafe', 'tagline' => 'Coffee & more'],
        ]);

        $html = $this->get('/'.$store->slug.'/bio')->assertOk()->getContent();

        // The owner's chosen name is the page heading (.bio-name), not the store title.
        $this->assertMatchesRegularExpression('/class="bio-name"[^>]*>\s*Sunset Cafe/', $html);
        $this->assertStringContainsString('Coffee &amp; more', $html); // Blade-escaped
    }
}
