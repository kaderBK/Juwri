<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Database\Seeders;

use App\Models\Theme;
use Juwri\Modules\Storefront\Services\ThemeStudio\Recipes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Theme Studio B2: list the six Studio starter themes in the Theme Store.
 *
 * Each starter is an in-repo Liquid package (resources/liquid-themes/<slug>/1)
 * pinned to section library 1 and built from a recipe. This creates/updates a
 * free, platform-owned, published Theme row (engine liquid) + a published
 * ThemeVersion "1" (matching the on-disk dir, so Apply and Live preview work),
 * with English listing copy, Arabic translations, the theme's public skin CSS
 * and a palette-card screenshot. Idempotent by slug — safe to re-run. Opt-in:
 *
 *   php artisan db:seed --class=StudioStarterThemesSeeder
 */
class StudioStarterThemesSeeder extends Seeder
{
    public const SEMVER = '1';

    private const AR_NOTE = 'المعاينة المباشرة متجر تجريبي — قائمتك وصورك وهويتك ولغاتك تحلّ محلّها فور تطبيق القالب. رتّب كل قسم كما تريد في استوديو القوالب.';

    /**
     * slug => listing copy. `recipe` names the recipe the package was built
     * from (its palette drives the screenshot).
     *
     * @return array<string,array{name:string,recipe:string,tagline:string,ar:array{tagline:string,description:string}}>
     */
    public static function catalog(): array
    {
        return [
            'studio-bistro' => [
                'name' => 'Studio Bistro',
                'recipe' => 'restaurant/classic',
                'tagline' => 'A warm, photo-led restaurant storefront — stories, dietary filters and a full photo menu, every section yours to rearrange.',
                'ar' => [
                    'tagline' => 'واجهة مطعم دافئة تقودها الصور — قصص وفلاتر غذائية ومنيو مصوّر كامل، وكل قسم قابل لإعادة الترتيب.',
                    'description' => 'واجهة مطعم دافئة مبنية من مكتبة أقسام استوديو القوالب: غلاف رئيسي، قصص، فلاتر غذائية، تصنيفات ثابتة ومنيو مصوّر كامل، مع التقييمات وساعات العمل والخريطة. رتّب كل قسم في استوديو القوالب.',
                ],
            ],
            'studio-express' => [
                'name' => 'Studio Express',
                'recipe' => 'restaurant/quick-order',
                'tagline' => 'Speed-first ordering for quick-service kitchens — sticky search and tabs, a dense menu and a cart that is always one tap away.',
                'ar' => [
                    'tagline' => 'طلب سريع أولًا لمطابخ الخدمة السريعة — بحث وتبويبات ثابتة، منيو مكثّف وسلة على بعد لمسة دائمًا.',
                    'description' => 'واجهة طلب سريعة لمطابخ الخدمة السريعة: بلا غلاف، بحث وتبويبات تصنيفات ثابتة، منيو مكثّف بعمود واحد وشريط سلة على بعد لمسة دائمًا.',
                ],
            ],
            'studio-market' => [
                'name' => 'Studio Market',
                'recipe' => 'retail/grocery',
                'tagline' => 'A fast grocery and convenience shop — promo banners, category tiles and a product grid with filters and stock badges.',
                'ar' => [
                    'tagline' => 'متجر بقالة سريع — لافتات عروض، بلاطات تصنيفات وشبكة منتجات بفلاتر وشارات مخزون.',
                    'description' => 'متجر بقالة وحاجيات سريع: بحث ثابت، لافتات عروض، بلاطات تصنيفات بالصور، صفقات وشبكة منتجات كثيفة بفلاتر وترتيب وشارات مخزون.',
                ],
            ],
            'studio-boutique' => [
                'name' => 'Studio Boutique',
                'recipe' => 'clothing/default',
                'tagline' => 'An editorial, monochrome boutique — tall hero, new-in rail and a portrait grid with size and colour swatches.',
                'ar' => [
                    'tagline' => 'متجر أزياء تحريري أحادي اللون — غلاف طويل، شريط الجديد وشبكة طولية بعيّنات المقاسات والألوان.',
                    'description' => 'متجر تحريري أحادي اللون لعلامات الأزياء وأسلوب الحياة: غلاف طويل، تبويبات تصنيفات، شريط الجديد وشبكة منتجات طولية بعيّنات المقاسات والألوان.',
                ],
            ],
            'studio-services' => [
                'name' => 'Studio Services',
                'recipe' => 'laundry/default',
                'tagline' => 'A calm, bookable storefront for laundries and service businesses — price list, booking notes, offers and loyalty.',
                'ar' => [
                    'tagline' => 'واجهة هادئة قابلة للحجز للمغاسل وأعمال الخدمات — قائمة أسعار وملاحظات حجز وعروض وولاء.',
                    'description' => 'واجهة هادئة قابلة للحجز للمغاسل وأعمال الخدمات: قائمة أسعار الخدمات مع ملاحظات الحجز، العروض والولاء، التقييمات وساعات العمل.',
                ],
            ],
            'studio-stay' => [
                'name' => 'Studio Stay',
                'recipe' => 'hotel/default',
                'tagline' => 'Understated luxury for hotels and guest houses — rooms with amenities and nightly rates, reservations and a map.',
                'ar' => [
                    'tagline' => 'فخامة هادئة للفنادق وبيوت الضيافة — غرف بمرافقها وأسعار الليلة، حجوزات وخريطة.',
                    'description' => 'فخامة هادئة للفنادق وبيوت الضيافة: غلاف طويل، الغرف بمرافقها وأسعار الليلة، الحجوزات، قصة المكان والخريطة.',
                ],
            ],
        ];
    }

