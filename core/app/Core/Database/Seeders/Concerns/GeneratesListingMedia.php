<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Database\Seeders\Concerns;

use Illuminate\Support\Facades\File;

/**
 * MONETIZATION-RUN M0-4: generate listing media for ANY marketplace listable.
 *
 * AppStoreCatalogSeeder hand-authors a rich window-mock per app because there
 * are fifteen of them. Add-ons are a different scale problem — 45+ SKUs across
 * Waves A/B/C — so this concern derives the whole listing from a compact spec
 * (accent colour, a glyph, the benefit-led title/body M0-1 already wrote into
 * the feature row) rather than from hand-drawn screens. One icon, three
 * benefit panels, one social raster, written under
 * `storage/marketplace/{type}/{id}/` and indexed in `marketplace_media`.
 *
 * Idempotent by construction: {@see writeListingMedia()} deletes the listable's
 * existing media (rows AND files) before regenerating, so re-running a seeder
 * leaves exactly one icon and three screenshots — never an orphan on disk.
 *
 * The listable only has to expose a `media()` morphMany and an `id`; both App
 * and AddonProduct do.
 */
trait GeneratesListingMedia
{
    use RasterizesSvg;

    /**
     * @param object $listable  App|AddonProduct — anything with media() + id
     * @param string $typeDir   storage sub-folder: 'app' | 'addon'
     * @param array  $spec      ['accent'=>'#hex','glyph'=>svg,'title'=>..,'tagline'=>..,'bullets'=>[[head,body],..]]
     */
    protected function writeListingMedia(object $listable, string $typeDir, array $spec): void
    {
        $rel = "storage/marketplace/{$typeDir}/{$listable->id}/";
        $abs = public_path($rel);
        File::ensureDirectoryExists($abs);

        // Wipe prior media first — rows and the files they point at — so a
        // re-seed can never leave a stale screenshot behind.
        foreach ($listable->media()->get() as $existing) {
            @unlink(public_path($existing->disk_path));
            $existing->delete();
        }
        // Belt and braces: sweep any file the folder still holds from an
        // interrupted prior run whose row never committed.
        foreach (glob($abs.'*') ?: [] as $stale) {
            @unlink($stale);
        }

        file_put_contents($abs.'icon.svg', $this->listingIconSvg($spec));
        $listable->media()->create(['disk_path' => $rel.'icon.svg', 'kind' => 'icon', 'position' => 0]);

        $bullets = array_values($spec['bullets'] ?? []);
        for ($i = 0; $i < 3; $i++) {
            $file = 'shot-'.($i + 1).'.svg';
            $svg = $i === 0
                ? $this->listingHeroSvg($spec)
                : $this->listingPanelSvg($spec, $bullets[$i - 1] ?? null, $i);
            file_put_contents($abs.$file, $svg);
            $listable->media()->create(['disk_path' => $rel.$file, 'kind' => 'screenshot', 'position' => $i + 1]);
        }

        /* The PNG raster is a social-crawler (og:image) asset — irrelevant to a
           test run, and the macOS fallback (qlmanage) is slow enough to dominate
           the suite. Skip it under the test runner; production and the manual
           seeder still emit it. The listing views fall back to no og:image when
           the file is absent, so this changes nothing a merchant sees. */
        if (! (function_exists('app') && app()->runningUnitTests())) {
            $this->rasterizeToPng($abs.'shot-1.svg', $abs.'og.png');
        }
    }

    // ---------------------------------------------------------------- helpers

