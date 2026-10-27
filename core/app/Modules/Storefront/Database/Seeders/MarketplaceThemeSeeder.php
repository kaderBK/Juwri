<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Database\Seeders;

use App\Models\Theme;
use App\Models\ThemeVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Intervention\Image\Facades\Image;

/**
 * stores P1-4: platform-owned demo themes for the Theme Store, so browse/detail
 * (P1-5/P1-6) render against real content. Each theme gets a published 1.0.0
 * version and one generated placeholder screenshot. Idempotent by slug — safe to
 * re-run. NOT wired into DatabaseSeeder (demo data is opt-in):
 *   php artisan db:seed --class=MarketplaceThemeSeeder
 */
class MarketplaceThemeSeeder extends Seeder
{
    public function run(): void
    {
        $defs = [
            ['slug' => 'linen-classic', 'name' => 'Linen Classic', 'category' => 'cafe',        'price' => 0,     'color' => '#e7e0d3', 'accent' => '#b98a5a', 'tagline' => 'Warm, editorial layout for cafés and coffee bars.'],
            ['slug' => 'noir-fine',     'name' => 'Noir',          'category' => 'fine_dining',  'price' => 49,    'color' => '#1c1c22', 'accent' => '#c9a24b', 'tagline' => 'Dark, elegant menu for fine-dining rooms.'],
            ['slug' => 'rush-fastfood', 'name' => 'Rush',          'category' => 'fast_food',    'price' => 19,    'color' => '#ff5a3c', 'accent' => '#ffd23c', 'tagline' => 'Bold, fast-tap ordering for quick-service.'],
            ['slug' => 'crumb-bakery',  'name' => 'Crumb',         'category' => 'bakery',       'price' => 15,    'color' => '#f3d9c0', 'accent' => '#8a5a3c', 'tagline' => 'Soft, pastel storefront for bakeries.'],
            ['slug' => 'slice-pizza',   'name' => 'Slice',         'category' => 'pizza',        'price' => 19,    'color' => '#b1442e', 'accent' => '#f2e0c0', 'tagline' => 'Classic pizzeria menu with build-your-own.'],
            ['slug' => 'hub-multi',     'name' => 'Hub',           'category' => 'multi_branch', 'price' => 79,    'color' => '#2b4a6f', 'accent' => '#6fb3d9', 'tagline' => 'Multi-branch storefront with location switcher.'],
        ];

        foreach ($defs as $d) {
            $theme = Theme::updateOrCreate(
                ['slug' => $d['slug']],
                [
                    'name'        => $d['name'],
                    'seller_id'   => null,               // platform-owned
                    'status'      => 'published',
                    'price'       => $d['price'],
                    'category'    => $d['category'],
                    'tagline'     => $d['tagline'],
                    'support_url' => 'https://help.juwri.test/themes/' . $d['slug'],
                    'demo_notes'  => 'Demo data — sample menu preview.',
                ]
            );

            // Published versions are immutable (model guard) — only create if absent.
            if (! $theme->versions()->where('semver', '1.0.0')->exists()) {
                ThemeVersion::create([
                    'theme_id' => $theme->id,
                    'semver'   => '1.0.0',
                    'manifest' => ['name' => $d['name'], 'version' => '1.0.0', 'description' => $d['tagline'], 'templates' => ['index', 'product']],
                    'checksum' => hash('sha256', $d['slug'] . '1.0.0'),
                    'status'   => 'published',
                ]);
            }

            // A minimal but real theme package so the demo preview renders
            // (resolver reads storage/app/marketplace-themes/<slug>/<ver>/templates).
            $tplDir = storage_path("app/marketplace-themes/{$d['slug']}/1.0.0/templates");
            File::ensureDirectoryExists($tplDir);
            File::put($tplDir . '/index.liquid', <<<LIQUID
                <section class="mp-demo" style="max-width:720px;margin:40px auto;font-family:Georgia,serif;">
                  <h1>{{ restaurant.title | escape }}</h1>
                  <p style="color:#666;">{$d['tagline']}</p>
                  <hr>
                  <p>Sample menu preview for the {$d['name']} theme.</p>
                </section>
                LIQUID);

            if ($theme->screenshots()->exists()) {
                continue; // already has a screenshot — don't duplicate on re-run
            }

            $dir  = public_path("storage/marketplace/theme/{$theme->id}/");
            File::ensureDirectoryExists($dir);
            $file = "seed-{$theme->id}.webp";

            $img = Image::canvas(1280, 800, $d['color']);
            $img->rectangle(0, 0, 1280, 120, fn ($draw) => $draw->background($d['accent']));       // header bar
            $img->rectangle(80, 220, 600, 720, fn ($draw) => $draw->background($d['accent']));      // menu block
            $img->encode('webp', 85)->save($dir . $file);

            $theme->media()->create([
                'disk_path' => "storage/marketplace/theme/{$theme->id}/{$file}",
                'position'  => 0,
                'kind'      => 'screenshot',
            ]);
        }

        $this->command?->info('Seeded ' . count($defs) . ' platform themes with screenshots.');
    }
}
