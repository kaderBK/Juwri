<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Biolink\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * BIOLINK-RUN A1: slim, PII-free per-day bio click/view counter. SPEC §3 LOCKED.
 *
 * `block_id = 0` is the reserved PAGE-VIEW sentinel (G1): views and clicks share
 * one table and one grain. Never carries per-visitor data. Rows are upserted with
 * an atomic count increment keyed on UNIQUE(block_id, day, source, device).
 *
 * No timestamps: the `day` column is the only time dimension this table keeps.
 */
class BioClick extends Model
{
    use BelongsToAccount;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'store_id' => 'integer',
        'block_id' => 'integer',
        'day' => 'date',
        'count' => 'integer',
    ];

    /** Reserved block id for a bio PAGE VIEW (G1). */
    public const VIEW_SENTINEL = 0;

    /** Allowed source buckets (SPEC §3). */
    public const SOURCES = ['qr', 'social', 'direct', 'share', 'other'];

    /** Allowed device buckets (SPEC §3). */
    public const DEVICES = ['mobile', 'desktop', 'tablet', 'other'];

    /**
     * bio-t15 Lane D (SPEC §7, D1): sentinel for "no coarse country derived" —
     * an unconfigured geo seam (visitor_country() dark by default), a preview
     * request, or any row written before D1 was wired. Never an empty string,
     * so every row always groups cleanly in a GROUP BY.
     */
    public const COUNTRY_UNKNOWN = '--';

    /**
     * bio-t15 Lane D (SPEC §7, D2): sentinel for "no referrer host" (direct
     * nav / no Referer header) — distinct from the `'other'` bucket, which
     * means a referrer WAS present but is not on the known-host allowlist.
     */
    public const REF_NONE = '';

    /** bio-t15 Lane D: catch-all bucket for a referrer host outside the allowlist. */
    public const REF_OTHER = 'other';
}
