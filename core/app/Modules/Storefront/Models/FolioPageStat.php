<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

/**
 * Folio Polish (FP run) B8 — one aggregated row per (store, day, page) of the
 * flip-book theme. Written only through the anonymous storefront beacon via
 * firstOrCreate + atomic increment; read by the owner "Insights" panel.
 *
 * No timestamps (the `day` column is the time axis) and fully mass-assignable
 * because every write goes through the controller's validated payload.
 */
class FolioPageStat extends Model
{
    use BelongsToAccount;

    protected $table = 'folio_page_stats';

    public $timestamps = false;

    /** @var array<string> */
    protected $guarded = [];
}
