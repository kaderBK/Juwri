<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use App\Models\TemplateRollout;

/**
 * One store's membership in a rollout: which arm it is in (control | variant)
 * and the theme it had before the rollout.
 */
class TemplateRolloutStore extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    /** @return BelongsTo<TemplateRollout, $this> */
    public function rollout(): BelongsTo
    {
        return $this->belongsTo(TemplateRollout::class, 'rollout_id');
    }
}
