<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Models\WebhookDelivery;

class WebhookEndpoint extends Model
{
    protected $guarded = [];
    protected $casts = [
        'events' => 'array',
        'active' => 'boolean',
        'consecutive_failures' => 'integer',
    ];
    protected $hidden = ['secret'];

    /** Auto-disable an endpoint after this many consecutive failed deliveries. */
    public const MAX_CONSECUTIVE_FAILURES = 15;

    /**
     * A4: single source of truth for the topics a merchant may subscribe to. Every one
     * is actually fired somewhere in the app (order.*, menu.updated, entitlement.changed,
     * the subscription lifecycle family, app.uninstalled), plus `*` for all events.
     */
    public const SELECTABLE_EVENTS = [
        '*',
        'order.created',
        'order.status_changed',
        'order.paid',
        // HPMS E10: refund / reservation / customer / shift lifecycle events.
        'refund.completed',
        'reservation.created',
        'reservation.cancelled',
        'customer.created',
        'shift.opened',
        'shift.closed',
        'menu.updated',
        'entitlement.changed',
        'subscription.paused',
        'subscription.resumed',
        'subscription.renewed',
        'subscription.expired',
        'subscription.plan_changed',
        'subscription.past_due',
        'subscription.expiring',
        'subscription.trial_ending',
        'app.uninstalled',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record the outcome of a delivery attempt. A success resets the failure
     * streak; a failure increments it and auto-disables the endpoint once it
     * crosses MAX_CONSECUTIVE_FAILURES (so a dead URL stops being retried forever).
     */
    public function recordDeliveryResult(bool $success, ?string $failReason = null): void
    {
        if ($success) {
            if ($this->consecutive_failures !== 0) {
                $this->forceFill(['consecutive_failures' => 0])->saveQuietly();
            }
            return;
        }

        $count = (int) $this->consecutive_failures + 1;
        $updates = ['consecutive_failures' => $count];

        if ($count >= self::MAX_CONSECUTIVE_FAILURES && $this->active) {
            $updates['active'] = false;
            $updates['disabled_reason'] = $failReason
                ?: "Auto-disabled after {$count} consecutive delivery failures.";
        }
        $this->forceFill($updates)->saveQuietly();

        /* Polish (P5-7 deferral — enforcement-ladder WARN step): if the endpoint
           that just auto-disabled belongs to a marketplace app installation,
           mail the app's DEVELOPER once (the disable transition fires once). */
        if (($updates['active'] ?? true) === false) {
            $this->warnAppDeveloperOfDeadWebhook();
        }
    }

    /** One-shot developer warning when an app install's webhook goes dead. */
    protected function warnAppDeveloperOfDeadWebhook(): void
    {
        try {
            $installation = \App\Models\AppInstallation::where('webhook_endpoint_id', $this->id)->first();
            $app = $installation ? \App\Models\App::find($installation->app_id) : null;
            $developer = $app ? User::find($app->developer_user_id) : null;
            if (! $developer || empty($developer->email)) {
                return;
            }

            \Illuminate\Support\Facades\Mail::to($developer->email)->queue(new \Juwri\Modules\AppStore\Mail\AppDunningMail(
                ___('Your app :app has a failing webhook endpoint', ['app' => $app->name]),
                [
                    ___('Deliveries to :url failed :count times in a row and the endpoint has been disabled.', ['url' => (string) $this->url, 'count' => self::MAX_CONSECUTIVE_FAILURES]),
                    ___('Your app will no longer receive events for this workspace until the endpoint is fixed. Repeated failures across installs can lead to delisting.'),
                ],
            ));
        } catch (\Throwable $e) {
            report($e); // warning mail must never break delivery bookkeeping
        }
    }

    public function deliveries()
    {
        return $this->hasMany(WebhookDelivery::class, 'endpoint_id');
    }

    public function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret ?? '');
    }

    /**
     * Standard-Webhooks signature: HMAC-SHA256 over `{id}.{timestamp}.{body}`,
     * base64-encoded. Binding the id + timestamp into the signed content is what
     * makes a captured payload safe against replay. Sent as the `webhook-signature`
     * header (prefixed `v1,`); receivers reject requests whose `webhook-timestamp`
     * is outside a small tolerance (e.g. 5 minutes).
     */
    public function signStandard(string $id, int $timestamp, string $body): string
    {
        $signed = $id . '.' . $timestamp . '.' . $body;
        return base64_encode(hash_hmac('sha256', $signed, $this->secret ?? '', true));
    }
}
