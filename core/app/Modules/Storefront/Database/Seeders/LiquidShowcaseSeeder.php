<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Database\Seeders;

use App\Models\StoreOption;
use App\Models\Theme;
use App\Models\ThemeVersion;
use App\Services\StoreCloneService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;

/**
 * Liquid showcase: the MARKETPLACE themes get a live demo too.
 *
 * The Blade showcase (BladeShowcaseSeeder) points every Blade listing at the
 * demo store that renders it. The Liquid listings had none: the Blade
 * seeder skips a Liquid twin's Blade sibling (a slug collision — crave,
 * starter, bazaar), and the Studio starters never had a store at all. So a
 * visitor who clicked "live demo" on a Liquid theme got the synthetic
 * preview or the fallback storefront — never the theme itself.
 *
 * This seeder gives each Liquid listing a showcase-owned store that renders
 * THAT theme: a clone of the vertical's showcase store (the same demo menu,
 * the same size × colour matrix, the same photos) pointed at the theme's
 * marketplace package. Idempotent — a listing that already has a demo is
 * left alone.
 *
 * Order: run AFTER VerticalShowcaseSeeder (the source stores) and
 * ThemeShowcaseSeeder (the food demo store):
 *
 *   php artisan db:seed --class=Juwri\\Modules\\Storefront\\Database\\Seeders\\VerticalShowcaseSeeder
 *   php artisan db:seed --class=Juwri\\Modules\\Storefront\\Database\\Seeders\\ThemeShowcaseSeeder
 *   php artisan db:seed --class=Juwri\\Modules\\Storefront\\Database\\Seeders\\LiquidShowcaseSeeder
 */
class LiquidShowcaseSeeder extends Seeder
{
    /** The showcase account — the owner of every demo store. */
    private const OWNER_EMAIL = 'showcase-demo@example.com';

    /**
     * The showcase store each vertical is demonstrated through. A Liquid
     * theme inherits its vertical's demo data — the clothing showcase carries
     * the size × colour matrix, the food showcase the photo menu.
     */
    private const SOURCES = [
        'clothing' => 'clothing-demo',
        'retail' => 'retail-demo',
        'cosmetic' => 'cosmetic-demo',
        'carparts' => 'carparts-demo',
        'laundry' => 'laundry-demo',
        'pharmacy' => 'pharmacy-demo',
        'hotel' => 'hotel-demo',
        'restaurant' => 'crave-demo',
    ];

    public function run(): void
    {
        User::updateOrCreate(
            ['email' => self::OWNER_EMAIL],
            [
                'username' => 'themeshowcase', 'name' => 'Theme Showcase Owner',
                'password' => Hash::make('showcase-demo-pass'), 'user_type' => 'user',
                // A demo-grade plan: the caps, not the label, is what decides
                // how much of a demo store's menu is published, and a demo
                // that shows 10 of 30 items is not a demo.
                'group_id' => 2, 'status' => 1, 'country_code' => 'SA', 'country' => 'Saudi Arabia',
                'email_verified_at' => now(),
            ]
        );

        $made = 0;
        foreach (Theme::query()->where('engine', 'liquid')->orderBy('slug')->get() as $theme) {
            if ($theme->demo_slug) {
                continue; // already has one
            }
            $version = ThemeVersion::query()->where('theme_id', $theme->id)
                ->where('status', 'published')->orderByDesc('id')->first();
            if (! $version) {
                continue;
            }
            $sourceSlug = self::SOURCES[$this->verticalOf($version)] ?? 'crave-demo';
            $source = Store::query()->where('slug', $sourceSlug)->first();
            if (! $source) {
                continue; // the vertical showcase has not run yet
            }

            $slug = $theme->slug.'-demo';
            if (Store::query()->where('slug', $slug)->exists()) {
                $slug = $theme->slug.'-liquid-demo';
            }

            $clone = app(StoreCloneService::class)->clone($source)['clone'];
            $clone->slug = $slug;
            $clone->save();

            StoreOption::updateStoreOption($clone->id, 'store_template', 'mp:'.$theme->slug.'@'.$version->semver);
            StoreOption::updateStoreOption($clone->id, 'demo_showcase', '1');
            $theme->update(['demo_slug' => $slug]);

            $made++;
            $this->command?->info("  {$theme->slug} → {$slug}");
        }

        $this->command?->info("Liquid showcase: {$made} demo store(s) created.");
    }

    /** The vertical a Liquid theme belongs to (from its manifest). */
    private function verticalOf(ThemeVersion $version): string
    {
        $manifest = is_array($version->manifest) ? $version->manifest : [];
        $vertical = trim((string) ($manifest['vertical'] ?? ''));
        if ($vertical === '') {
            $recipe = (string) ($manifest['recipe'] ?? '');
            $vertical = str_contains($recipe, '/') ? explode('/', $recipe)[0] : '';
        }

        return isset(self::SOURCES[$vertical]) ? $vertical : 'restaurant';
    }
}
