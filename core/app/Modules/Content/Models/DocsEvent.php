<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * DOCS-PLATFORM D6-2: an append-only docs-quality signal (helpful vote, search,
 * or assistant question). Written best-effort from the Help Center; read by the
 * docs:analytics report. No updated_at — events never change.
 *
 * @property-read int $n Aggregate alias from docs:analytics COUNT(*) queries.
 * @property-read mixed $first_seen Aggregate alias from docs:analytics MIN(created_at).
 */
class DocsEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['type', 'pillar', 'slug', 'user_id', 'meta', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    /** Best-effort record — never let analytics break a docs page. */
    public static function record(string $type, string $pillar, ?string $slug = null, ?string $meta = null): void
    {
        try {
            static::create([
                'type' => $type,
                'pillar' => $pillar,
                'slug' => $slug,
                'user_id' => auth()->id(),
                'meta' => $meta !== null ? mb_substr($meta, 0, 500) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // swallow — a logging table must never 500 a docs view
        }
    }
}