    private function lmEsc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1);
    }

    /** Darken a #rrggbb colour by $f (0..1). */
    private function lmShade(string $hex, float $f): string
    {
        $c = sscanf($hex, '#%02x%02x%02x');

        return sprintf('#%02x%02x%02x', (int) ($c[0] * (1 - $f)), (int) ($c[1] * (1 - $f)), (int) ($c[2] * (1 - $f)));
    }

    private function lmText(float $x, float $y, string $s, float $size, string $fill, int $weight = 400, string $anchor = 'start'): string
    {
        return '<text x="'.$x.'" y="'.$y.'" font-family="-apple-system,\'Segoe UI\',Arial,sans-serif" font-size="'.$size
            .'" font-weight="'.$weight.'" fill="'.$fill.'" text-anchor="'.$anchor.'">'.$this->lmEsc($s).'</text>';
    }

    private function lmWrap(float $x, float $y, string $text, float $size, string $fill, int $weight, int $chars): string
    {
        $lines = explode("\n", wordwrap($text, $chars, "\n", true));
        $s = '<text x="'.$x.'" y="'.$y.'" font-family="-apple-system,\'Segoe UI\',Arial,sans-serif" font-size="'.$size
            .'" font-weight="'.$weight.'" fill="'.$fill.'">';
        foreach ($lines as $i => $line) {
            $s .= '<tspan x="'.$x.'" dy="'.($i === 0 ? 0 : $size * 1.3).'">'.$this->lmEsc($line).'</tspan>';
        }

        return $s.'</text>';
    }

    /** 512×512 rounded-square icon: accent gradient + white glyph. */
    private function listingIconSvg(array $spec): string
    {
        $a = $spec['accent'];

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">'
            .'<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
            .'<stop offset="0" stop-color="'.$a.'"/><stop offset="1" stop-color="'.$this->lmShade($a, .35).'"/>'
            .'</linearGradient></defs>'
            .'<rect width="512" height="512" rx="112" fill="url(#g)"/>'
            .'<circle cx="400" cy="112" r="150" fill="#ffffff" opacity=".08"/>'
            .'<g fill="none" stroke="#ffffff" stroke-width="26" stroke-linecap="round" stroke-linejoin="round">'
            .($spec['glyph'] ?? '').'</g></svg>';
    }

    /** 1600×1000 marketing hero: gradient, name, headline, sub. */
    private function listingHeroSvg(array $spec): string
    {
        $a = $spec['accent'];

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1600 1000">'
            .'<defs><linearGradient id="hg" x1="0" y1="0" x2="1" y2="1">'
            .'<stop offset="0" stop-color="'.$this->lmShade($a, .12).'"/><stop offset="1" stop-color="'.$this->lmShade($a, .5).'"/>'
            .'</linearGradient></defs>'
            .'<rect width="1600" height="1000" fill="url(#hg)"/>'
            .'<circle cx="1440" cy="70" r="300" fill="#ffffff" opacity=".06"/>'
            .'<circle cx="120" cy="960" r="220" fill="#ffffff" opacity=".05"/>'
            .'<g transform="translate(150,150) scale(3)" fill="none" stroke="#ffffff" stroke-width="9" stroke-linecap="round" stroke-linejoin="round" opacity=".9">'
            .($spec['glyph'] ?? '').'</g>'
            .$this->lmWrap(150, 470, $spec['title'] ?? '', 66, '#ffffff', 800, 26)
            .$this->lmWrap(150, 620, $spec['tagline'] ?? '', 26, 'rgba(255,255,255,.9)', 400, 62)
            .'</svg>';
    }

    /**
     * 1600×1000 benefit panel: soft tinted ground + a card whose heading/body
     * come from one "Feature — benefit" bullet. Panels 2 and 3 shift the accent
     * so the three screenshots do not look identical in the store carousel.
     */
    private function listingPanelSvg(array $spec, ?array $bullet, int $index): string
    {
        $a = $spec['accent'];
        $head = $bullet[0] ?? ($spec['title'] ?? '');
        $body = $bullet[1] ?? ($spec['tagline'] ?? '');

        $s = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1600 1000">'
            .'<defs><linearGradient id="bg'.$index.'" x1="0" y1="0" x2="1" y2="1">'
            .'<stop offset="0" stop-color="'.$a.'" stop-opacity="'.($index === 1 ? '.16' : '.10').'"/>'
            .'<stop offset="1" stop-color="'.$a.'" stop-opacity=".04"/>'
            .'</linearGradient></defs>'
            .'<rect width="1600" height="1000" fill="#f6f4ef"/><rect width="1600" height="1000" fill="url(#bg'.$index.')"/>';

        // Card.
        $s .= '<rect x="180" y="170" width="1240" height="660" rx="28" fill="#ffffff" stroke="#e7e4de"/>';
        $s .= '<rect x="180" y="170" width="1240" height="120" rx="28" fill="'.$a.'" opacity=".1"/>';
        $s .= '<rect x="180" y="250" width="1240" height="40" fill="#ffffff"/>';
        $s .= '<circle cx="256" cy="238" r="34" fill="'.$a.'"/>';
        $s .= '<g transform="translate(238,220) scale(1.5)" fill="none" stroke="#ffffff" stroke-width="14" stroke-linecap="round" stroke-linejoin="round">'
            .($spec['glyph'] ?? '').'</g>';
        $s .= $this->lmWrap(316, 250, $head, 34, '#2c2a26', 800, 34);
        $s .= $this->lmWrap(230, 380, $body, 26, '#5d584f', 400, 74);

        // Three faux rows as texture, tinted by the same accent.
        for ($i = 0; $i < 3; $i++) {
            $y = 520 + $i * 92;
            $s .= '<rect x="230" y="'.$y.'" width="1140" height="68" rx="14" fill="#faf9f6" stroke="#eeebe4"/>';
            $s .= '<circle cx="276" cy="'.($y + 34).'" r="18" fill="'.$a.'" opacity="'.(0.28 - $i * 0.07).'"/>';
            $s .= '<rect x="314" y="'.($y + 22).'" width="'.(520 - $i * 70).'" height="13" rx="6" fill="#dcd8cf"/>';
            $s .= '<rect x="314" y="'.($y + 44).'" width="'.(360 - $i * 40).'" height="9" rx="4" fill="#eae7e0"/>';
            $s .= '<rect x="1230" y="'.($y + 24).'" width="110" height="22" rx="11" fill="'.$a.'" opacity=".16"/>';
        }

        return $s.'</svg>';
    }
}
