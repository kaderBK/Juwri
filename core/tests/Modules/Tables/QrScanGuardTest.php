<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Tables;

use Juwri\Modules\Tables\Models\QrDesign;
use Juwri\Modules\Tables\Services\Qr\QrPresets;
use Juwri\Modules\Tables\Services\Qr\QrScanGuard;
use Tests\TestCase;

/**
 * PARITY-RUN T10-2: the scan-validation guard.
 *
 * The guard is rules + a raster round-trip (render → re-sample every module
 * centre → compare with the encoder's matrix), NOT a Reed–Solomon decode; there
 * is no pure-PHP QR decoder in the dependency tree. These tests pin both layers.
 */
class QrScanGuardTest extends TestCase
{
    private const URL = 'https://example.test/demo?qr-id=abc';

    private QrScanGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->guard = app(QrScanGuard::class);
    }

    /** The gallery is the first thing an owner clicks: none of it may be unsavable. */
    public function test_every_shipped_preset_passes_the_guard(): void
    {
        foreach (QrPresets::keys() as $key) {
            $verdict = $this->guard->verify(self::URL, QrPresets::settings($key));
            $this->assertTrue($verdict['ok'], "Preset [{$key}] must pass the scan guard ({$verdict['reason']}).");
        }
    }

    public function test_low_contrast_is_refused(): void
    {
        $verdict = $this->guard->verify(self::URL, QrDesign::normalize([
            'fg_color' => '#c8c8c8', 'bg_color' => '#ffffff',
        ]));

        $this->assertFalse($verdict['ok']);
        $this->assertSame('low_contrast', $verdict['reason']);
        $this->assertNotEmpty($verdict['message']);
    }

    /** A light-on-dark code is legal QR but a coin-flip on real scanners. */
    public function test_an_inverted_code_is_refused(): void
    {
        $verdict = $this->guard->verify(self::URL, QrDesign::normalize([
            'fg_color' => '#ffffff', 'bg_color' => '#111111',
        ]));

        $this->assertFalse($verdict['ok']);
        $this->assertSame('inverted', $verdict['reason']);
    }

    public function test_a_low_contrast_gradient_end_is_refused(): void
    {
        $verdict = $this->guard->verify(self::URL, QrDesign::normalize([
            'fg_color' => '#111111', 'bg_color' => '#ffffff', 'gradient_to' => '#eeeeee',
        ]));

        $this->assertFalse($verdict['ok']);
        $this->assertSame('low_contrast', $verdict['reason']);
    }

    public function test_a_thin_quiet_zone_is_refused(): void
    {
        $verdict = $this->guard->verify(self::URL, QrDesign::normalize(['quiet_zone' => 1]));

        $this->assertFalse($verdict['ok']);
        $this->assertSame('quiet_zone', $verdict['reason']);
    }

    public function test_an_oversized_logo_is_refused(): void
    {
        $verdict = $this->guard->verify(self::URL, QrDesign::normalize([
            'logo_file' => 'brand.png', 'logo_scale' => 40,
        ]));

        $this->assertFalse($verdict['ok']);
        $this->assertSame('logo_too_big', $verdict['reason']);
    }

    public function test_a_logo_within_the_cap_is_accepted(): void
    {
        $verdict = $this->guard->verify(self::URL, QrDesign::normalize([
            'logo_file' => 'brand.png', 'logo_scale' => QrScanGuard::MAX_LOGO_WIDTH_PCT,
        ]));

        $this->assertTrue($verdict['ok'], (string) $verdict['reason']);
        $this->assertSame(QrScanGuard::MAX_LOGO_WIDTH_PCT, $verdict['coverage']);
    }

    /**
     * Layer 2 actually ran: a pass reports zero re-sampled module mismatches, so
     * "ok" means the rendered pixels were checked, not just the colour maths.
     */
    public function test_the_round_trip_reports_a_clean_resample(): void
    {
        $verdict = $this->guard->verify(self::URL, QrPresets::settings('classic'));

        $this->assertTrue($verdict['ok']);
        $this->assertSame(0, $verdict['mismatches']);
        $this->assertGreaterThan(QrScanGuard::MIN_CONTRAST, $verdict['contrast']);
    }
}
