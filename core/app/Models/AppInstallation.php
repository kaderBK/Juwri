<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\User;

/**
 * P3-2: one tenant's install of an app — the ledger of granted scopes and the
 * per-install webhook endpoint. Uninstall keeps the row (status=uninstalled)
 * as the audit trail; reinstalling reactivates it.
 */
class AppInstallation extends Model
{
    use BelongsToAccount;

    protected $guarded = [];

    protected $casts = [
        'granted_scopes' => 'array',
        'installed_at' => 'datetime',
        'uninstalled_at' => 'datetime',
        'current_period_ends_at' => 'datetime',
        'trial_ends_at' => 'datetime',        // P4 feature-platform
        'grace_period_ends_at' => 'datetime', // P4 feature-platform; P1-23 part 7: the earliest pause day (Terms 6.4)
        'suspension_notice_at' => 'datetime', // P1-23 part 7: the 7-day written notice, sent once per overdue period
        'trial_reminders' => 'array',         // B4 upsell-visibility: stages already sent
    ];

    /** B4: trial reminder stages, in the order they fire. */
    public const TRIAL_STAGES = ['t_minus_3', 't_minus_1', 'ended'];

    /**
     * F5-1: installation statuses that still legitimately entitle the owner. `past_due` is a paid grace window
     * (AppBillingService::renewDue), not an orphan — access continues until the grace expires and the install is
     * flipped to `suspended`, which DOES revoke. (P1-13: owned here; Billing's reconciler reads it.)
     */
    public const ENTITLING_STATUSES = ['active', 'past_due'];

    /** Whole days until the trial ends; negative once it has. Null with no trial. */
    public function trialDaysLeft(): ?int
    {
        if (! $this->trial_ends_at) {
            return null;
        }

        return (int) floor(now()->startOfDay()->diffInDays($this->trial_ends_at->startOfDay(), false));
    }

    /** B4: has this stage already been sent for this install? */
    public function trialReminderSent(string $stage): bool
    {
        return isset(($this->trial_reminders ?? [])[$stage]);
    }

    /** B4: stamp a stage as sent. Idempotent by construction. */
    public function stampTrialReminder(string $stage): void
    {
        $stamps = $this->trial_reminders ?? [];
        $stamps[$stage] = now()->toIso8601String();
        $this->update(['trial_reminders' => $stamps]);
    }

    /** @return BelongsTo<App, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function webhookEndpoint()
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
