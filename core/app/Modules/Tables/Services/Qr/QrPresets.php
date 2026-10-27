<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Tables\Services\Qr;

use Juwri\Modules\Tables\Models\QrDesign;

/**
 * PARITY-RUN T10-4: the preset gallery.
 *
 * Eight starting points, every one of which passes QrScanGuard on its own (the
 * gallery is the first thing an owner clicks, so a preset that cannot be saved
 * would be an immediate dead end). `classic` is deliberately first and is the
 * exact plain black-on-white code the platform rendered before this track — an
 * owner can always get back to something that scans on any reader.
 *
 * Labels are translated at the call site, not here: this file is data, and
 * ___() at class-definition time would bake the boot locale into the array.
 */
class QrPresets
{
    /** @return array<string, array<string, mixed>> preset key => raw design attributes */
    public static function all(): array
    {
        return [
            'classic' => [
                'label'      => 'Classic',
                'fg_color'   => '#0a0a0a',
                'bg_color'   => '#ffffff',
                'dot_style'  => 'square',
                'eye_style'  => 'square',
            ],
            'rounded' => [
                'label'      => 'Rounded',
                'fg_color'   => '#111827',
                'bg_color'   => '#ffffff',
                'dot_style'  => 'rounded',
                'eye_style'  => 'rounded',
            ],
            'dots' => [
                'label'      => 'Dots',
                'fg_color'   => '#1f2937',
                'bg_color'   => '#ffffff',
                'dot_style'  => 'dots',
                'eye_style'  => 'circle',
            ],
            'espresso' => [
                'label'      => 'Espresso',
                'fg_color'   => '#3b2314',
                'bg_color'   => '#faf5ef',
                'eye_color'  => '#8a5a2b',
                'dot_style'  => 'rounded',
                'eye_style'  => 'rounded',
            ],
            'mint' => [
                'label'      => 'Mint',
                'fg_color'   => '#0f766e',
                'bg_color'   => '#f0fdfa',
                'dot_style'  => 'dots',
                'eye_style'  => 'rounded',
            ],
            'sunset' => [
                'label'         => 'Sunset',
                'fg_color'      => '#9a3412',
                'bg_color'      => '#fffbeb',
                'gradient_to'   => '#b91c1c',
                'gradient_type' => 'linear',
                'dot_style'     => 'rounded',
                'eye_style'     => 'circle',
            ],
            'midnight' => [
                'label'         => 'Midnight',
                'fg_color'      => '#1e1b4b',
                'bg_color'      => '#eef2ff',
                'gradient_to'   => '#4338ca',
                'gradient_type' => 'radial',
                'dot_style'     => 'square',
                'eye_style'     => 'rounded',
            ],
            'tent' => [
                // Table-tent starting point: a framed card with a call to action.
                'label'      => 'Table tent',
                'fg_color'   => '#0a0a0a',
                'bg_color'   => '#ffffff',
                'dot_style'  => 'rounded',
                'eye_style'  => 'square',
                'frame'      => 'bottom',
                'frame_text' => 'Scan to order',
            ],
        ];
    }

    /** Raw attributes for one preset (unknown key → classic). */
    public static function attributes(string $key): array
    {
        $all = self::all();
        $preset = $all[$key] ?? $all['classic'];
        unset($preset['label']);

        return $preset;
    }

    /** Normalized settings for one preset — what the gallery thumbnails render. */
    public static function settings(string $key): array
    {
        return QrDesign::normalize(self::attributes($key));
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
