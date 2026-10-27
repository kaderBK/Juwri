<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Tables;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Tables\Models\QrDesign;
use Juwri\Modules\Tables\Services\Qr\QrPresets;
use Juwri\Modules\Tables\Services\Qr\QrRenderService;
use Tests\TestCase;

/**
 * PARITY-RUN T10-2: the render service itself.
 */
class QrRenderServiceTest extends TestCase
{
    use RefreshDatabase;

    private QrRenderService $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->renderer = app(QrRenderService::class);
    }

    private const URL = 'https://example.test/demo?qr-id=abc';

    public function test_render_is_deterministic(): void
    {
        $settings = QrPresets::settings('espresso');

        $a = $this->renderer->svg(self::URL, $settings);
        $b = $this->renderer->svg(self::URL, $settings);

        $this->assertSame($a, $b, 'The same design + payload must produce byte-identical SVG.');
        $this->assertStringStartsWith('<svg', $a);
    }

    public function test_a_different_payload_changes_the_render(): void
    {
        $settings = QrPresets::settings('classic');

        $this->assertNotSame(
            $this->renderer->svg(self::URL, $settings),
            $this->renderer->svg(self::URL.'&table=zzz', $settings)
        );
    }

    public function test_a_different_design_changes_the_render(): void
    {
        $this->assertNotSame(
            $this->renderer->svg(self::URL, QrPresets::settings('classic')),
            $this->renderer->svg(self::URL, QrPresets::settings('mint'))
        );
    }

    public function test_png_export_is_a_real_png(): void
    {
        $png = $this->renderer->png(self::URL, QrPresets::settings('classic'), ['size' => 320]);

        $this->assertNotSame('', $png);
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));

        $img = imagecreatefromstring($png);
        $this->assertNotFalse($img);
        $this->assertGreaterThan(100, imagesx($img));
        imagedestroy($img);
    }

    /** A logo overlay ALWAYS forces error-correction H — the overlay eats modules. */
    public function test_a_logo_forces_error_correction_h(): void
    {
        $this->assertSame('M', QrDesign::normalize([])['ec']);
        $this->assertSame('H', QrDesign::normalize(['logo_file' => 'brand.png', 'ec' => 'L'])['ec']);
    }

    public function test_normalize_falls_back_instead_of_throwing_on_junk(): void
    {
        $settings = QrDesign::normalize([
            'fg_color' => '<script>',
            'bg_color' => 'nope',
            'dot_style' => 'squircle',
            'eye_style' => 'blob',
            'frame' => 'diagonal',
            'quiet_zone' => 99,
            'logo_scale' => 900,
            'size' => 99999,
        ]);

        $this->assertSame('#0a0a0a', $settings['fg']);
        $this->assertSame('#ffffff', $settings['bg']);
        $this->assertSame('square', $settings['dot_style']);
        $this->assertSame('square', $settings['eye_style']);
        $this->assertSame('none', $settings['frame']);
        $this->assertSame(8, $settings['quiet_zone']);
        $this->assertSame(40, $settings['logo_scale']);
        $this->assertSame(QrDesign::MAX_SIZE, $settings['size']);
    }

    /** Fiscal/thermal surfaces need a code with no colour, gradient, logo or frame. */
    public function test_monochrome_strips_branding(): void
    {
        $mono = QrDesign::monochrome(QrDesign::normalize([
            'fg_color' => '#0f766e',
            'bg_color' => '#f0fdfa',
            'gradient_to' => '#b91c1c',
            'logo_file' => 'brand.png',
            'frame' => 'bottom',
            'frame_text' => 'Scan',
        ]));

        $this->assertSame('#000000', $mono['fg']);
        $this->assertSame('#ffffff', $mono['bg']);
        $this->assertNull($mono['gradient_to']);
        $this->assertNull($mono['logo_file']);
        $this->assertSame('none', $mono['frame']);
    }

    public function test_frame_text_is_rendered_and_escaped(): void
    {
        $svg = $this->renderer->svg(self::URL, QrDesign::normalize([
            'frame' => 'bottom',
            'frame_text' => 'Scan & <order>',
        ]));

        $this->assertStringContainsString('<text', $svg);
        $this->assertStringContainsString('Scan &amp; &lt;order&gt;', $svg);
        $this->assertStringNotContainsString('<order>', $svg);
    }

    /** The legacy helper keeps its old signature and gains an optional EC level. */
    public function test_legacy_qr_svg_helper_still_renders(): void
    {
        $this->assertStringStartsWith('<svg', qr_svg(self::URL));
        $this->assertStringStartsWith('<svg', qr_svg(self::URL, 140));
        $this->assertNotSame(qr_svg(self::URL, 140), qr_svg(self::URL, 140, 'H'));
    }

    /** An unbranded/ungated store must still get a QR — the plain one. */
    public function test_branded_helper_degrades_to_plain_when_the_feature_is_off(): void
    {
        config(['settings.admin_allow_qr_designer' => false]);

        $store = $this->makeStoreWithDesign();
        $svg = branded_qr_svg($store->id, self::URL);

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('#000000', $svg);
        $this->assertStringNotContainsString('#0f766e', $svg, 'A killed feature must not leak the branded colours.');
    }

    public function test_branded_helper_uses_the_stores_design_when_the_feature_is_on(): void
    {
        config(['settings.admin_allow_qr_designer' => true]);

        $store = $this->makeStoreWithDesign();

        $this->assertStringContainsString('#0f766e', branded_qr_svg($store->id, self::URL));
    }

    private function makeStoreWithDesign(): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'qr-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        QrDesign::create([
            'store_id' => $store->id, 'is_default' => true, 'name' => 'Default',
            'fg_color' => '#0f766e', 'bg_color' => '#ffffff', 'eye_color' => '#0f766e',
            'dot_style' => 'rounded', 'eye_style' => 'rounded', 'quiet_zone' => 4, 'size' => 512,
        ]);

        return $store;
    }
}
