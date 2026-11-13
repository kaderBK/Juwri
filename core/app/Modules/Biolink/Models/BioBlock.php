<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Biolink\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Modules\Biolink\Database\Factories\BioBlockFactory;

/**
 * BIOLINK-RUN A1: one block on a store's bio page. SPEC §3 LOCKED contract.
 *
 * fk-less BelongsToTenant pattern: `store_id` is an indexed column (no DB foreign
 * key), tenant isolation is enforced in owner controllers by filtering to the
 * dashboard owner's post ids — a bio page is public-by-design (any diner loads
 * it by slug) so no global scope is applied.
 *
 * The 14 `type` values and the `config` shape per type are frozen in SPEC §3.
 * `config._i18n` holds per-locale owner-content overrides (SPEC §3 i18n) and is
 * stripped by BioLinkService::blocksFor() before the render contract is returned.
 */
class BioBlock extends Model
{
    use BelongsToAccount;

    /** @use HasFactory<BioBlockFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'store_id' => 'integer',
        'branch_id' => 'integer',
        'config' => 'array',
        'position' => 'integer',
        'active' => 'boolean',
        'visible_from' => 'datetime',
        'visible_until' => 'datetime',
        'clicks_cached' => 'integer',
    ];

    /** The 14 block types frozen in SPEC §3. */
    public const TYPES = [
        'link', 'menu', 'menu_item', 'hours', 'map', 'whatsapp',
        'delivery_apps', 'reservation', 'reviews', 'announcement',
        'socials', 'capture', 'video', 'featured_dish',
        'text', 'image', 'divider',
        // bio-t15 Lane A (SPEC §2 — new types only; hours/reservation/reviews are
        // enhanced IN PLACE, see LEDGER "Contract changes"). Added one at a time,
        // each with its whitelist entry + partial landing in the same commit.
        'call', 'delivery', 'giftcard', 'loyalty', 'gallery', 'faq', 'event', 'tipjar',
    ];

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /**
     * Is this block visible right now given its schedule window? The caller passes
     * a store-local `now` (Carbon in the store's timezone). Bounds are stored as
     * store-local wall-clock, so the comparison is wall-clock (Y-m-d H:i:s) to
     * avoid any absolute-instant timezone skew. A null bound is open-ended.
     */
    public function isScheduledVisible(?Carbon $now = null): bool
    {
        $now ??= now();
        $nowStr = $now->format('Y-m-d H:i:s');

        if ($this->visible_from !== null && $nowStr < $this->visible_from->format('Y-m-d H:i:s')) {
            return false;
        }

        if ($this->visible_until !== null && $nowStr > $this->visible_until->format('Y-m-d H:i:s')) {
            return false;
        }

        return true;
    }
}
