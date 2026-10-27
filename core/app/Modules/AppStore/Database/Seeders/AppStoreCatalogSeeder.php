<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Database\Seeders;

use App\Models\App;
use Juwri\Core\Database\Seeders\Concerns\RasterizesSvg;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * App Store listing content: full benefit-led descriptions (hook paragraphs +
 * "Feature — benefit" bullets, Shopify-listing style) plus GENERATED media —
 * a square icon and three 16:10 product-mock screenshots per app, written as
 * SVG files under storage/marketplace/app/{id}/ and indexed in
 * marketplace_media (kind icon|screenshot). Idempotent: re-running wipes and
 * regenerates each app's media. Apps are matched by slug; unknown slugs skip.
 */
class AppStoreCatalogSeeder extends Seeder
{
    use RasterizesSvg;

    public function run(): void
    {
        foreach ($this->catalog() as $slug => $spec) {
            $app = App::where('slug', $slug)->first();
            if (! $app) {
                continue;
            }

            $app->update([
                'description' => trim($spec['description']),
                'category' => $spec['category'],
            ]);

            $rel = "storage/marketplace/app/{$app->id}/";
            File::ensureDirectoryExists(public_path($rel));

            foreach ($app->media()->get() as $m) {
                @unlink(public_path($m->disk_path));
                $m->delete();
            }

            file_put_contents(public_path($rel . 'icon.svg'), $this->iconSvg($spec));
            $app->media()->create(['disk_path' => $rel . 'icon.svg', 'kind' => 'icon', 'position' => 0]);

            foreach ($spec['screens'] as $i => $screen) {
                $file = 'shot-' . ($i + 1) . '.svg';
                $svg = $i === 0
                    ? $this->heroSvg($app, $spec, $screen)
                    : $this->screenshotSvg($app, $spec, $screen);
                file_put_contents(public_path($rel . $file), $svg);
                $app->media()->create(['disk_path' => $rel . $file, 'kind' => 'screenshot', 'position' => $i + 1]);
            }

            // Social-share raster of the hero (crawlers can't render SVG).
            $this->rasterizeToPng(public_path($rel . 'shot-1.svg'), public_path($rel . 'og.png'));

            $this->command?->info("Seeded listing media for {$slug} (app {$app->id})");
        }
    }

