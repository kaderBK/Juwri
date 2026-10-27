<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * P2-6: one submitted theme version — the IMMUTABLE unit of the marketplace.
 * Once published, its content fields (semver/manifest/checksum) can never
 * change; sellers ship updates as NEW rows through the same review pipeline.
 * That immutability is what makes merchant version-pinning (P4-7) safe.
 */
class ThemeVersion extends Model
{
    protected $guarded = [];

    protected $casts = [
        'manifest' => 'array',
        'lint_report' => 'array',
        'gate_report' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (ThemeVersion $version) {
            // A published version's content is frozen — only status may move
            // (published → delisted). Guard at the model layer so no code path
            // can silently rewrite what merchants already installed.
            if ($version->getOriginal('status') === 'published') {
                // JSON columns (MySQL / PostgreSQL) return object keys in their own order, so an
                // unchanged manifest re-read from the database can differ only by key order.
                $dirty = array_keys(array_filter(
                    $version->getDirty(),
                    fn ($value, $key) => ! (in_array($key, ['manifest', 'lint_report', 'gate_report'], true)
                        && self::canonicalJson($version->getOriginal($key)) === self::canonicalJson($value)),
                    ARRAY_FILTER_USE_BOTH
                ));
                $illegal = array_diff($dirty, ['status', 'updated_at']);
                if ($illegal !== []) {
                    throw new RuntimeException(
                        'Published theme versions are immutable (attempted to change: '.implode(', ', $illegal).').'
                    );
                }
            }
        });
    }

    /** Key-order-insensitive form of a JSON value (array, or its encoded string). */
    private static function canonicalJson(mixed $value): string
    {
        if (is_string($value)) {
            $value = json_decode($value, true) ?? $value;
        }
        $sort = function (mixed $v) use (&$sort): mixed {
            if (! is_array($v)) {
                return $v;
            }
            $v = array_map($sort, $v);
            if (! array_is_list($v)) {
                ksort($v);
            }

            return $v;
        };

        return (string) json_encode($sort($value));
    }

    /** @return BelongsTo<Theme, $this> */
    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }
}
