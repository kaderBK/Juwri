<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\User;

/**
 * One row per merchant per campaign — the record of what a bulk email did.
 *
 * @property int $id
 * @property int $user_id
 * @property string $campaign
 * @property string $status
 * @property string|null $error
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property-read User|null $user
 */
class MerchantCampaignSend extends Model
{
    protected $table = 'merchant_campaign_sends';

    protected $guarded = [];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_SKIPPED_OPTOUT = 'skipped_optout';

    public const STATUS_SKIPPED_DUPLICATE = 'skipped_duplicate';

    public const STATUS_FAILED = 'failed';

    /** The campaign was stopped before this recipient's mail went out. */
    public const STATUS_STOPPED = 'stopped';

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