    /* ---------------------------------------------------------------- SVG -- */

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1);
    }

    private function txt(float $x, float $y, string $s, int $size = 16, string $fill = '#2c2a26', int $weight = 400, string $anchor = 'start'): string
    {
        return '<text x="' . $x . '" y="' . $y . '" font-family="-apple-system,\'Segoe UI\',Arial,sans-serif" font-size="' . $size
            . '" font-weight="' . $weight . '" fill="' . $fill . '" text-anchor="' . $anchor . '">' . $this->e($s) . '</text>';
    }

    private function bar(float $x, float $y, float $w, float $h, string $fill, float $rx = 6, float $opacity = 1): string
    {
        return '<rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $h . '" rx="' . $rx . '" fill="' . $fill . '"'
            . ($opacity < 1 ? ' opacity="' . $opacity . '"' : '') . '/>';
    }

    /** Darken a #rrggbb color by $f (0..1). */
    private function shade(string $hex, float $f): string
    {
        $c = sscanf($hex, '#%02x%02x%02x');

        return sprintf('#%02x%02x%02x', (int) ($c[0] * (1 - $f)), (int) ($c[1] * (1 - $f)), (int) ($c[2] * (1 - $f)));
    }

    /** 512x512 app icon: accent gradient rounded square + white glyph. */
    private function iconSvg(array $spec): string
    {
        $a = $spec['accent'];

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">'
            . '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
            . '<stop offset="0" stop-color="' . $a . '"/><stop offset="1" stop-color="' . $this->shade($a, .35) . '"/>'
            . '</linearGradient></defs>'
            . '<rect width="512" height="512" rx="112" fill="url(#g)"/>'
            . '<circle cx="400" cy="112" r="150" fill="#ffffff" opacity=".08"/>'
            . '<g fill="none" stroke="#ffffff" stroke-width="26" stroke-linecap="round" stroke-linejoin="round">'
            . $spec['glyph']
            . '</g></svg>';
    }

    /**
     * The reusable app-window mock (1420x860 at origin 0,0): chrome bar,
     * sidebar with nav, then the variant's content.
     */
    private function windowGroup(App $app, array $spec, array $screen): string
    {
        $a = $spec['accent'];
        $s = '<g>';
        $s .= '<rect width="1420" height="860" rx="18" fill="#ffffff" stroke="#e7e4de"/>';
        // chrome bar
        $s .= '<path d="M0 18 a18 18 0 0 1 18-18 h1384 a18 18 0 0 1 18 18 v34 h-1420 z" fill="#faf9f6"/>';
        $s .= '<line x1="0" y1="52" x2="1420" y2="52" stroke="#eeebe4"/>';
        $s .= '<circle cx="30" cy="26" r="7" fill="#ff5f57"/><circle cx="56" cy="26" r="7" fill="#febc2e"/><circle cx="82" cy="26" r="7" fill="#28c840"/>';
        $s .= $this->bar(460, 12, 500, 28, '#f0eee9', 14);
        $s .= $this->txt(710, 31, 'yourbrand.com/apps/' . $app->slug, 14, '#a7a199', 400, 'middle');
        // sidebar
        $s .= $this->bar(0, 52, 232, 808, '#fbfaf7', 0);
        $s .= '<line x1="232" y1="52" x2="232" y2="860" stroke="#eeebe4"/>';
        $s .= '<circle cx="46" cy="100" r="16" fill="' . $a . '"/>';
        $s .= $this->txt(74, 106, $app->name, 17, '#2c2a26', 700);
        foreach ($spec['nav'] as $i => $item) {
            $y = 150 + $i * 48;
            if ($i === ($screen['nav'] ?? 0)) {
                $s .= $this->bar(14, $y, 204, 38, $a, 10, .14);
                $s .= '<circle cx="38" cy="' . ($y + 19) . '" r="5" fill="' . $a . '"/>';
                $s .= $this->txt(56, $y + 25, $item, 15.5, $this->shade($a, .25), 700);
            } else {
                $s .= '<circle cx="38" cy="' . ($y + 19) . '" r="5" fill="#d6d1c8"/>';
                $s .= $this->txt(56, $y + 25, $item, 15.5, '#8a857c');
            }
        }
        // page header
        $s .= $this->txt(268, 116, $screen['title'], 26, '#2c2a26', 800);
        $s .= $this->bar(1268, 88, 120, 40, $a, 20);
        $s .= $this->txt(1328, 113, $screen['cta'] ?? 'New', 15, '#ffffff', 700, 'middle');
        // content
        $s .= '<g transform="translate(268,150)">' . $this->{'v' . ucfirst($screen['variant'])}($a, $screen) . '</g>';
        $s .= '</g>';

        return $s;
    }

    /** Standard screenshot: soft tinted background + centered window. */
    private function screenshotSvg(App $app, array $spec, array $screen): string
    {
        $a = $spec['accent'];

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1600 1000">'
            . '<defs><linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">'
            . '<stop offset="0" stop-color="' . $a . '" stop-opacity=".14"/><stop offset="1" stop-color="' . $a . '" stop-opacity=".05"/>'
            . '</linearGradient></defs>'
            . '<rect width="1600" height="1000" fill="#f6f4ef"/><rect width="1600" height="1000" fill="url(#bg)"/>'
            . '<g transform="translate(90,70)">' . $this->windowGroup($app, $spec, $screen) . '</g>'
            . '</svg>';
    }

    /** First screenshot: marketing hero — gradient, headline, offset window. */
    private function heroSvg(App $app, array $spec, array $screen): string
    {
        $a = $spec['accent'];

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1600 1000">'
            . '<defs><linearGradient id="hg" x1="0" y1="0" x2="1" y2="1">'
            . '<stop offset="0" stop-color="' . $this->shade($a, .15) . '"/><stop offset="1" stop-color="' . $this->shade($a, .55) . '"/>'
            . '</linearGradient></defs>'
            . '<rect width="1600" height="1000" fill="url(#hg)"/>'
            . '<circle cx="1440" cy="60" r="280" fill="#ffffff" opacity=".06"/>'
            . '<circle cx="120" cy="960" r="220" fill="#ffffff" opacity=".05"/>'
            . $this->txt(110, 200, $app->name, 30, '#ffffff', 700)
            . $this->wrapText(110, 280, $screen['headline'], 58, '#ffffff', 800, 34)
            . $this->wrapText(110, 420, $screen['sub'], 22, 'rgba(255,255,255,.85)', 400, 78)
            . '<g transform="translate(410,520) scale(.82)">' . $this->windowGroup($app, $spec, $screen) . '</g>'
            . '</svg>';
    }

    /** Naive word-wrap into tspans at ~$chars per line. */
    private function wrapText(float $x, float $y, string $text, int $size, string $fill, int $weight, int $chars): string
    {
        $lines = explode("\n", wordwrap($text, $chars, "\n", true));
        $s = '<text x="' . $x . '" y="' . $y . '" font-family="-apple-system,\'Segoe UI\',Arial,sans-serif" font-size="' . $size
            . '" font-weight="' . $weight . '" fill="' . $fill . '">';
        foreach ($lines as $i => $line) {
            $s .= '<tspan x="' . $x . '" dy="' . ($i === 0 ? 0 : $size * 1.25) . '">' . $this->e($line) . '</tspan>';
        }

        return $s . '</text>';
    }

    /* ------------------------------------------------- content variants -- */
    /* Each renders inside a 1120x690 area at local origin 0,0. */

    private function vKpis(string $a, array $screen): string
    {
        $s = '';
        $labels = $screen['labels'] ?? ['Revenue today', 'Orders', 'Avg ticket', 'Repeat rate'];
        $values = $screen['values'] ?? ['12,480', '318', '39.20', '46%'];
        $deltas = ['+12%', '+8%', '+3%', '+5%'];
        foreach ($labels as $i => $label) {
            $x = $i * 284;
            $s .= $this->bar($x, 0, 260, 120, '#ffffff', 14) . $this->bar($x, 0, 260, 120, $a, 14, .05);
            $s .= '<rect x="' . $x . '" y="0" width="260" height="120" rx="14" fill="none" stroke="#eeebe4"/>';
            $s .= $this->txt($x + 20, 34, $label, 14, '#8a857c');
            $s .= $this->txt($x + 20, 78, $values[$i], 30, '#2c2a26', 800);
            $s .= $this->txt($x + 20, 104, $deltas[$i] . '  vs last week', 13, '#3f9d63');
        }
        // bar chart card
        $s .= $this->bar(0, 150, 700, 420, '#ffffff', 14) . '<rect y="150" width="700" height="420" rx="14" fill="none" stroke="#eeebe4"/>';
        $s .= $this->txt(24, 188, $screen['chart'] ?? 'Sales by hour', 17, '#2c2a26', 700);
        $h = [90, 130, 70, 180, 240, 210, 280, 250, 300, 200, 150, 110];
        foreach ($h as $i => $bh) {
            $s .= $this->bar(30 + $i * 55, 540 - $bh, 36, $bh, $a, 7, $i === 8 ? 1 : .55);
        }
        // side list card
        $s .= $this->bar(730, 150, 390, 420, '#ffffff', 14) . '<rect x="730" y="150" width="390" height="420" rx="14" fill="none" stroke="#eeebe4"/>';
        $s .= $this->txt(754, 188, $screen['list'] ?? 'Top items', 17, '#2c2a26', 700);
        $w = [300, 250, 210, 160, 120];
        foreach ($w as $i => $bw) {
            $y = 220 + $i * 64;
            $s .= '<circle cx="772" cy="' . ($y + 12) . '" r="14" fill="' . $a . '" opacity=".18"/>';
            $s .= $this->txt(772, $y + 17, (string) ($i + 1), 13, $this->shade($a, .2), 700, 'middle');
            $s .= $this->bar(800, $y, $bw, 12, '#e9e6df', 6);
            $s .= $this->bar(800, $y + 20, $bw * .8, 8, $a, 4, .45);
        }

        return $s;
    }

    private function vTable(string $a, array $screen): string
    {
        $s = $this->bar(0, 0, 560, 42, '#ffffff', 21) . '<rect width="560" height="42" rx="21" fill="none" stroke="#e7e4de"/>';
        $s .= '<circle cx="26" cy="21" r="7" fill="none" stroke="#a7a199" stroke-width="2.5"/><line x1="31" y1="27" x2="38" y2="34" stroke="#a7a199" stroke-width="2.5" stroke-linecap="round"/>';
        $s .= $this->txt(52, 27, $screen['search'] ?? 'Search…', 14.5, '#a7a199');
        $s .= $this->bar(0, 66, 1120, 46, '#f4f2ec', 10);
        foreach (($screen['cols'] ?? ['Name', 'Status', 'Updated', 'Total']) as $i => $col) {
            $s .= $this->txt(24 + $i * 280, 95, strtoupper($col), 12.5, '#8a857c', 700);
        }
        $pills = $screen['pills'] ?? ['Active', 'Active', 'Pending', 'Active', 'Draft', 'Active'];
        $pillColor = ['Active' => '#3f9d63', 'Pending' => '#c9932f', 'Draft' => '#8a857c', 'Open' => '#c9932f', 'Paid' => '#3f9d63', 'Matched' => '#3f9d63', 'On route' => '#c9932f', 'Delivered' => '#3f9d63', 'Cleared' => '#3f9d63', 'Queued' => '#8a857c', 'Ready' => '#3f9d63', 'Preparing' => '#c9932f'];
        for ($i = 0; $i < 6; $i++) {
            $y = 112 + $i * 78;
            $s .= '<line x1="0" y1="' . ($y + 78) . '" x2="1120" y2="' . ($y + 78) . '" stroke="#eeebe4"/>';
            $s .= '<circle cx="40" cy="' . ($y + 39) . '" r="17" fill="' . $a . '" opacity="' . (.12 + $i * .04) . '"/>';
            $s .= $this->bar(72, $y + 24, 190 - $i * 12, 13, '#dcd8cf', 6);
            $s .= $this->bar(72, $y + 46, 120, 9, '#eae7e0', 4);
            $p = $pills[$i % count($pills)];
            $pc = $pillColor[$p] ?? '#8a857c';
            $s .= $this->bar(304, $y + 26, 26 + strlen($p) * 8, 26, $pc, 13, .14);
            $s .= $this->txt(317 + strlen($p) * 4, $y + 44, $p, 13, $pc, 700, 'middle');
            $s .= $this->bar(584, $y + 31, 110, 11, '#e5e2da', 5);
            $s .= $this->txt(1096, $y + 45, $screen['amounts'][$i] ?? '', 15, '#2c2a26', 700, 'end');
        }

        return $s;
    }

    private function vBoard(string $a, array $screen): string
    {
        $s = '';
        $cols = $screen['boardCols'] ?? ['To do', 'In progress', 'Done'];
        $cards = [[3, 2], [2, 3], [2, 2]];
        foreach ($cols as $c => $col) {
            $x = $c * 380;
            $s .= $this->bar($x, 0, 356, 640, '#f4f2ec', 14);
            $s .= $this->txt($x + 20, 36, $col, 15.5, '#5d584f', 700);
            $s .= '<circle cx="' . ($x + 330) . '" cy="30" r="12" fill="' . $a . '" opacity=".16"/>';
            $s .= $this->txt($x + 330, 35, (string) ($cards[$c][0] + $cards[$c][1]), 12, $this->shade($a, .2), 700, 'middle');
            $n = $cards[$c][0] + $cards[$c][1];
            for ($i = 0; $i < $n; $i++) {
                $y = 58 + $i * 118;
                $s .= $this->bar($x + 14, $y, 328, 104, '#ffffff', 12);
                $s .= '<rect x="' . ($x + 14) . '" y="' . $y . '" width="328" height="104" rx="12" fill="none" stroke="#e7e4de"/>';
                $s .= $this->bar($x + 30, $y + 20, 210 - $i * 20, 12, '#dcd8cf', 6);
                $s .= $this->bar($x + 30, $y + 42, 150, 9, '#eae7e0', 4);
                $s .= $this->bar($x + 30, $y + 68, 68, 22, $a, 11, ($i + $c) % 2 ? .16 : .30);
                $s .= '<circle cx="' . ($x + 318) . '" cy="' . ($y + 79) . '" r="12" fill="' . $a . '" opacity=".25"/>';
            }
        }

        return $s;
    }

    private function vChat(string $a, array $screen): string
    {
        $s = $this->bar(0, 0, 330, 690, '#f4f2ec', 14);
        for ($i = 0; $i < 5; $i++) {
            $y = 20 + $i * 86;
            if ($i === 0) {
                $s .= $this->bar(10, $y - 8, 310, 78, '#ffffff', 12);
            }
            $s .= '<circle cx="44" cy="' . ($y + 26) . '" r="19" fill="' . $a . '" opacity="' . (.5 - $i * .08) . '"/>';
            $s .= $this->bar(76, $y + 12, 160 - $i * 10, 12, '#d6d1c8', 6);
            $s .= $this->bar(76, $y + 34, 210, 9, '#e5e2da', 4);
        }
        $s .= $this->txt(370, 30, $screen['thread'] ?? 'Conversation', 17, '#2c2a26', 700);
        $b = [[380, 60, 430, 'them'], [560, 130, 480, 'me'], [380, 200, 360, 'them'], [640, 270, 400, 'me'], [380, 340, 470, 'them'], [520, 410, 520, 'me']];
        foreach ($b as $i => [$x, $y, $w, $who]) {
            $fill = $who === 'me' ? $a : '#f0eee9';
            $s .= '<rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="54" rx="16" fill="' . $fill . '"' . ($who === 'me' ? ' opacity=".88"' : '') . '/>';
            $tb = $who === 'me' ? 'rgba(255,255,255,.85)' : '#c9c4ba';
            $s .= '<rect x="' . ($x + 18) . '" y="' . ($y + 16) . '" width="' . ($w - 60) . '" height="9" rx="4" fill="' . $tb . '"/>';
            $s .= '<rect x="' . ($x + 18) . '" y="' . ($y + 32) . '" width="' . (($w - 60) * .6) . '" height="9" rx="4" fill="' . $tb . '"/>';
        }
        $s .= $this->bar(370, 620, 750, 52, '#ffffff', 26) . '<rect x="370" y="620" width="750" height="52" rx="26" fill="none" stroke="#e7e4de"/>';
        $s .= $this->txt(398, 652, $screen['compose'] ?? 'Write a reply…', 15, '#a7a199');
        $s .= '<circle cx="1088" cy="646" r="20" fill="' . $a . '"/><path d="M1080 646 h14 m-5 -6 6 6 -6 6" stroke="#fff" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>';

        return $s;
    }

    private function vMap(string $a, array $screen): string
    {
        $s = $this->bar(0, 0, 1120, 690, '#eef1ee', 14);
        for ($i = 1; $i < 12; $i++) {
            $s .= '<line x1="' . ($i * 95) . '" y1="0" x2="' . ($i * 95) . '" y2="690" stroke="#e0e5e0" stroke-width="1.5"/>';
        }
        for ($i = 1; $i < 8; $i++) {
            $s .= '<line x1="0" y1="' . ($i * 92) . '" x2="1120" y2="' . ($i * 92) . '" stroke="#e0e5e0" stroke-width="1.5"/>';
        }
        $s .= '<path d="M120 560 C 300 480, 380 300, 560 300 S 880 180, 1010 150" fill="none" stroke="' . $a . '" stroke-width="6" stroke-dasharray="14 12" stroke-linecap="round"/>';
        foreach ([[120, 560], [560, 300], [1010, 150]] as $i => [$px, $py]) {
            $s .= '<circle cx="' . $px . '" cy="' . $py . '" r="22" fill="' . $a . '"/><circle cx="' . $px . '" cy="' . $py . '" r="9" fill="#fff"/>';
            $s .= '<circle cx="' . $px . '" cy="' . $py . '" r="34" fill="' . $a . '" opacity=".18"/>';
        }
        // heat blobs
        foreach ([[300, 180, 90], [820, 460, 120], [640, 560, 70]] as [$px, $py, $r]) {
            $s .= '<circle cx="' . $px . '" cy="' . $py . '" r="' . $r . '" fill="' . $a . '" opacity=".10"/>';
        }
        // side panel
        $s .= $this->bar(760, 30, 330, 320, '#ffffff', 14) . '<rect x="760" y="30" width="330" height="320" rx="14" fill="none" stroke="#e7e4de"/>';
        $s .= $this->txt(784, 66, $screen['panel'] ?? 'Stops', 16, '#2c2a26', 700);
        for ($i = 0; $i < 3; $i++) {
            $y = 92 + $i * 80;
            $s .= '<circle cx="796" cy="' . ($y + 22) . '" r="14" fill="' . $a . '" opacity=".2"/>';
            $s .= $this->txt(796, $y + 27, (string) ($i + 1), 13, $this->shade($a, .2), 700, 'middle');
            $s .= $this->bar(824, $y + 8, 200 - $i * 20, 12, '#dcd8cf', 6);
            $s .= $this->bar(824, $y + 30, 140, 9, '#eae7e0', 4);
        }

        return $s;
    }

    private function vDoc(string $a, array $screen): string
    {
        $s = $this->bar(0, 0, 660, 690, '#ffffff', 14) . '<rect width="660" height="690" rx="14" fill="none" stroke="#e7e4de"/>';
        $s .= $this->bar(40, 40, 56, 56, $a, 14, .85);
        $s .= $this->txt(120, 62, $screen['docTitle'] ?? 'Document', 20, '#2c2a26', 800);
        $s .= $this->bar(120, 76, 150, 10, '#e5e2da', 5);
        $s .= $this->bar(510, 44, 110, 30, '#3f9d63', 15, .15) . $this->txt(565, 64, $screen['docBadge'] ?? 'Paid', 14, '#3f9d63', 700, 'middle');
        for ($i = 0; $i < 4; $i++) {
            $y = 140 + $i * 26;
            $s .= $this->bar(40, $y, 220 - $i * 30, 10, '#eae7e0', 5);
        }
        $s .= $this->bar(40, 268, 580, 40, '#f4f2ec', 10);
        for ($i = 0; $i < 5; $i++) {
            $y = 308 + $i * 52;
            $s .= '<line x1="40" y1="' . ($y + 52) . '" x2="620" y2="' . ($y + 52) . '" stroke="#eeebe4"/>';
            $s .= $this->bar(56, $y + 20, 220 - $i * 18, 11, '#dcd8cf', 5);
            $s .= $this->txt(608, $y + 34, ['420.00', '186.50', '96.00', '54.25', '312.75'][$i], 14, '#5d584f', 600, 'end');
        }
        $s .= $this->bar(360, 596, 260, 62, $a, 12, .12);
        $s .= $this->txt(384, 622, $screen['totalLabel'] ?? 'Total', 14, '#8a857c');
        $s .= $this->txt(596, 644, $screen['total'] ?? '1,069.50', 24, $this->shade($a, .2), 800, 'end');
        // side summary
        $s .= $this->bar(700, 0, 420, 330, '#ffffff', 14) . '<rect x="700" width="420" height="330" rx="14" fill="none" stroke="#e7e4de"/>';
        $s .= $this->txt(724, 40, $screen['side'] ?? 'Summary', 16, '#2c2a26', 700);
        foreach ([260, 200, 300, 160] as $i => $w) {
            $y = 76 + $i * 58;
            $s .= $this->bar(724, $y, 120, 10, '#eae7e0', 5);
            $s .= $this->bar(724, $y + 18, $w, 13, $a, 6, .3 + $i * .12);
        }
        $s .= $this->bar(700, 360, 420, 330, '#ffffff', 14) . '<rect x="700" y="360" width="420" height="330" rx="14" fill="none" stroke="#e7e4de"/>';
        $s .= $this->txt(724, 400, $screen['side2'] ?? 'History', 16, '#2c2a26', 700);
        for ($i = 0; $i < 4; $i++) {
            $y = 430 + $i * 62;
            $s .= '<circle cx="740" cy="' . ($y + 16) . '" r="6" fill="' . $a . '"/>';
            if ($i < 3) {
                $s .= '<line x1="740" y1="' . ($y + 26) . '" x2="740" y2="' . ($y + 68) . '" stroke="#e7e4de" stroke-width="2"/>';
            }
            $s .= $this->bar(762, $y + 8, 250 - $i * 24, 11, '#dcd8cf', 5);
            $s .= $this->bar(762, $y + 28, 130, 8, '#eae7e0', 4);
        }

        return $s;
    }

    private function vSegments(string $a, array $screen): string
    {
        $s = $this->bar(0, 0, 620, 690, '#ffffff', 14) . '<rect width="620" height="690" rx="14" fill="none" stroke="#e7e4de"/>';
        $s .= $this->txt(30, 44, $screen['segTitle'] ?? 'Segments', 18, '#2c2a26', 700);
        $s .= '<circle cx="240" cy="300" r="150" fill="' . $a . '" opacity=".28"/>';
        $s .= '<circle cx="380" cy="330" r="115" fill="' . $a . '" opacity=".45"/>';
        $s .= '<circle cx="300" cy="440" r="85" fill="' . $this->shade($a, .3) . '" opacity=".5"/>';
        $s .= $this->txt(240, 290, $screen['seg1'] ?? 'Regulars', 17, $this->shade($a, .45), 800, 'middle');
        $s .= $this->txt(240, 316, '2,340', 15, $this->shade($a, .45), 600, 'middle');
        $s .= $this->txt(400, 330, $screen['seg2'] ?? 'Big spenders', 15, '#ffffff', 800, 'middle');
        $s .= $this->txt(300, 452, $screen['seg3'] ?? 'At risk', 14, '#ffffff', 800, 'middle');
        // right ranked list
        $s .= $this->bar(660, 0, 460, 690, '#ffffff', 14) . '<rect x="660" width="460" height="690" rx="14" fill="none" stroke="#e7e4de"/>';
        $s .= $this->txt(688, 44, $screen['listTitle'] ?? 'Audiences', 18, '#2c2a26', 700);
        $w = [340, 300, 260, 210, 170, 130];
        foreach ($w as $i => $bw) {
            $y = 84 + $i * 96;
            $s .= $this->bar(688, $y, 404, 78, '#faf9f6', 12);
            $s .= $this->bar(706, $y + 18, 160 - $i * 10, 11, '#d6d1c8', 5);
            $s .= $this->bar(706, $y + 44, $bw, 16, $a, 8, .8 - $i * .1);
            $s .= $this->txt(1076, $y + 58, (string) (98 - $i * 14) . '%', 13, '#8a857c', 600, 'end');
        }

        return $s;
    }

    private function vDevice(string $a, array $screen): string
    {
        $mode = $screen['device'] ?? 'kiosk';
        $s = '';
        if ($mode === 'kiosk') {
            $s .= $this->bar(330, 0, 460, 690, '#23211d', 28);
            $s .= $this->bar(352, 26, 416, 600, '#fffdf9', 16);
            $s .= $this->bar(352, 26, 416, 90, $a, 16, .9);
            $s .= $this->txt(560, 80, $screen['kioskTitle'] ?? 'Order here', 26, '#ffffff', 800, 'middle');
            for ($r = 0; $r < 3; $r++) {
                for ($c = 0; $c < 2; $c++) {
                    $x = 372 + $c * 192;
                    $y = 136 + $r * 152;
                    $s .= $this->bar($x, $y, 184, 138, '#f4f2ec', 12);
                    $s .= $this->bar($x + 12, $y + 12, 160, 74, $a, 10, .18 + ($r * 2 + $c) * .06);
                    $s .= $this->bar($x + 12, $y + 96, 100, 10, '#d6d1c8', 5);
                    $s .= $this->txt($x + 172, $y + 126, ['24', '32', '18', '27', '21', '35'][$r * 2 + $c], 15, $this->shade($a, .2), 800, 'end');
                }
            }
            $s .= $this->bar(372, 592, 376, 20, $a, 10);
            $s .= '<circle cx="560" cy="652" r="18" fill="#3a372f"/>';
            // side captions
            $s .= $this->txt(60, 300, '01', 46, $a, 800);
            $s .= $this->bar(60, 320, 180, 12, '#dcd8cf', 6);
            $s .= $this->bar(60, 344, 130, 9, '#e5e2da', 5);
            $s .= $this->txt(880, 300, '02', 46, $a, 800);
            $s .= $this->bar(880, 320, 180, 12, '#dcd8cf', 6);
            $s .= $this->bar(880, 344, 130, 9, '#e5e2da', 5);
        } else { // signage board
            $s .= $this->bar(0, 0, 1120, 690, '#1d1b17', 20);
            $s .= $this->bar(0, 0, 1120, 84, $a, 20, .92);
            $s .= $this->txt(40, 54, $screen['boardTitle'] ?? 'Menu', 30, '#ffffff', 800);
            $s .= $this->txt(1080, 54, '18:24', 26, 'rgba(255,255,255,.85)', 700, 'end');
            for ($c = 0; $c < 2; $c++) {
                for ($i = 0; $i < 6; $i++) {
                    $x = 60 + $c * 540;
                    $y = 130 + $i * 84;
                    $s .= $this->bar($x, $y, 340 - $i * 16, 15, 'rgba(255,255,255,.75)', 7);
                    $s .= '<line x1="' . ($x + 350) . '" y1="' . ($y + 8) . '" x2="' . ($x + 420) . '" y2="' . ($y + 8) . '" stroke="rgba(255,255,255,.15)" stroke-dasharray="3 6"/>';
                    $s .= $this->txt($x + 480, $y + 15, ['24', '32', '18', '27', '21', '35'][$i], 21, '#ffffff', 800, 'end');
                    $s .= $this->bar($x, $y + 28, 220, 9, 'rgba(255,255,255,.25)', 4);
                }
            }
        }

        return $s;
    }

    private function vCalendar(string $a, array $screen): string
    {
        $s = '';
        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        foreach ($days as $i => $d) {
            $s .= $this->txt(20 + $i * 160, 24, strtoupper($d), 13, '#8a857c', 700);
        }
        $n = 1;
        for ($r = 0; $r < 4; $r++) {
            for ($c = 0; $c < 7; $c++) {
                $x = $c * 160;
                $y = 44 + $r * 156;
                $s .= '<rect x="' . $x . '" y="' . $y . '" width="148" height="144" rx="10" fill="#ffffff" stroke="#eeebe4"/>';
                $s .= $this->txt($x + 14, $y + 28, (string) $n, 14, '#8a857c', 600);
                if (in_array($n, [3, 8, 12, 17, 21, 26], true)) {
                    $s .= $this->bar($x + 10, $y + 44, 128, 26, $a, 8, .8);
                    $s .= $this->bar($x + 10, $y + 78, 128, 26, $a, 8, .25);
                }
                $n++;
            }
        }

        return $s;
    }

    /* ------------------------------------------------------------ catalog -- */

    private function catalog(): array
    {
        return [
            'hotel-suite' => [
                'accent' => '#8b5cf6',
                'category' => 'operations',
                'glyph' => '<path d="M116 348 V208 M116 348 h280 M396 348 v-84 a44 44 0 0 0 -44 -44 h-132 v84"/><circle cx="168" cy="260" r="26"/>',
                'nav' => ['Front desk', 'Rooms', 'Stays', 'Folios', 'Requests'],
                'description' => <<<'TXT'
Run rooms and restaurant on one system. Hotel Suite adds a full front-desk layer to your store: rooms and rates, guest stays, folios and charge-to-room — so in-room dining, minibar and restaurant bills all land on one guest account.

Guests scan the QR code in their room, order from your live menu and charge it straight to the room. The front desk sees every stay, open folio and pending request in real time, then settles everything in one consolidated bill at checkout.

• Rooms and rates — manage room types, rates and availability in minutes
• Charge-to-room — orders from any channel post to the guest folio automatically
• In-room dining — per-room QR codes drive orders without a phone call
• Guest requests — housekeeping, amenities and maintenance in one inbox
• Stay timeline — check-in to check-out with every charge in view
• One-bill checkout — settle the whole stay in a single payment

Works with your existing menu, taxes and payment methods — no separate hotel PMS needed. Set up rooms once and take in-room orders the same day.
TXT,
                'screens' => [
                    ['variant' => 'kpis', 'title' => 'Front desk', 'cta' => 'Check in', 'headline' => 'Front desk, folios and in-room dining', 'sub' => 'Rooms, stays and restaurant charges on one guest account.', 'labels' => ['Occupied rooms', 'Arrivals today', 'Open folios', 'Room orders'], 'values' => ['42 / 56', '11', '38', '64'], 'chart' => 'Charges by hour', 'list' => 'Open requests'],
                    ['variant' => 'table', 'title' => 'Stays', 'cta' => 'New stay', 'nav' => 2, 'cols' => ['Guest', 'Status', 'Room', 'Balance'], 'pills' => ['Active', 'Active', 'Pending', 'Active', 'Active', 'Draft'], 'amounts' => ['312.50', '84.00', '0.00', '156.75', '48.20', '620.00']],
                    ['variant' => 'doc', 'title' => 'Folio — Room 214', 'cta' => 'Settle', 'nav' => 3, 'docTitle' => 'Guest folio', 'docBadge' => 'Open', 'totalLabel' => 'Balance due', 'total' => '1,069.50', 'side' => 'Stay details', 'side2' => 'Recent charges'],
                ],
            ],

            'kiosk' => [
                'accent' => '#0ea5e9',
                'category' => 'pos',
                'glyph' => '<rect x="156" y="120" width="200" height="272" rx="24"/><line x1="156" y1="336" x2="356" y2="336"/><circle cx="256" cy="364" r="4"/>',
                'nav' => ['Kiosks', 'Layout', 'Upsells', 'Payments', 'Reports'],
                'description' => <<<'TXT'
Turn any tablet into a self-service ordering kiosk. Kiosk gives your counter, lobby or queue a full-screen menu that guests browse and order from without staff help — orders land in the kitchen like any other channel.

Modifiers, combos and upsells are handled on screen, and payment closes the loop with card, wallet or pay-at-counter.

• Full-screen guest UI — big touch targets built for walk-up ordering
• Live menu sync — items, prices and 86'd products update instantly
• On-screen payment — card, wallet or pay-at-counter flows
• Smart upsells — combo and add-on prompts at the right step
• Attract screen — your branding on idle displays
• Any hardware — runs in the browser on any tablet or kiosk enclosure

Cut queue length at peak hours and let staff focus on prep and hospitality instead of taking orders.
TXT,
                'screens' => [
                    ['variant' => 'device', 'device' => 'kiosk', 'title' => 'Kiosk preview', 'cta' => 'Pair', 'headline' => 'Self-service ordering at the counter', 'sub' => 'A full-screen menu guests order from — no staff needed.', 'kioskTitle' => 'Order & pay here'],
                    ['variant' => 'kpis', 'title' => 'Kiosk performance', 'cta' => 'Export', 'nav' => 4, 'labels' => ['Kiosk orders', 'Avg ticket', 'Upsell rate', 'Queue saved'], 'values' => ['186', '31.40', '24%', '3.2 hrs'], 'chart' => 'Orders by hour', 'list' => 'Top upsells'],
                    ['variant' => 'table', 'title' => 'Paired kiosks', 'cta' => 'Add kiosk', 'cols' => ['Device', 'Status', 'Branch', 'Today'], 'pills' => ['Active', 'Active', 'Active', 'Pending', 'Active', 'Active'], 'amounts' => ['64', '58', '43', '—', '12', '9']],
                ],
            ],

            'digital-signage' => [
                'accent' => '#f59e0b',
                'category' => 'operations',
                'glyph' => '<rect x="120" y="140" width="272" height="172" rx="18"/><line x1="256" y1="312" x2="256" y2="368"/><line x1="196" y1="368" x2="316" y2="368"/>',
                'nav' => ['Boards', 'Layouts', 'Screens', 'Schedule', 'Branches'],
                'description' => <<<'TXT'
Put your menu and order status on any screen. Digital Signage turns TVs and displays into live menu boards and order-ready boards driven directly by your store data.

Boards update the moment you change a price, 86 an item or mark an order ready — no USB sticks, no design tools, no re-exporting slides.

• Live menu boards — items, prices and availability always current
• Order status board — preparing → ready with big order numbers
• Layout library — rotate between boards and promos on a schedule
• Per-branch boards — each location shows its own content
• Instant pairing — connect any smart TV or stick with a short code
• Zero maintenance — change the menu once, every screen follows

Pair a screen in minutes, then manage everything from your dashboard.
TXT,
                'screens' => [
                    ['variant' => 'device', 'device' => 'signage', 'title' => 'Board preview', 'cta' => 'Publish', 'headline' => 'Menu boards that update themselves', 'sub' => 'Live prices and availability on every screen you own.', 'boardTitle' => "Today's menu"],
                    ['variant' => 'table', 'title' => 'Screens', 'cta' => 'Pair screen', 'nav' => 2, 'cols' => ['Screen', 'Status', 'Board', 'Uptime'], 'pills' => ['Active', 'Active', 'Active', 'Active', 'Pending', 'Active'], 'amounts' => ['99.9%', '99.7%', '99.9%', '98.2%', '—', '99.4%']],
                    ['variant' => 'calendar', 'title' => 'Board schedule', 'cta' => 'Add slot', 'nav' => 3],
                ],
            ],

            'accounting' => [
                'accent' => '#10b981',
                'category' => 'back_office',
                'glyph' => '<path d="M128 384 h256 M128 384 V148 M168 344 v-80 M240 344 V204 M312 344 v-56"/>',
                'nav' => ['Overview', 'Journal', 'Expenses', 'Bills', 'Reports'],
                'description' => <<<'TXT'
Restaurant accounting without the spreadsheet gymnastics. A double-entry general ledger built into your store: sales, taxes, expenses and supplier bills post to real accounts automatically.

Daily sales journals come straight from your orders; expenses and purchases take seconds to capture. When your accountant asks for the numbers, export clean reports instead of shoeboxes.

• Double-entry ledger — a restaurant-ready chart of accounts out of the box
• Automatic journals — daily sales, tax and payment-method postings, hands-free
• Expense capture — categories, receipts and recurring costs in seconds
• Supplier bills — track what's owed and what's paid
• Real reports — P&L, balance sheet and trial balance on demand
• Period locking — close a month and keep it closed

One source of truth from order to report — the books always match what actually sold.
TXT,
                'screens' => [
                    ['variant' => 'kpis', 'title' => 'Financial overview', 'cta' => 'Report', 'headline' => 'Books that write themselves', 'sub' => 'Every sale, tax and expense posted to a real ledger automatically.', 'labels' => ['Revenue MTD', 'Expenses MTD', 'Gross margin', 'Cash position'], 'values' => ['184,200', '61,900', '66%', '92,400'], 'chart' => 'Revenue vs expenses', 'list' => 'Top expense heads'],
                    ['variant' => 'doc', 'title' => 'Daily sales journal', 'cta' => 'Post', 'nav' => 1, 'docTitle' => 'Journal entry', 'docBadge' => 'Posted', 'totalLabel' => 'Balanced', 'total' => '12,480.00', 'side' => 'Accounts touched', 'side2' => 'Recent postings'],
                    ['variant' => 'table', 'title' => 'Supplier bills', 'cta' => 'Add bill', 'nav' => 3, 'cols' => ['Supplier', 'Status', 'Due', 'Amount'], 'pills' => ['Paid', 'Open', 'Paid', 'Open', 'Paid', 'Paid'], 'amounts' => ['1,240.00', '860.50', '420.00', '2,310.75', '156.20', '980.00']],
                ],
            ],

            'hr-payroll' => [
                'accent' => '#6366f1',
                'category' => 'back_office',
                'glyph' => '<circle cx="200" cy="196" r="44"/><path d="M120 372 c0 -52 36 -84 80 -84 s80 32 80 84"/><circle cx="330" cy="216" r="32"/><path d="M300 372 c4 -40 28 -64 60 -60"/>',
                'nav' => ['Team', 'Leave', 'Pay periods', 'Payslips', 'Settings'],
                'description' => <<<'TXT'
People, shifts and payslips in one place. HR & Payroll keeps employee records, contracts, leave and pay periods next to the operation they work in — not in a separate system nobody updates.

Run a pay period and hours, allowances and deductions roll up into payslips your team can access themselves; leave balances and approvals stay visible to managers.

• Employee profiles — documents, contracts and roles in one record
• Leave management — types, requests and live balances
• Pay periods — allowances, deductions and overtime rolled up automatically
• Payslips — generated, stored and self-serve for every employee
• Branch-aware access — managers see their team, not everyone's
• Clean exports — bank-transfer and accounting-ready files

Built for restaurant teams: fast onboarding, branch transfers, and tidy records when you need them.
TXT,
                'screens' => [
                    ['variant' => 'table', 'title' => 'Team', 'cta' => 'Add person', 'headline' => 'Your team, from contract to payslip', 'sub' => 'Records, leave and payroll in the same system they work in.', 'cols' => ['Employee', 'Status', 'Role', 'Net pay'], 'pills' => ['Active', 'Active', 'Active', 'Pending', 'Active', 'Active'], 'amounts' => ['4,200', '3,850', '3,600', '—', '2,950', '2,780']],
                    ['variant' => 'calendar', 'title' => 'Leave calendar', 'cta' => 'Approve', 'nav' => 1],
                    ['variant' => 'doc', 'title' => 'Payslip — March', 'cta' => 'Send', 'nav' => 3, 'docTitle' => 'Payslip', 'docBadge' => 'Final', 'totalLabel' => 'Net pay', 'total' => '4,206.20', 'side' => 'Earnings', 'side2' => 'Deductions'],
                ],
            ],

            'crm' => [
                'accent' => '#ec4899',
                'category' => 'marketing',
                'glyph' => '<circle cx="256" cy="200" r="48"/><path d="M164 384 c0 -56 40 -92 92 -92 s92 36 92 92"/><path d="M352 148 l14 28 30 5 -22 21 5 30 -27 -14"/>',
                'nav' => ['Guests', 'Segments', 'Timelines', 'Audiences', 'Privacy'],
                'description' => <<<'TXT'
Know every guest, not just every order. CRM unifies profiles from every channel — QR, delivery, POS, reservations — into one guest record with visits, spend and preferences.

RFM segments update themselves, so "slipping-away regulars" and "big spenders" are always current and one click from a campaign.

• Unified profiles — one guest record across every order channel
• Auto-RFM — recency, frequency and spend scoring that stays fresh
• Visit timelines — every order, reservation and review per guest
• Tags and notes — allergies, preferences and VIP flags where staff see them
• Audience export — push segments to campaigns and marketing tools
• Privacy built in — consent tracking and one-click delete tooling

Turns raw order history into audiences you can actually act on.
TXT,
                'screens' => [
                    ['variant' => 'segments', 'title' => 'Segments', 'cta' => 'Sync', 'headline' => 'Segments that keep themselves fresh', 'sub' => 'RFM scoring across every channel, updated with every order.', 'segTitle' => 'RFM map', 'listTitle' => 'Live audiences'],
                    ['variant' => 'table', 'title' => 'Guests', 'cta' => 'Import', 'nav' => 0, 'cols' => ['Guest', 'Segment', 'Visits', 'Lifetime'], 'pills' => ['Active', 'Active', 'Active', 'Active', 'Pending', 'Active'], 'amounts' => ['1,240', '890', '760', '410', '180', '95']],
                    ['variant' => 'kpis', 'title' => 'Guest health', 'cta' => 'Report', 'nav' => 2, 'labels' => ['Known guests', 'Repeat rate', 'Avg lifetime', 'At risk'], 'values' => ['8,412', '46%', '284', '312'], 'chart' => 'New vs returning', 'list' => 'Top guests'],
                ],
            ],

            'support-desk' => [
                'accent' => '#14b8a6',
                'category' => 'operations',
                'glyph' => '<path d="M136 280 v-24 a120 120 0 0 1 240 0 v24"/><rect x="120" y="264" width="44" height="72" rx="20"/><rect x="348" y="264" width="44" height="72" rx="20"/><path d="M376 336 v16 a48 48 0 0 1 -48 48 h-40"/>',
                'nav' => ['Inbox', 'Tickets', 'WhatsApp', 'Templates', 'Reports'],
                'description' => <<<'TXT'
One inbox for every guest conversation. Support Desk pulls feedback forms, WhatsApp messages and order issues into a single shared queue with assignment, statuses and SLAs.

Every ticket carries its guest and order context, so agents answer with the full picture instead of asking guests to repeat themselves.

• Shared inbox — feedback, WhatsApp and order issues in one queue
• Full context — guest profile and order history on every ticket
• Assignment & status — who owns it and where it stands, always visible
• Canned replies — answer the common cases in two clicks
• SLA timers — overdue conversations flag themselves
• Reporting — volume, first-response and resolution time per agent

Stop losing guest complaints in personal phones — give the whole team one queue.
TXT,
                'screens' => [
                    ['variant' => 'chat', 'title' => 'Inbox', 'cta' => 'Assign', 'headline' => 'Every guest conversation, one queue', 'sub' => 'Feedback, WhatsApp and order issues with full context.', 'thread' => 'Order #4218 — cold delivery', 'compose' => 'Type a reply — or pick a template…'],
                    ['variant' => 'table', 'title' => 'Tickets', 'cta' => 'New ticket', 'nav' => 1, 'cols' => ['Ticket', 'Status', 'Agent', 'Age'], 'pills' => ['Open', 'Pending', 'Open', 'Active', 'Open', 'Draft'], 'amounts' => ['12m', '48m', '1h', '2h', '4h', '1d']],
                    ['variant' => 'kpis', 'title' => 'Support health', 'cta' => 'Export', 'nav' => 4, 'labels' => ['Open tickets', 'First response', 'Resolved today', 'CSAT'], 'values' => ['23', '8m', '61', '4.7'], 'chart' => 'Tickets by hour', 'list' => 'Top issue types'],
                ],
            ],

            'supplier-purchasing' => [
                'accent' => '#ca8a04',
                'category' => 'back_office',
                'glyph' => '<path d="M256 120 l120 62 v148 l-120 62 -120 -62 V182 z M136 182 l120 62 120 -62 M256 244 v148"/>',
                'nav' => ['Suppliers', 'Purchase orders', 'Receiving', 'Invoices', 'Variance'],
                'description' => <<<'TXT'
Close the loop from purchase order to plate cost. Supplier & Purchasing manages suppliers, receiving and invoice capture, then reconciles what you paid against what your recipes expect.

COGS variance surfaces quietly rising ingredient prices before they eat your margin — per supplier, per ingredient, per week.

• Supplier directory — contacts, price lists and terms in one place
• Purchase orders — build, send and track POs against deliveries
• Goods receiving — check quantities in and post straight to inventory
• Invoice matching — three-way match PO, delivery and invoice
• COGS variance — actual ingredient cost vs. recipe cost, flagged early
• Supplier statements — what's owed, what's paid, what's disputed

Works hand-in-hand with inventory and accounting, so a delivery received is a cost recorded.
TXT,
                'screens' => [
                    ['variant' => 'table', 'title' => 'Purchase orders', 'cta' => 'New PO', 'headline' => 'From purchase order to plate cost', 'sub' => 'Receiving, invoices and COGS variance in one flow.', 'cols' => ['PO', 'Status', 'Supplier', 'Total'], 'pills' => ['Matched', 'Open', 'Matched', 'Pending', 'Matched', 'Open'], 'amounts' => ['2,140.00', '860.20', '1,432.50', '540.00', '3,208.75', '412.30']],
                    ['variant' => 'doc', 'title' => 'Invoice match', 'cta' => 'Approve', 'nav' => 3, 'docTitle' => 'Supplier invoice', 'docBadge' => 'Matched', 'totalLabel' => 'Invoice total', 'total' => '2,140.00', 'side' => 'PO vs received', 'side2' => 'Match history'],
                    ['variant' => 'kpis', 'title' => 'COGS variance', 'cta' => 'Export', 'nav' => 4, 'labels' => ['COGS this week', 'Variance', 'Price alerts', 'Open POs'], 'values' => ['18,240', '+2.4%', '6', '11'], 'chart' => 'Cost variance by week', 'list' => 'Rising ingredients'],
                ],
            ],

            'team-chat' => [
                'accent' => '#3b82f6',
                'category' => 'operations',
                'glyph' => '<path d="M132 148 h190 a28 28 0 0 1 28 28 v90 a28 28 0 0 1 -28 28 h-120 l-52 44 v-44 h-18 a28 28 0 0 1 -28 -28 v-90 a28 28 0 0 1 28 -28 z"/><path d="M380 240 v52 a28 28 0 0 1 -28 28 h-6"/>',
                'nav' => ['Channels', 'Shift notes', 'Checklists', 'Tasks', 'Announcements'],
                'description' => <<<'TXT'
Shift-proof communication for restaurant teams. Team Chat gives your staff messaging, shift notes and checklists inside the system they already work in — no personal group chats, no lost handovers.

Notes stick to the shift, and opening or closing checklists show exactly what got done, by whom, with photo proof where it matters.

• Team channels — branch and role-based rooms with mentions
• Shift notes — handovers that persist and stay searchable
• Checklists — opening, closing and cleaning runs with completion tracking
• Task assignment — hand off work with photos as proof of done
• Announcements — read receipts so "I didn't see it" retires
• Full history — search everything, forever

Keep operations chatter out of WhatsApp and in a place managers can actually manage.
TXT,
                'screens' => [
                    ['variant' => 'chat', 'title' => 'Channels', 'cta' => 'Post', 'headline' => 'Ops chatter out of WhatsApp', 'sub' => 'Messaging, shift notes and checklists where the work happens.', 'thread' => '# evening-shift — handover', 'compose' => 'Message # evening-shift…'],
                    ['variant' => 'board', 'title' => 'Checklists', 'cta' => 'New run', 'nav' => 2, 'boardCols' => ['Opening', 'During service', 'Closing']],
                    ['variant' => 'table', 'title' => 'Announcements', 'cta' => 'Announce', 'nav' => 4, 'cols' => ['Post', 'Status', 'Author', 'Read'], 'pills' => ['Active', 'Active', 'Active', 'Draft', 'Active', 'Active'], 'amounts' => ['24/26', '26/26', '19/26', '—', '22/26', '26/26']],
                ],
            ],

            'driver-app' => [
                'accent' => '#f97316',
                'category' => 'delivery',
                'glyph' => '<path d="M256 132 c-56 0 -96 42 -96 96 0 72 96 152 96 152 s96 -80 96 -152 c0 -54 -40 -96 -96 -96 z"/><circle cx="256" cy="226" r="34"/>',
                'nav' => ['Dispatch', 'Drivers', 'Routes', 'Proof', 'Cash'],
                'description' => <<<'TXT'
Your own fleet, dispatch to doorstep. Driver App is a PWA your couriers install in one tap: assigned orders, ordered stops, navigation hand-off and proof of delivery.

Dispatchers watch every driver live on the map; customers get tracking links driven by real GPS instead of guesswork.

• Driver PWA — installs from a link, no app store required
• Live dispatch map — every driver and order in one view
• Smart stops — batched orders with one-tap navigation
• Proof of delivery — photo, signature or PIN at the door
• Cash reconciliation — per-driver shift totals that balance
• Zone analytics — delivery times per area, spot the slow spots

Runs alongside aggregator orders in Delivery Hub, so own-fleet and marketplace deliveries share one workflow.
TXT,
                'screens' => [
                    ['variant' => 'map', 'title' => 'Live dispatch', 'cta' => 'Assign', 'headline' => 'Dispatch to doorstep, live', 'sub' => 'Your fleet on one map with real GPS tracking links.', 'panel' => 'Route stops'],
                    ['variant' => 'table', 'title' => 'Driver shifts', 'cta' => 'Close shift', 'nav' => 1, 'cols' => ['Driver', 'Status', 'Deliveries', 'Cash'], 'pills' => ['On route', 'On route', 'Delivered', 'Active', 'On route', 'Delivered'], 'amounts' => ['312.00', '186.50', '420.75', '96.00', '154.25', '380.00']],
                    ['variant' => 'kpis', 'title' => 'Delivery performance', 'cta' => 'Export', 'nav' => 2, 'labels' => ['On-time rate', 'Avg door time', 'Active drivers', 'Deliveries'], 'values' => ['94%', '26m', '8', '142'], 'chart' => 'Deliveries by hour', 'list' => 'Fastest zones'],
                ],
            ],

            'marketing-automation' => [
                'accent' => '#d946ef',
                'category' => 'marketing',
                'glyph' => '<path d="M148 296 v-64 l160 -76 v216 l-160 -76 z"/><path d="M148 232 h-16 a24 24 0 0 0 0 64 h16"/><path d="M196 318 v34 a22 22 0 0 0 44 0 v-24"/><path d="M348 200 c20 12 20 84 0 96"/>',
                'nav' => ['Flows', 'Campaigns', 'Coupons', 'Channels', 'Revenue'],
                'description' => <<<'TXT'
Win back the guests you already paid to acquire. Marketing Automation runs cart recovery and win-back campaigns on autopilot: abandoned carts get a timely nudge, lapsing regulars get a reason to return.

Flows trigger from real order behavior and report the revenue they actually brought back — not opens, not clicks, orders.

• Cart recovery — timed nudges that catch abandoned checkouts
• Win-back flows — RFM-driven campaigns for guests going quiet
• Smart incentives — coupons and free items with spend guardrails
• Multi-channel — email, SMS and WhatsApp from one flow
• Respectful by default — quiet hours, frequency caps, consent handling
• Real attribution — recovered revenue per flow, in money

Set a flow live in minutes; it keeps working every night after.
TXT,
                'screens' => [
                    ['variant' => 'board', 'title' => 'Flows', 'cta' => 'New flow', 'headline' => 'Campaigns that run themselves', 'sub' => 'Cart recovery and win-back, triggered by real behavior.', 'boardCols' => ['Triggered', 'Scheduled', 'Completed']],
                    ['variant' => 'kpis', 'title' => 'Recovered revenue', 'cta' => 'Report', 'nav' => 4, 'labels' => ['Recovered MTD', 'Carts saved', 'Win-backs', 'ROI'], 'values' => ['9,640', '184', '96', '11.2x'], 'chart' => 'Recovered by day', 'list' => 'Best flows'],
                    ['variant' => 'segments', 'title' => 'Audiences', 'cta' => 'Target', 'nav' => 1, 'segTitle' => 'Behavior map', 'seg1' => 'Cart abandoners', 'seg2' => 'Lapsing', 'seg3' => 'VIP', 'listTitle' => 'Flow audiences'],
                ],
            ],

            'demand-forecast' => [
                'accent' => '#84cc16',
                'category' => 'analytics',
                'glyph' => '<path d="M128 372 L 224 276 l64 48 104 -128"/><path d="M336 196 h56 v56"/>',
                'nav' => ['Forecast', 'Heatmap', 'Prep', 'Staffing', 'Accuracy'],
                'description' => <<<'TXT'
See tomorrow's rush today. Demand Forecast learns from your order history, weekday patterns and delivery heat to predict demand per hour, item and zone.

Staff to the forecast instead of the gut: prep lists and staffing suggestions arrive sized to expected volume, and the model grades its own accuracy as it learns.

• Hourly forecasts — expected orders per branch, per hour
• Delivery heatmaps — demand by zone so drivers start where orders will be
• Prep suggestions — item-level quantities before service starts
• Staffing hints — covers expected vs. hands scheduled
• Anomaly flags — unusual days and events called out early
• Self-grading — forecast vs. actual tracked continuously

The longer it runs, the sharper it gets — no data-science degree required.
TXT,
                'screens' => [
                    ['variant' => 'kpis', 'title' => "Tomorrow's forecast", 'cta' => 'Refresh', 'headline' => "See tomorrow's rush today", 'sub' => 'Demand per hour, item and zone — learned from your history.', 'labels' => ['Expected orders', 'Peak hour', 'Prep items', 'Confidence'], 'values' => ['412', '19:00', '38', '91%'], 'chart' => 'Forecast vs actual', 'list' => 'Prep priorities'],
                    ['variant' => 'map', 'title' => 'Demand heatmap', 'cta' => 'Zones', 'nav' => 1, 'panel' => 'Hot zones'],
                    ['variant' => 'calendar', 'title' => 'Staffing plan', 'cta' => 'Apply', 'nav' => 3],
                ],
            ],

            'delivery-hub' => [
                'accent' => '#ef4444',
                'category' => 'delivery',
                'glyph' => '<circle cx="256" cy="256" r="44"/><circle cx="140" cy="150" r="26"/><circle cx="372" cy="150" r="26"/><circle cx="140" cy="362" r="26"/><circle cx="372" cy="362" r="26"/><path d="M162 172 l62 56 M350 172 l-62 56 M162 340 l62 -56 M350 340 l-62 -56"/>',
                'nav' => ['Inbox', 'Channels', 'Dispatch', 'Menu sync', 'Performance'],
                'description' => <<<'TXT'
Every delivery channel in one queue. Delivery Hub pulls aggregator orders and your own-fleet dispatch into a single inbox: accept, prep and hand off from one screen.

Couriers — yours or the platform's — get assigned in one flow, and status flows back to each channel automatically. No more tablet farm on the counter.

• Unified inbox — aggregators and direct orders in one queue
• One-tap accept — with prep-time control per order
• Own-fleet dispatch — assign your drivers next to platform couriers
• Menu sync — items and availability pushed per channel
• Status sync back — each platform stays updated automatically
• Channel comparison — fees, volume and ratings side by side

Stop juggling tablets: one screen, one queue, every order.
TXT,
                'screens' => [
                    ['variant' => 'table', 'title' => 'Order inbox', 'cta' => 'Accept', 'headline' => 'Every channel, one queue', 'sub' => 'Aggregators and own fleet in a single dispatch flow.', 'cols' => ['Order', 'Status', 'Channel', 'Total'], 'pills' => ['Preparing', 'Ready', 'Preparing', 'On route', 'Ready', 'Preparing'], 'amounts' => ['64.00', '31.50', '48.25', '92.75', '27.00', '55.40']],
                    ['variant' => 'map', 'title' => 'Dispatch', 'cta' => 'Assign', 'nav' => 2, 'panel' => 'Hand-offs'],
                    ['variant' => 'kpis', 'title' => 'Channel performance', 'cta' => 'Compare', 'nav' => 4, 'labels' => ['Orders today', 'Own fleet share', 'Avg accept', 'Channel fees'], 'values' => ['238', '41%', '38s', '1,240'], 'chart' => 'Volume by channel', 'list' => 'Channel ratings'],
                ],
            ],

            'multi-vendor' => [
                'accent' => '#06b6d4',
                'category' => 'ordering',
                'glyph' => '<path d="M120 160 h36 l28 152 h150 l30 -110 h-186"/><circle cx="204" cy="366" r="22"/><circle cx="318" cy="366" r="22"/><path d="M352 132 h44 M374 110 v44"/>',
                'nav' => ['Orders', 'Stores', 'Splits', 'Payouts', 'Storefront'],
                'description' => <<<'TXT'
One cart, many kitchens. Multi-Vendor lets guests order from several stores in a single checkout — food court, cloud kitchen or marketplace style.

Each store gets its own ticket and payout split, while the guest sees one order, one payment and one tracking page.

• Cross-store cart — items from any store, one checkout
• Automatic split — each kitchen gets its own ticket instantly
• Independent prep — per-store status rolls up to one tracking page
• Payout splits — each store's share computed and reconciled
• Flexible hand-off — shared courier or per-store delivery
• Marketplace storefront — browse-all pages ready out of the box

The backbone for food halls and multi-brand kitchens on one platform.
TXT,
                'screens' => [
                    ['variant' => 'table', 'title' => 'Split orders', 'cta' => 'View', 'headline' => 'One cart, many kitchens', 'sub' => 'A single checkout that splits itself per store.', 'cols' => ['Order', 'Status', 'Stores', 'Total'], 'pills' => ['Preparing', 'Ready', 'Preparing', 'Ready', 'Preparing', 'Delivered'], 'amounts' => ['86.00', '54.50', '112.25', '38.75', '96.00', '73.40']],
                    ['variant' => 'board', 'title' => 'Kitchen tickets', 'cta' => 'Print', 'nav' => 2, 'boardCols' => ['Burger Bar', 'Sushi Line', 'Sweet Stop']],
                    ['variant' => 'kpis', 'title' => 'Payout splits', 'cta' => 'Settle', 'nav' => 3, 'labels' => ['Marketplace GMV', 'Stores active', 'Pending payouts', 'Avg basket'], 'values' => ['24,180', '14', '3,420', '61.20'], 'chart' => 'GMV by store', 'list' => 'Payout queue'],
                ],
            ],

            'fiscalization' => [
                'accent' => '#0d9488',
                'category' => 'back_office',
                'glyph' => '<path d="M148 120 h216 v260 l-36 -24 -36 24 -36 -24 -36 24 -36 -24 -36 24 z"/><path d="M196 220 l40 40 84 -84"/>',
                'nav' => ['Documents', 'Submissions', 'Devices', 'Credit notes', 'Exports'],
                'description' => <<<'TXT'
Stay compliant without thinking about it. Fiscalization connects your orders to MENA tax-authority e-invoicing: fiscal receipts, QR stamps and reporting formats generated automatically for every sale.

Every order produces the right document with the right fields — and when the rules change phases, mappings update centrally instead of in your workflow.

• E-invoicing — ZATCA-style phase 1 and phase 2 clearance flows
• Fiscal receipts — compliant QR stamps on every customer document
• Credit notes — corrections and refunds handled the compliant way
• Audit trail — sequential numbering with a tamper-evident log
• Device management — per-branch devices and certificates in one panel
• Audit exports — filing-ready packs when the authority asks

Built for the region: Arabic-first documents, VAT handling and clearance status you can see.
TXT,
                'screens' => [
                    ['variant' => 'doc', 'title' => 'Fiscal invoice', 'cta' => 'Clear', 'headline' => 'Compliance on autopilot', 'sub' => 'E-invoices, QR stamps and audit trails for every sale.', 'docTitle' => 'Tax invoice', 'docBadge' => 'Cleared', 'totalLabel' => 'Incl. VAT', 'total' => '431.25', 'side' => 'Clearance status', 'side2' => 'Submission log'],
                    ['variant' => 'table', 'title' => 'Submissions', 'cta' => 'Retry', 'nav' => 1, 'cols' => ['Document', 'Status', 'Branch', 'VAT'], 'pills' => ['Cleared', 'Cleared', 'Queued', 'Cleared', 'Cleared', 'Queued'], 'amounts' => ['64.69', '23.10', '—', '41.25', '18.60', '—']],
                    ['variant' => 'kpis', 'title' => 'Compliance overview', 'cta' => 'Export', 'nav' => 4, 'labels' => ['Cleared today', 'Queue', 'VAT collected', 'Devices online'], 'values' => ['312', '2', '4,860', '6/6'], 'chart' => 'Submissions by day', 'list' => 'By branch'],
                ],
            ],
        ];
    }
}
