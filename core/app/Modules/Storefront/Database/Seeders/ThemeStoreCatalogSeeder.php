<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Database\Seeders;

use App\Models\Theme;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Juwri\Core\Database\Seeders\Concerns\RasterizesSvg;

/**
 * Theme Store listing content for the two REAL installable Liquid themes that
 * ship in-repo (resources/liquid-themes/{crave,starter}/1). Creates/updates the
 * Theme row + a published ThemeVersion whose semver matches the on-disk dir
 * ("1") so Apply and Live-preview actually work, writes a benefit-led
 * description into the version manifest, and generates storefront-mock SVG
 * screenshots under storage/marketplace/theme/{id}/. Idempotent by slug.
 * Never lists themes without files on disk — applying one would fall back to
 * the default Blade theme and blank the storefront's look.
 */
class ThemeStoreCatalogSeeder extends Seeder
{
    use RasterizesSvg;

    public function run(): void
    {
        foreach ($this->catalog() as $slug => $spec) {
            $dir = resource_path('liquid-themes/'.$slug.'/'.$spec['semver']);
            if (! is_file($dir.'/templates/index.liquid') && ! is_file($dir.'/templates/index.json')) {
                $this->command?->warn("Skipping {$slug} — no Liquid package on disk");

                continue;
            }

            /* The catalogue publishes every theme it knows — but it must not
               resurrect one the owner DELISTED in the admin (the listing
               editor's Visibility control). A delisted theme keeps its status;
               the catalogue only fills in the copy, the price and the
               category. */
            $existing = Theme::query()->where('slug', $slug)->first();
            $theme = Theme::updateOrCreate(['slug' => $slug], [
                'name' => $spec['name'],
                'status' => ($existing && $existing->status === 'delisted') ? 'delisted' : 'published',
                'category' => $spec['category'],
                'tagline' => $spec['tagline'],
                'demo_notes' => $spec['demo_notes'],
                'price' => $spec['price'],
                'seller_id' => null, // platform-owned
            ]);

            /* A PUBLISHED version is immutable (ThemeVersion's own guard), so
               the catalogue must not rewrite one — a re-seed that changed a
               theme's copy would throw. Only a version that is not published
               yet is refreshed. */
            $version = $theme->versions()->firstWhere('semver', $spec['semver']);
            if (! $version || $version->status !== 'published') {
                $version = $theme->versions()->updateOrCreate(['semver' => $spec['semver']], [
                    'manifest' => [
                        'name' => $spec['name'],
                        'version' => $spec['semver'],
                        'description' => trim($spec['description']),
                    ],
                    'checksum' => hash('sha256', $slug.'@'.$spec['semver']),
                    'status' => 'published',
                    'review_notes' => 'First-party reference theme',
                ]);
            }

            // Publish the theme's static assets (css/js/fonts/images) to the
            // public dir the `theme_asset` filter reads (MarketplaceTheme::assetMap).
            // Without this the DB listing renders, but the storefront comes up
            // UNSTYLED because every `theme_asset` URL 404s. Mirrors the public-asset
            // step ThemePackageInstaller does for uploaded packages — reference themes
            // that ship in-repo need it published from resources/ here.
            $assetsSrc = $dir.'/assets';
            if (is_dir($assetsSrc)) {
                $assetsDst = public_path('assets/marketplace-themes/'.$slug.'/'.$spec['semver']);
                File::copyDirectory($assetsSrc, $assetsDst);
            }

            $rel = "storage/marketplace/theme/{$theme->id}/";
            File::ensureDirectoryExists(public_path($rel));
            foreach ($theme->media()->get() as $m) {
                @unlink(public_path($m->disk_path));
                $m->delete();
            }
            foreach ($spec['screens'] as $i => $screen) {
                $file = 'shot-'.($i + 1).'.svg';
                file_put_contents(public_path($rel.$file), $this->{$screen[0]}($spec, $screen[1] ?? []));
                $theme->media()->create(['disk_path' => $rel.$file, 'kind' => 'screenshot', 'position' => $i + 1]);
            }

            // Social-share raster of the hero (crawlers can't render SVG).
            $this->rasterizeToPng(public_path($rel.'shot-1.svg'), public_path($rel.'og.png'));

            $this->command?->info("Seeded theme listing {$slug} (theme {$theme->id}, v{$version->semver})");
        }
    }

