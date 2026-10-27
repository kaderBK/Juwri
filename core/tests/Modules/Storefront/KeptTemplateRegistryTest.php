<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Database\Seeders\ThemeShowcaseSeeder;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Tests\TestCase;

/**
 * WI-M13: the seventeen-template keep-list had one canonical home
 * (Juwri\Modules\Storefront\Services\Theme\KeptTemplates) — before, the same literal lived in 20+
 * files under many names/shapes, so adding an 18th template could ship four
 * shared storefront sections missing under a fully green suite. These tests fail
 * when any file grows a private copy again.
 */
class KeptTemplateRegistryTest extends TestCase
{
    use RefreshDatabase;

    /** Files that legitimately hold the full literal (the canonical source + its mirrors). */
    private const ALLOWED = [
        'app/Modules/Storefront/Services/Theme/KeptTemplates.php',
        'tests/e2e/lib/retained.js',
        'app/Modules/Storefront/Database/Seeders/ThemeShowcaseSeeder.php',
    ];

    public function test_no_file_carries_its_own_copy_of_the_keep_list(): void
    {
        $slugs = KeptTemplates::MODERN;
        $offenders = [];

        foreach (['app', 'database', 'resources', 'routes', 'tests'] as $top) {
            $dir = base_path($top);
            if (! is_dir($dir)) {
                continue;
            }
            foreach (File::allFiles($dir) as $file) {
                if (! in_array($file->getExtension(), ['php', 'js'], true)) {
                    continue;
                }
                $rel = ltrim(str_replace(base_path(), '', $file->getPathname()), '/');
                if (in_array($rel, self::ALLOWED, true)) {
                    continue;
                }
                $lines = preg_split('/\R/', (string) file_get_contents($file->getPathname()));
                $n = count($lines);
                for ($i = 0; $i < $n; $i++) {
                    $window = implode("\n", array_slice($lines, $i, 5));
                    $named = 0;
                    foreach ($slugs as $s) {
                        // Count only LIST-context occurrences ('slug', or 'slug']) —
                        // a keyed map ('slug' => data) is per-theme DATA, not a copy of
                        // the keep-list, and must not be flagged.
                        if (preg_match('/["\']'.$s.'["\']\s*[,\]]/', $window)) {
                            $named++;
                        }
                    }
                    if ($named >= 15) {
                        $offenders[] = $rel.' (line '.($i + 1).')';
                        break;
                    }
                }
            }
        }

        $this->assertSame([], $offenders,
            "These files carry their own copy of the 17-template keep-list; use Juwri\\Modules\\Storefront\\Services\\Theme\\KeptTemplates::MODERN:\n"
            .implode("\n", $offenders));
    }

    public function test_the_javascript_mirror_matches_the_php_source(): void
    {
        $js = (string) file_get_contents(base_path('tests/e2e/lib/retained.js'));
        preg_match_all("/'([a-z]+)'/", $js, $m);

        $this->assertSame(KeptTemplates::MODERN, $m[1], 'retained.js must mirror KeptTemplates::MODERN exactly, same order.');
    }

    public function test_every_retained_template_has_a_view_directory(): void
    {
        foreach (KeptTemplates::MODERN as $slug) {
            $this->assertFileExists(resource_path("views/store_templates/{$slug}/index.blade.php"),
                "Retained template {$slug} has no index.blade.php — a slug in the constant whose template was deleted.");
        }
    }

    public function test_the_showcase_seeder_pro_set_is_a_subset_of_the_keep_list(): void
    {
        $this->assertEmpty(array_diff(ThemeShowcaseSeeder::PRO, KeptTemplates::MODERN));
    }

    public function test_the_room_service_provider_covers_every_retained_template(): void
    {
        $this->assertSame(KeptTemplates::MODERN, array_keys(StorefrontRoomServiceOptionTest::themes()));
    }

    public function test_the_menu_templates_admin_page_badges_the_retained_seventeen(): void
    {
        app()->setLocale('en');
        // The admin layout reads a few admin-only settings keys the default 7 don't cover.
        $this->shareLayoutGlobals([
            'site_admin_logo' => 'logo.png', 'site_logo_footer' => 'f.png', 'site_logo' => 'l.png',
            'currency_sign' => '$', 'currency_pos' => 1,
        ], true);
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);

        $admin = User::factory()->create(['group_id' => '1', 'status' => 1, 'email_verified_at' => now(), 'user_type' => 'admin']);

        $res = $this->actingAs($admin)->get(route('admin.menu-templates.index'))->assertOk();

        // Exactly the retained 17 carry the Modern badge. Match the badge's
        // closing </span> specifically — the grid toolbar also has a "Modern"
        // filter <option>, whose text would otherwise inflate a bare '>Modern<'.
        $this->assertSame(17, substr_count($res->getContent(), '>'.___('Modern').'</span>'));
    }
}
