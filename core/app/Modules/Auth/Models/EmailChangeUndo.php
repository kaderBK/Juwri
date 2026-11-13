<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Juwri\Core\Models\User;

/**
 * BL-036 A1a: a pending "This wasn't me — undo" link mailed to the OLD address
 * after a sign-in email change. See EmailChangeUndoService.
 *
 * @property int $id
 * @property int $user_id
 * @property string $old_email
 * @property string $new_email
 * @property string $source
 * @property int|null $reseller_id
 * @property int|null $actor_user_id
 * @property string $token_hash
 * @property Carbon|null $changed_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $used_at
 * @property Carbon|null $voided_at
 * @property string|null $used_ip
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EmailChangeUndo extends Model
{
    /** The account owner changed their own email (owner settings, reseller account). */
    public const SOURCE_SELF = 'self';

    /** A reseller changed its customer's email from the partner portal. */
    public const SOURCE_RESELLER = 'reseller';

    protected $table = 'email_change_undos';

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'changed_at' => 'datetime',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'voided_at' => 'datetime',
        'meta' => 'array',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return list<int> webhook endpoint ids the undo paused (A1b) */
    public function deactivatedWebhookIds(): array
    {
        $ids = is_array($this->meta) ? ($this->meta['deactivated_webhooks'] ?? []) : [];

        return array_values(array_map('intval', is_array($ids) ? $ids : []));
    }

    /** @return list<int> staff member ids the undo switched off (created or edited since the change) */
    public function disabledStaffIds(): array
    {
        $ids = is_array($this->meta) ? ($this->meta['disabled_staff'] ?? []) : [];

        return array_values(array_map('intval', is_array($ids) ? $ids : []));
    }
}