    /* ---------------------------------------------------------------- svg -- */

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1);
    }

    private function txt(float $x, float $y, string $s, int $size = 16, string $fill = '#2c2a26', int $weight = 400, string $anchor = 'start'): string
    {
        return '<text x="'.$x.'" y="'.$y.'" font-family="-apple-system,\'Segoe UI\',Arial,sans-serif" font-size="'.$size
            .'" font-weight="'.$weight.'" fill="'.$fill.'" text-anchor="'.$anchor.'">'.$this->e($s).'</text>';
    }

    private function bar(float $x, float $y, float $w, float $h, string $fill, float $rx = 6, float $opacity = 1): string
    {
        return '<rect x="'.$x.'" y="'.$y.'" width="'.$w.'" height="'.$h.'" rx="'.$rx.'" fill="'.$fill.'"'
            .($opacity < 1 ? ' opacity="'.$opacity.'"' : '').'/>';
    }

    private function shade(string $hex, float $f): string
    {
        $c = sscanf($hex, '#%02x%02x%02x');

        return sprintf('#%02x%02x%02x', (int) ($c[0] * (1 - $f)), (int) ($c[1] * (1 - $f)), (int) ($c[2] * (1 - $f)));
    }

    /** Palette per theme mode: [pageBg, cardBg, textStrong, textSoft, line]. */
    private function pal(array $spec): array
    {
        return $spec['mode'] === 'dark'
            ? ['#161311', '#221e1a', '#f2ece3', '#8f867b', '#332d26']
            : ['#faf8f4', '#ffffff', '#26231f', '#9a9187', '#eae6df'];
    }

    /**
     * Storefront page mock (1420x860 at origin): store topbar, hero band,
     * 2x3 menu-item card grid. The visual identity of a THEME listing.
     */
    private function storefrontGroup(array $spec, array $o = []): string
    {
        [$bg, $card, $strong, $soft, $line] = $this->pal($spec);
        $a = $spec['accent'];
        $s = '<g>';
        $s .= '<rect width="1420" height="860" rx="18" fill="'.$bg.'" stroke="'.$line.'"/>';
        // store topbar
        $s .= '<circle cx="56" cy="42" r="17" fill="'.$a.'"/>';
        $s .= $this->txt(86, 49, $spec['store'], 19, $strong, 800);
        foreach ([0, 1, 2] as $i) {
            $s .= $this->bar(720 + $i * 110, 34, 74, 14, $soft, 7, .55);
        }
        $s .= $this->bar(1268, 24, 116, 38, $a, 19);
        $s .= $this->txt(1326, 48, $o['cart'] ?? 'Cart · 2', 14.5, '#ffffff', 700, 'middle');
        // hero band
        $s .= '<defs><linearGradient id="sfh" x1="0" y1="0" x2="1" y2="1">'
            .'<stop offset="0" stop-color="'.$a.'"/><stop offset="1" stop-color="'.$this->shade($a, .45).'"/></linearGradient></defs>';
        $s .= $this->bar(36, 86, 1348, 210, 'url(#sfh)', 18);
        $s .= '<circle cx="1240" cy="120" r="150" fill="#ffffff" opacity=".08"/>';
        $s .= $this->txt(76, 165, $o['heroA'] ?? 'Fresh from the kitchen', 34, '#ffffff', 800);
        $s .= $this->bar(76, 190, 340, 12, 'rgba(255,255,255,.7)', 6);
        $s .= $this->bar(76, 226, 150, 42, '#ffffff', 21);
        $s .= $this->txt(151, 253, $o['heroCta'] ?? 'Order now', 15, $this->shade($a, .25), 800, 'middle');
        // menu grid 3x2
        for ($r = 0; $r < 2; $r++) {
            for ($c = 0; $c < 3; $c++) {
                $x = 36 + $c * 462;
                $y = 330 + $r * 252;
                $s .= $this->bar($x, $y, 438, 228, $card, 16);
                $s .= '<rect x="'.$x.'" y="'.$y.'" width="438" height="228" rx="16" fill="none" stroke="'.$line.'"/>';
                $i = $r * 3 + $c;
                $s .= $this->bar($x + 16, $y + 16, 406, 120, $a, 12, [.5, .28, .38, .32, .45, .25][$i]);
                $s .= '<circle cx="'.($x + 80 + $i * 40).'" cy="'.($y + 76).'" r="34" fill="#ffffff" opacity=".25"/>';
                $s .= $this->bar($x + 16, $y + 152, 200 - $i * 12, 14, $strong === '#f2ece3' ? '#4a4238' : '#d9d4cb', 7);
                $s .= $this->bar($x + 16, $y + 178, 130, 10, $soft, 5, .5);
                $s .= $this->txt($x + 422, $y + 176, ['24', '32', '18', '27', '21', '35'][$i], 19, $a, 800, 'end');
                $s .= '<circle cx="'.($x + 398).'" cy="'.($y + 206).'" r="15" fill="'.$a.'"/>';
                $s .= '<path d="M'.($x + 391).' '.($y + 206).' h14 M'.($x + 398).' '.($y + 199).' v14" stroke="#fff" stroke-width="2.6" stroke-linecap="round"/>';
            }
        }
        $s .= '</g>';

        return $s;
    }

    /** Phone mock (content 360x740 at origin): storefront mobile view. */
    private function phoneGroup(array $spec, array $o = []): string
    {
        [$bg, $card, $strong, $soft, $line] = $this->pal($spec);
        $a = $spec['accent'];
        $s = '<g>';
        $s .= '<rect x="-14" y="-14" width="388" height="768" rx="46" fill="#17140f"/>';
        $s .= '<rect width="360" height="740" rx="34" fill="'.$bg.'"/>';
        $s .= $this->bar(130, 12, 100, 20, '#17140f', 10);
        $s .= '<circle cx="36" cy="70" r="13" fill="'.$a.'"/>';
        $s .= $this->txt(58, 76, $spec['store'], 15.5, $strong, 800);
        $s .= $this->bar(288, 56, 50, 28, $a, 14);
        $s .= $this->txt(313, 75, '2', 14, '#ffffff', 800, 'middle');
        // category chips
        foreach ([0, 1, 2, 3] as $i) {
            $s .= $this->bar(22 + $i * 84, 102, 72, 30, $i === 0 ? $a : $card, 15);
            if ($i !== 0) {
                $s .= '<rect x="'.(22 + $i * 84).'" y="102" width="72" height="30" rx="15" fill="none" stroke="'.$line.'"/>';
            }
            $s .= $this->bar(38 + $i * 84, 113, 40, 8, $i === 0 ? 'rgba(255,255,255,.85)' : $soft, 4);
        }
        // item rows
        for ($i = 0; $i < 4; $i++) {
            $y = 152 + $i * 118;
            $s .= $this->bar(20, $y, 320, 104, $card, 16);
            $s .= '<rect x="20" y="'.$y.'" width="320" height="104" rx="16" fill="none" stroke="'.$line.'"/>';
            $s .= $this->bar(34, $y + 14, 76, 76, $a, 12, .32 + $i * .1);
            $s .= $this->bar(124, $y + 22, 140 - $i * 14, 12, $strong === '#f2ece3' ? '#4a4238' : '#d9d4cb', 6);
            $s .= $this->bar(124, $y + 44, 100, 8, $soft, 4, .55);
            $s .= $this->txt(124, $y + 82, ['24', '18', '32', '21'][$i], 16, $a, 800);
            $s .= '<circle cx="316" cy="'.($y + 74).'" r="15" fill="'.$a.'"/>';
            $s .= '<path d="M309 '.($y + 74).' h14 M316 '.($y + 67).' v14" stroke="#fff" stroke-width="2.6" stroke-linecap="round"/>';
        }
        // sticky cart bar
        $s .= $this->bar(20, 664, 320, 54, $a, 27);
        $s .= $this->txt(180, 698, $o['cartBar'] ?? 'View cart · 46.00', 16, '#ffffff', 800, 'middle');
        $s .= '</g>';

        return $s;
    }

    /** Screenshot 1: marketing hero — headline + desktop & phone duo. */
    private function shotHero(array $spec, array $o = []): string
    {
        $a = $spec['accent'];

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1600 1000">'
            .'<defs><linearGradient id="hg" x1="0" y1="0" x2="1" y2="1">'
            .'<stop offset="0" stop-color="'.$this->shade($a, .2).'"/><stop offset="1" stop-color="'.$this->shade($a, .6).'"/>'
            .'</linearGradient></defs>'
            .'<rect width="1600" height="1000" fill="url(#hg)"/>'
            .'<circle cx="1460" cy="80" r="260" fill="#ffffff" opacity=".06"/>'
            .'<circle cx="100" cy="950" r="200" fill="#ffffff" opacity=".05"/>'
            .$this->txt(100, 190, $spec['name'].' — '.($o['kicker'] ?? 'storefront theme'), 26, 'rgba(255,255,255,.85)', 700)
            .$this->wrapText(100, 268, $o['headline'], 56, '#ffffff', 800, 30)
            .$this->wrapText(100, 400, $o['sub'], 22, 'rgba(255,255,255,.85)', 400, 60)
            .'<g transform="translate(560,480) scale(.72)">'.$this->storefrontGroup($spec, $o).'</g>'
            .'<g transform="translate(180,470) scale(.62)">'.$this->phoneGroup($spec, $o).'</g>'
            .'</svg>';
    }

    /** Screenshot: desktop storefront full-bleed. */
    private function shotDesktop(array $spec, array $o = []): string
    {
        $a = $spec['accent'];

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1600 1000">'
            .'<defs><linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">'
            .'<stop offset="0" stop-color="'.$a.'" stop-opacity=".16"/><stop offset="1" stop-color="'.$a.'" stop-opacity=".05"/>'
            .'</linearGradient></defs>'
            .'<rect width="1600" height="1000" fill="#f4f1ec"/><rect width="1600" height="1000" fill="url(#bg)"/>'
            .'<g transform="translate(90,70)">'.$this->storefrontGroup($spec, $o).'</g>'
            .'</svg>';
    }

    /** Screenshot: mobile view centered with feature callouts. */
    private function shotMobile(array $spec, array $o = []): string
    {
        $a = $spec['accent'];
        $s = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1600 1000">'
            .'<defs><linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">'
            .'<stop offset="0" stop-color="'.$a.'" stop-opacity=".18"/><stop offset="1" stop-color="'.$a.'" stop-opacity=".06"/>'
            .'</linearGradient></defs>'
            .'<rect width="1600" height="1000" fill="#f4f1ec"/><rect width="1600" height="1000" fill="url(#bg)"/>'
            .'<g transform="translate(620,110)">'.$this->phoneGroup($spec, $o).'</g>';
        foreach ([[210, 260, 'l'], [1150, 340, 'r'], [210, 560, 'l'], [1150, 660, 'r']] as $i => [$x, $y]) {
            $s .= $this->bar($x, $y, 240, 90, '#ffffff', 14, .92);
            $s .= '<rect x="'.$x.'" y="'.$y.'" width="240" height="90" rx="14" fill="none" stroke="#e5e0d8"/>';
            $s .= '<circle cx="'.($x + 30).'" cy="'.($y + 30).'" r="12" fill="'.$a.'" opacity=".8"/>';
            $s .= $this->txt($x + 30, $y + 35, '✓', 13, '#ffffff', 800, 'middle');
            $s .= $this->bar($x + 54, $y + 22, 150 - $i * 12, 12, '#c9c2b6', 6);
            $s .= $this->bar($x + 54, $y + 46, 110, 9, '#ddd7cc', 4);
        }

        return $s.'</svg>';
    }

    private function wrapText(float $x, float $y, string $text, int $size, string $fill, int $weight, int $chars): string
    {
        $lines = explode("\n", wordwrap($text, $chars, "\n", true));
        $s = '<text x="'.$x.'" y="'.$y.'" font-family="-apple-system,\'Segoe UI\',Arial,sans-serif" font-size="'.$size
            .'" font-weight="'.$weight.'" fill="'.$fill.'">';
        foreach ($lines as $i => $line) {
            $s .= '<tspan x="'.$x.'" dy="'.($i === 0 ? 0 : $size * 1.25).'">'.$this->e($line).'</tspan>';
        }

        return $s.'</text>';
    }

    /* ------------------------------------------------------------ catalog -- */

    private function catalog(): array
    {
        return [
            'crave' => [
                'name' => 'Crave',
                'semver' => '1',
                'category' => 'fast_food',
                'price' => 0,
                'mode' => 'dark',
                'accent' => '#ff6b35',
                'store' => 'CRAVE KITCHEN',
                'tagline' => 'Bold, image-led storefront built for appetite — big food photography, sticky order bar, zero clutter.',
                'demo_notes' => 'The live preview renders a demo dataset — your menu, photos, branding and languages replace it the moment you apply the theme.',
                'description' => <<<'TXT'
Make them hungry before they scroll. Crave is a bold, image-led storefront theme built for fast food, burger joints and street kitchens: full-bleed food photography, high-contrast type and a sticky order bar that keeps checkout one thumb away.

Every screen is tuned for speed on a phone — the menu loads fast, items add to cart in one tap, and the cart follows the guest instead of hiding in a corner.

• Image-first menu — big photography sells the dish before the words do
• Sticky order bar — cart and checkout stay one tap away while browsing
• High-contrast dark look — reads clearly in bright daylight and at night
• One-tap add — modifiers and combos open in a bottom sheet, not a new page
• Live order tracking — status page guests actually keep open
• RTL & multilingual — Arabic-first layouts switch automatically

Free for every store. Apply it, upload your photos, and the storefront does the selling.
TXT,
                'screens' => [
                    ['shotHero', ['headline' => 'Built to make them order', 'sub' => 'Image-led menu, sticky cart, checkout one thumb away.', 'kicker' => 'bold storefront theme', 'heroA' => 'Smash it. Stack it.', 'heroCta' => 'Order now', 'cartBar' => 'View cart · 46.00']],
                    ['shotDesktop', ['heroA' => 'Fired fresh, every day', 'heroCta' => 'Start order']],
                    ['shotMobile', ['cartBar' => 'View cart · 46.00']],
                ],
            ],

            'starter' => [
                'name' => 'Starter',
                'semver' => '1',
                'category' => 'general',
                'price' => 0,
                'mode' => 'light',
                'accent' => '#2e7d5b',
                'store' => 'Corner Café',
                'tagline' => 'Clean, sectioned base theme — fast, accessible and easy to make your own.',
                'demo_notes' => 'The live preview renders a demo dataset — your menu, photos, branding and languages replace it the moment you apply the theme.',
                'description' => <<<'TXT'
A clean start that already looks finished. Starter is the platform's sectioned base theme: a light, airy storefront that puts your menu first and stays out of the way — cafés, bakeries and everyday restaurants are live in minutes.

Built on the sectioned theme format, so every block — hero, categories, featured items, story, footer — can be reordered or hidden without touching code.

• Sectioned layout — drag, reorder and hide blocks from the customizer
• Menu-first design — categories and items above the fold, no fluff
• Fast by default — lean markup, instant loads on mid-range phones
• Accessible — real contrast ratios, focus states and screen-reader labels
• Customizer-ready — colors, fonts and spacing without editing files
• RTL & multilingual — every layout mirrors correctly out of the box

Free, neutral and dependable — the right default, and the right base to build your own look on.
TXT,
                'screens' => [
                    ['shotHero', ['headline' => 'A clean start that looks finished', 'sub' => 'Sectioned, fast and accessible — your menu, front and center.', 'kicker' => 'sectioned base theme', 'heroA' => 'Morning, friend', 'heroCta' => 'See menu', 'cartBar' => 'View cart · 18.50']],
                    ['shotDesktop', ['heroA' => 'Baked this morning', 'heroCta' => 'Order pickup']],
                    ['shotMobile', ['cartBar' => 'View cart · 18.50']],
                ],
            ],

            'bazaar' => [
                'name' => 'Bazaar',
                'semver' => '1',
                'category' => 'retail',
                'price' => 0,
                'mode' => 'light',
                'accent' => '#1f6feb',
                'store' => 'Bazaar Market',
                'tagline' => 'A white-card storefront built for goods and services — searchable catalog, one-tap add-to-cart, RTL and dark mode included.',
                'demo_notes' => 'The live preview renders a demo dataset — your catalog, photos, branding and languages replace it the moment you apply the theme.',
                'description' => <<<'TXT'
Built for selling things, not just food. Bazaar is a clean, white-card storefront for shops, pharmacies, salons and every other goods or services store on the platform: searchable product grid, category chips, one-tap add-to-cart and a proper checkout — without a single hardcoded "menu" or "dish" anywhere in the theme.

Every label runs through the platform's vertical vocabulary, so the same package reads "Products" and "Add to cart" for a retail store, "Services" and "Book" for a salon or laundry, and "Rooms" and "Reservation" for a stay — no theme swap required when a store's category changes.

• Vertical-aware copy — catalog, item and action words adapt to the store type automatically
• White product cards — image, price, rating and stock status, one tap to add
• Search, category chips and a favourites list — finding things is fast
• Full checkout — cart, order tracking and account, wired to the commerce runtime
• Light & dark — matches the visitor's system preference automatically
• RTL & multilingual — Arabic layouts mirror correctly out of the box

Free for every store. Apply it, upload your catalog, and it reads right no matter what you sell.
TXT,
                'screens' => [
                    ['shotHero', ['headline' => 'Built to sell more than food', 'sub' => 'A white-card catalog, one-tap add-to-cart, checkout that just works.', 'kicker' => 'goods & services storefront theme', 'heroA' => 'New in store', 'heroCta' => 'Shop now', 'cartBar' => 'View cart · 128.00']],
                    ['shotDesktop', ['heroA' => 'Fresh arrivals weekly', 'heroCta' => 'Browse catalog']],
                    ['shotMobile', ['cartBar' => 'View cart · 128.00']],
                ],
            ],
        ];
    }
}
