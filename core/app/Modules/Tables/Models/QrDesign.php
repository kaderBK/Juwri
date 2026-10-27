<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Tables\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;

/**
 * PARITY-RUN T10: a store's branded QR design.
 *
 * `normalize()` is the ONE place raw input (a saved row, a preset, or the
 * designer's unsaved live-preview query string) becomes the settings array every
 * renderer consumes. Keeping it static and pure is what makes the live preview
 * and the saved render provably identical — the preview endpoint and the export
 * endpoints call the same function with the same shape.
 *
 * NOTE on the logo: `logo_file` is a bare filename in the SHARED storage/menu/
 * library. Never unlink it when a design changes or is deleted — the same file
 * routinely backs menu items too. MediaReferences knows about this column
 * (see its SOURCES list) so the shared-file reference count includes designs.
 */
class QrDesign extends Model
{
    use BelongsToAccount;
    use HasFactory;

    protected $table = 'qr_designs';

    protected $guarded = [];

    protected $casts = [
        'is_default' => 'boolean',
        'logo_scale' => 'integer',
        'size' => 'integer',
        'quiet_zone' => 'integer',
    ];

    public const DOT_STYLES = ['square', 'rounded', 'dots'];

    public const EYE_STYLES = ['square', 'rounded', 'circle'];

    public const FRAMES = ['none', 'bottom', 'top'];

    public const GRADIENTS = ['linear', 'radial'];

    /** Smallest size the raster exporter will produce, and the largest. */
    public const MIN_SIZE = 128;

    public const MAX_SIZE = 2048;

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /** The store's active design, or null when it has never opened the designer. */
    public static function forStore(?int $storeId): ?self
    {
        if (! $storeId) {
            return null;
        }

        return static::where('store_id', $storeId)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    /** This row as the renderer's settings array. */
    public function settings(): array
    {
        return static::normalize($this->attributesToArray());
    }

    /**
     * Clamp + default any raw input into the renderer contract.
     *
     * Every value is defensive: the preview endpoint feeds this straight from a
     * query string, so "anything that is not valid falls back" is the rule, not
     * "anything invalid throws". The one derived value is `ec`: a logo overlay
     * ALWAYS forces error-correction level H, because the overlay destroys
     * modules and only H's ~30 % recovery budget absorbs that.
     */
    public static function normalize(array $raw): array
    {
        // Accept BOTH shapes: the model's column names and an already-normalized
        // settings array (so monochrome()/overrides can be re-normalized safely).
        foreach (['fg' => 'fg_color', 'bg' => 'bg_color', 'eye' => 'eye_color'] as $short => $column) {
            if (! array_key_exists($column, $raw) && array_key_exists($short, $raw)) {
                $raw[$column] = $raw[$short];
            }
        }

        $hex = static function ($value, ?string $fallback): ?string {
            if (! is_string($value) || ! preg_match('/^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', trim($value))) {
                return $fallback;
            }
            $h = ltrim(trim($value), '#');
            if (strlen($h) === 3) {
                $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
            }

            return '#'.strtolower($h);
        };
        $pick = static fn ($value, array $allowed, string $fallback): string => in_array($value, $allowed, true) ? $value : $fallback;
        $clamp = static fn ($value, int $min, int $max, int $fallback): int => is_numeric($value) ? max($min, min($max, (int) $value)) : $fallback;

        $fg = $hex($raw['fg_color'] ?? null, '#0a0a0a');
        $bg = $hex($raw['bg_color'] ?? null, '#ffffff');
        $logo = $raw['logo_file'] ?? null;
        $logo = (is_string($logo) && $logo !== '') ? basename($logo) : null;

        $frameText = $raw['frame_text'] ?? null;
        $frameText = (is_string($frameText) && trim($frameText) !== '')
            ? mb_substr(trim($frameText), 0, 60)
            : null;

        return [
            'fg' => $fg,
            'bg' => $bg,
            'eye' => $hex($raw['eye_color'] ?? null, null) ?? $fg,
            'gradient_to' => $hex($raw['gradient_to'] ?? null, null),
            'gradient_type' => $pick($raw['gradient_type'] ?? null, self::GRADIENTS, 'linear'),
            'dot_style' => $pick($raw['dot_style'] ?? null, self::DOT_STYLES, 'square'),
            'eye_style' => $pick($raw['eye_style'] ?? null, self::EYE_STYLES, 'square'),
            'logo_file' => $logo,
            'logo_scale' => $clamp($raw['logo_scale'] ?? null, 5, 40, 20),
            'frame' => $pick($raw['frame'] ?? null, self::FRAMES, 'none'),
            'frame_text' => $frameText,
            'size' => $clamp($raw['size'] ?? null, self::MIN_SIZE, self::MAX_SIZE, 512),
            'quiet_zone' => $clamp($raw['quiet_zone'] ?? null, 0, 8, 4),
            // Derived, never stored: a logo mandates H.
            'ec' => $logo ? 'H' : $pick(strtoupper((string) ($raw['ec'] ?? '')), ['L', 'M', 'Q', 'H'], 'M'),
        ];
    }

    /** The plain black-on-white design every legacy/compliance surface falls back to. */
    public static function plain(array $overrides = []): array
    {
        return static::normalize($overrides + [
            'fg_color' => '#000000',
            'bg_color' => '#ffffff',
            'dot_style' => 'square',
            'eye_style' => 'square',
            'quiet_zone' => 4,
            'ec' => 'L',
        ]);
    }

    /**
     * Strip a design down to something a 1-bit thermal printer can render.
     * Fiscal/receipt QRs go through this: colour, gradients and logo overlays
     * dither into mush on thermal paper, and a fiscal QR that will not scan is a
     * compliance failure, not a branding preference.
     */
    public static function monochrome(array $settings): array
    {
        return static::normalize(array_merge($settings, [
            'fg_color' => '#000000',
            'bg_color' => '#ffffff',
            'eye_color' => '#000000',
            'gradient_to' => null,
            'logo_file' => null,
            'frame' => 'none',
            'ec' => $settings['ec'] ?? 'M',
        ]));
    }
}