    public function run(): void
    {
        $made = 0;
        foreach (self::catalog() as $slug => $spec) {
            $dir = resource_path('liquid-themes/'.$slug.'/'.self::SEMVER);
            $manifest = is_file($dir.'/theme.json') ? json_decode((string) file_get_contents($dir.'/theme.json'), true) : null;
            if (! is_array($manifest) || ! is_file($dir.'/templates/index.json')) {
                $this->command->warn("Skipping {$slug} — no Liquid package on disk");

                continue;
            }
            $description = trim((string) ($manifest['description'] ?? ''));

            $theme = Theme::updateOrCreate(['slug' => $slug], [
                'name' => $spec['name'],
                'engine' => 'liquid',
                'status' => 'published',
                'category' => (string) ($manifest['category'] ?? 'general'),
                'tagline' => $spec['tagline'],
                'demo_notes' => 'The live preview renders a demo store — your menu, photos, branding and languages replace it the moment you apply the theme. Rearrange every section in Theme Studio.',
                'translations' => ['ar' => $spec['ar'] + ['notes' => self::AR_NOTE]],
                'price' => 0,
                'seller_id' => null, // platform-owned
            ]);

            $theme->versions()->updateOrCreate(['semver' => self::SEMVER], [
                'manifest' => [
                    'name' => $spec['name'],
                    'version' => self::SEMVER,
                    'description' => $description,
                    'library' => (string) ($manifest['library'] ?? '1'),
                    'recipe' => $spec['recipe'],
                ],
                'checksum' => hash('sha256', $slug.'@'.self::SEMVER),
                'status' => 'published',
                'review_notes' => 'First-party Theme Studio starter theme',
            ]);

            // Public assets, if a starter ever ships any (theme_asset reads
            // assets/marketplace-themes/<slug>/<ver>). The palette itself is
            // theme.json "tokens", applied by the shell.
            if (is_dir($dir.'/assets')) {
                File::copyDirectory($dir.'/assets', public_path('assets/marketplace-themes/'.$slug.'/'.self::SEMVER));
            }

            $rel = "storage/marketplace/theme/{$theme->id}/";
            foreach ($theme->media()->get() as $m) {
                @unlink(public_path($m->disk_path));
                $m->delete();
            }
            File::ensureDirectoryExists(public_path($rel));
            file_put_contents(public_path($rel.'shot-1.svg'), $this->paletteCard($spec['name'], Recipes::tokens($spec['recipe']) ?? []));
            $theme->media()->create(['disk_path' => $rel.'shot-1.svg', 'kind' => 'screenshot', 'position' => 1]);

            $made++;
            $this->command->info("Seeded Studio starter {$slug} (theme {$theme->id}, v".self::SEMVER.')');
        }

        $this->command->info("Seeded {$made} Studio starter themes.");
    }

    /**
     * A 1600x1000 phone-on-palette card drawn in the recipe's own colours —
     * a placeholder until the theme_showcase_shot capture runs.
     *
     * @param  array<string,string>  $tokens
     */
    private function paletteCard(string $name, array $tokens): string
    {
        $c = fn (string $role, string $fallback) => preg_match('/^#[0-9a-f]{6}$/i', $tokens['colors.'.$role] ?? '') ? $tokens['colors.'.$role] : $fallback;
        $bg = $c('background', '#ffffff');
        $surface = $c('surface', '#ffffff');
        $text = $c('text', '#141414');
        $primary = $c('primary', '#3fd463');
        $accent = $c('accent', '#f5a623');
        $e = fn (string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_XML1);

        $cards = '';
        for ($i = 0; $i < 4; $i++) {
            $y = 420 + $i * 130;
            $cards .= '<rect x="640" y="'.$y.'" width="320" height="112" rx="16" fill="'.$surface.'"/>'
                .'<rect x="656" y="'.($y + 16).'" width="80" height="80" rx="12" fill="'.$primary.'" opacity="'.(0.35 + $i * 0.12).'"/>'
                .'<rect x="752" y="'.($y + 26).'" width="'.(150 - $i * 14).'" height="12" rx="6" fill="'.$text.'" opacity=".8"/>'
                .'<rect x="752" y="'.($y + 50).'" width="110" height="9" rx="4" fill="'.$text.'" opacity=".35"/>'
                .'<circle cx="930" cy="'.($y + 80).'" r="14" fill="'.$primary.'"/>';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="1000" viewBox="0 0 1600 1000">'
            .'<rect width="1600" height="1000" fill="'.$primary.'"/>'
            .'<circle cx="1400" cy="160" r="260" fill="'.$accent.'" opacity=".35"/>'
            .'<text x="120" y="200" font-family="-apple-system,Segoe UI,Arial,sans-serif" font-size="64" font-weight="800" fill="'.$bg.'">'.$e($name).'</text>'
            .'<rect x="600" y="120" width="400" height="820" rx="48" fill="#111111"/>'
            .'<rect x="616" y="136" width="368" height="788" rx="36" fill="'.$bg.'"/>'
            .'<rect x="640" y="170" width="320" height="220" rx="18" fill="'.$primary.'" opacity=".85"/>'
            .'<rect x="664" y="300" width="180" height="16" rx="8" fill="'.$bg.'"/>'
            .'<rect x="664" y="332" width="110" height="36" rx="18" fill="'.$accent.'"/>'
            .$cards
            .'</svg>';
    }
}
