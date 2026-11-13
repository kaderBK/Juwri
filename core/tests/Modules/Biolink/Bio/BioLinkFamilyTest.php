<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink\Bio;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Lane C · family layouts. Each family renders as a full standalone page through a
 * harness route (Lane A's controller does not exist yet). Grows one const entry per
 * family task (C2 list/menu_card/store_rows; C3–C5 add the rest).
 */
class BioLinkFamilyTest extends TestCase
{
    use RefreshDatabase;

    /** Families proven to render. Extend as each C-task lands its family. */
    private const BUILT_FAMILIES = ['list', 'menu_card', 'store_rows', 'bento', 'photo_hero', 'social_grid',
        'chat', 'story_card', 'broadsheet', 'countdown', 'swiss', 'brutalist', 'type_lite'];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    private function contract(string $family, array $overrides = []): array
    {
        $store = (object) [
            'id' => 4321, 'slug' => 'demo-diner', 'title' => 'Demo Diner',
            'bio_title' => 'Demo Diner', 'bio_description' => 'Late-night smash burgers',
            'bio_main_image' => null, 'never_show_title' => 0,
        ];
        $tokens = ['--bg' => '#101014', '--card' => '#1b1f2a', '--ink' => '#f4f4f6',
            '--muted' => '#8f95a8', '--accent' => '#e2603a', '--accent-ink' => '#ffffff', '--radius' => '16px'];

        return array_merge([
            'store' => $store,
            'branch' => (object) ['address' => '12 Olaya St', 'whatsapp' => '+966500000000', 'hours' => null, 'latitude' => 24.7, 'longitude' => 46.6],
            'preset' => [
                'slug' => 'test_'.$family, 'name' => ucfirst($family), 'family' => $family, 'tier' => 'pro',
                'rtl_native' => false, 'fonts' => [['family' => 'Poppins', 'weights' => [400, 700]]], 'tokens' => $tokens,
            ],
            'tokens' => $tokens,
            'bioSettings' => ['title' => 'Demo Diner', 'subtitle' => '3 branches', 'status_label' => 'OPEN NOW'],
            'poweredBy' => 'Powered by MyMenu',
            'rtl' => false,
            'source' => 'social',
            'blocks' => [
                ['id' => 1, 'type' => 'menu', 'label' => 'Menu', 'url' => 'https://x.test/m', 'config' => ['sub' => 'Burgers']],
                ['id' => 2, 'type' => 'menu_item', 'label' => 'Truffle Double', 'config' => ['price_label' => '38 SAR', 'sub' => 'Black bun']],
                ['id' => 3, 'type' => 'reservation', 'label' => 'Reserve', 'url' => 'https://x.test/r', 'config' => ['note' => 'Tonight']],
                ['id' => 4, 'type' => 'hours', 'config' => ['rows' => [['label' => 'Thu', 'value' => '12:00 – 00:30', 'now' => true]]]],
                ['id' => 5, 'type' => 'socials', 'config' => ['links' => [['platform' => 'instagram', 'url' => 'https://i.test']]]],
            ],
        ], $overrides);
    }

    private function renderFamily(string $family, array $overrides = []): string
    {
        $contract = $this->contract($family, $overrides);
        $path = '/__biofam/'.$family;
        Route::get($path, fn () => view('bio.layouts.'.$family, $contract))->middleware('web');

        return $this->get($path)->assertOk()->getContent();
    }

    public function test_all_built_families_render_cleanly(): void
    {
        foreach (self::BUILT_FAMILIES as $family) {
            $html = $this->renderFamily($family);

            $this->assertStringContainsString('fam-'.$family, $html, "$family: family class present");
            $this->assertStringContainsString('bio--test_'.$family, $html, "$family: preset marker present");
            $this->assertStringContainsString('--accent:#e2603a', $html, "$family: tokens emitted");
            $this->assertSame(1, substr_count($html, '<style'), "$family: exactly one style tag");
            $this->assertSame(1, substr_count($html, '<script'), "$family: exactly one script tag");
            $this->assertSame(0, preg_match_all('/<style(?![^>]*\bnonce\b)[ >]/i', $html), "$family: no un-nonced style");
            $this->assertSame(0, preg_match_all('/<script(?![^>]*\bsrc=)(?![^>]*\bnonce\b)[ >]/i', $html), "$family: no un-nonced script");
            $this->assertStringContainsString('bio-link', $html, "$family: blocks rendered");
            $this->assertStringNotContainsString('<iframe', $html, "$family: no iframe");
        }
    }

    public function test_countdown_family_renders_static_digits(): void
    {
        $html = $this->renderFamily('countdown', [
            'bioSettings' => [
                'title' => 'Qamar Rooftop',
                'countdown' => [
                    'label' => 'Next seating drops in',
                    'title' => 'Harvest Moon Dinner',
                    'target' => now()->addDays(4)->addHours(11)->toIso8601String(),
                    'cta_label' => 'Join the Waitlist',
                    'cta_url' => 'https://x.test/wl',
                ],
            ],
        ]);

        $this->assertStringContainsString('bio-countdown__digits', $html);
        $this->assertStringContainsString('Harvest Moon Dinner', $html);
        $this->assertStringContainsString('Join the Waitlist', $html);
        $this->assertMatchesRegularExpression('/<b>0[34]<\/b>/', $html); // ~4 days remaining
    }

    public function test_families_flip_to_rtl(): void
    {
        foreach (self::BUILT_FAMILIES as $family) {
            $html = $this->renderFamily($family, ['rtl' => true]);
            $this->assertStringContainsString('dir="rtl"', $html, "$family: RTL applied");
        }
    }

    /**
     * Regression: the global `view()->composer('*')` shares a stdClass `settings`
     * to every view, which overrides any controller-passed `settings`. A bio view
     * that array-accesses it (`$settings['dateline']`) fatals with "Cannot use
     * object of type stdClass as array" on a populated options table (masked by the
     * empty test DB). Bio views must read `$bioSettings`; no bio view may use the
     * `$settings[...]` array-access form.
     */
    public function test_bio_views_never_array_access_the_global_settings(): void
    {
        $dir = resource_path('views/bio');
        $files = array_merge(
            glob($dir.'/*.blade.php') ?: [],
            glob($dir.'/*/*.blade.php') ?: [],
            glob($dir.'/*/*/*.blade.php') ?: [],
        );
        $this->assertNotEmpty($files, 'bio views found');

        foreach ($files as $f) {
            $this->assertStringNotContainsString(
                '$settings[',
                (string) file_get_contents($f),
                str_replace(resource_path(), '', $f).': bio views must read $bioSettings, never array-access the global $settings',
            );
        }
    }
}
