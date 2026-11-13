<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Console\Commands;

use App\Services\ThemeCustomizerService;
use Illuminate\Console\Command;
use Juwri\Modules\Storefront\Services\Theme\Tokens;

/**
 * Customizer v2 (WI-S2/S3): render a swatch thumbnail for every theme preset.
 *
 *   php artisan theme:preset-thumbs [--theme=crave]
 *
 * A thumbnail is a deterministic 320×200 card built from the preset's own colour
 * roles — a background field, a surface tile, the primary and accent as chips,
 * and a corner whose radius reflects the preset's `layout.radius`. It is written
 * to assets/store_templates/<theme>/presets/<id>.png, which is exactly the path
 * each preset's `thumb` points at. GD only (no headless browser, no font file),
 * so it regenerates identically on any machine and in CI.
 */
class ThemePresetThumbs extends Command
{
    protected $signature = 'theme:preset-thumbs {--theme= : one theme slug instead of all}';

    protected $description = 'Render preset swatch thumbnails for the theme customizer';

    private const W = 320;

    private const H = 200;

    public function handle(): int
    {
        $only = $this->option('theme');
        $root = base_path('../assets/store_templates');
        $count = 0;

        foreach (glob($root.'/*/theme.json') as $path) {
            $theme = basename(dirname($path));
            if ($only && $theme !== $only) {
                continue;
            }

            $schema = ThemeCustomizerService::schemaFor($theme);
            $presets = $schema['presets'] ?? [];
            if ($presets === []) {
                continue;
            }

            $dir = $root.'/'.$theme.'/presets';
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $defaults = ThemeCustomizerService::defaults($schema);
            foreach ($presets as $preset) {
                $values = array_merge($defaults, is_array($preset['values'] ?? null) ? $preset['values'] : []);
                $this->render($values, $dir.'/'.$preset['id'].'.png');
                $count++;
            }
            $this->line("  {$theme}: ".count($presets).' thumb(s)');
        }

        $this->info("Rendered {$count} preset thumbnail(s).");

        return self::SUCCESS;
    }

    /** @param array<string,mixed> $values */
    private function render(array $values, string $out): void
    {
        $bg = Tokens::normalizeHex($values['colors.background'] ?? null) ?? '#111111';
        $surface = Tokens::normalizeHex($values['colors.surface'] ?? null) ?? $bg;
        $text = Tokens::normalizeHex($values['colors.text'] ?? null) ?? '#ffffff';
        $primary = Tokens::normalizeHex($values['colors.primary'] ?? null) ?? '#888888';
        $accent = Tokens::normalizeHex($values['colors.accent'] ?? null) ?? $primary;

        $radius = match ((string) ($values['layout.radius'] ?? 'round')) {
            'none' => 0,
            'soft' => 10,
            'pill' => 26,
            default => 18,
        };

        $img = imagecreatetruecolor(self::W, self::H);
        imagefill($img, 0, 0, $this->alloc($img, $bg));

        // A surface card sitting on the background, its corner radius = the preset's.
        $this->roundedRect($img, 24, 34, self::W - 24, self::H - 34, $radius, $this->alloc($img, $surface));

        // Primary + accent chips.
        $this->roundedRect($img, 42, 58, 150, 108, min($radius, 24), $this->alloc($img, $primary));
        $this->roundedRect($img, 166, 58, 274, 108, min($radius, 24), $this->alloc($img, $accent));

        // A text bar and a muted bar, to read as content.
        imagefilledrectangle($img, 42, 126, 210, 138, $this->alloc($img, $text));
        $muted = Tokens::normalizeHex(Tokens::mix($text, $surface, 0.5));
        imagefilledrectangle($img, 42, 148, 274, 158, $this->alloc($img, $muted));

        imagepng($img, $out, 9);
        imagedestroy($img);
    }

    private function alloc($img, string $hex): int
    {
        [$r, $g, $b] = Tokens::rgb($hex);

        return imagecolorallocate($img, $r, $g, $b);
    }

    /** A filled rectangle with rounded corners (GD has no primitive for it). */
    private function roundedRect($img, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
    {
        $r = max(0, min($r, (int) (($x2 - $x1) / 2), (int) (($y2 - $y1) / 2)));
        if ($r === 0) {
            imagefilledrectangle($img, $x1, $y1, $x2, $y2, $color);

            return;
        }
        imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        $d = $r * 2;
        imagefilledarc($img, $x1 + $r, $y1 + $r, $d, $d, 180, 270, $color, IMG_ARC_PIE);
        imagefilledarc($img, $x2 - $r, $y1 + $r, $d, $d, 270, 360, $color, IMG_ARC_PIE);
        imagefilledarc($img, $x1 + $r, $y2 - $r, $d, $d, 90, 180, $color, IMG_ARC_PIE);
        imagefilledarc($img, $x2 - $r, $y2 - $r, $d, $d, 0, 90, $color, IMG_ARC_PIE);
    }
}
