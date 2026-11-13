<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Models\App;
use App\Models\AppInstallation;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\User;
use App\Models\WebhookEndpoint;
use Laravel\Passport\Token;
use RuntimeException;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\AppStore\Services\AppBillingService;
use Juwri\Core\Contracts\Billing\FeatureCommerce;

/**
 * P3-2: the install/uninstall lifecycle. Install grants the app's requested
 * scopes and wires its webhook subscription through the EXISTING #99 delivery
 * rails (a per-install WebhookEndpoint → signed, queued, retried). Uninstall
 * revokes every OAuth token the app holds for this tenant, fires
 * `app.uninstalled` (so the partner cleans up), and deactivates delivery.
 */
class AppInstallService
{
    /**
     * @param bool $prepaid P4-3 (feature-platform, D1): the buyer already paid
     *        this period by CARD through the Transaction/updateUserPlan choke
     *        point — skip the wallet charge AND the trial (they chose to pay);
     *        recurring apps get their period stamped here and renew from the
     *        wallet afterwards.
     */
    public function install(App $app, User $user, bool $prepaid = false): AppInstallation
    {
        if (! $app->isInstallable()) {
            throw new RuntimeException('App is not approved for installation.');
        }

        /* M0-3 (monetization): a bundle may own an `apps` row purely as a
           LISTING SHELL — the store renders the listing while its Buy CTA
           routes to add-on checkout. Installing that shell through this rail
           would grant the bundle's features on app semantics (trial, install
           lifecycle, no reseller commission) instead of add-on semantics, and
           for free, since the shell carries no price. One rail per product;
           this is the refusal that keeps it to one. */
        if (app(FeatureCommerce::class)->bundlesApp((int) $app->id)) {
            throw new RuntimeException('This listing is a bundle — it is purchased through add-on checkout, not installed.');
        }

        $endpointId = null;
        if ($app->webhook_url) {
            $endpoint = WebhookEndpoint::create([
                'user_id' => $user->id,
                'url' => $app->webhook_url,
                'secret' => $app->webhook_secret,
                'events' => array_values(array_unique(array_merge(
                    $app->webhook_events ?? [],
                    ['app.uninstalled'], // always subscribed — partners must see uninstalls
                ))),
                'active' => true,
            ]);
            $endpointId = $endpoint->id;
        }

        // Reinstall reactivates the audit row rather than duplicating it.
        $installation = AppInstallation::firstOrNew(['app_id' => $app->id, 'user_id' => $user->id]);
        $wasNew = ! $installation->exists;
        $previousStatus = $installation->status;
        $installation->fill([
            'granted_scopes' => $app->scopes,
            'webhook_endpoint_id' => $endpointId,
            'status' => 'active',
            'installed_at' => now(),
            'uninstalled_at' => null,
        ])->save();

        /* P4-4 (feature-platform): free trial — first-ever install of a recurring
           app with trial_days starts a trial instead of charging. The trial end
           doubles as the first due date, so renewDue() converts (charges) at
           expiry with no extra machinery. Reinstalls never get a second trial. */
        if ($prepaid) {
            // Card-paid period (P4-3): stamp the period; renewals ride the wallet.
            if ($app->billing_type === 'recurring') {
                $installation->update(['current_period_ends_at' => now()->addDays($app->billing_period_days), 'trial_ends_at' => null, 'grace_period_ends_at' => null]);
            }
        } elseif ($wasNew && $app->billing_type === 'recurring' && $app->trial_days > 0 && $app->price > 0) {
            $installation->update([
                'trial_ends_at' => now()->addDays($app->trial_days),
                'current_period_ends_at' => now()->addDays($app->trial_days),
            ]);
        } else {
            // P3-4: paid apps charge through the wallet choke point. A failed
            // charge aborts the install cleanly.
            try {
                app(AppBillingService::class)->chargeInstall($app, $installation);
            } catch (\Throwable $e) {
                $wasNew
                    ? $installation->delete()
                    : $installation->update(['status' => $previousStatus ?? 'uninstalled']);
                throw new RuntimeException('Install payment failed: '.$e->getMessage(), 0, $e);
            }
        }

        // P4-2 (feature-platform): an active installation grants the app's
        // feature keys (admin-curated grants_features, D9) via EntitlementWriter
        // overrides — revoked on uninstall/suspend, re-granted here on
        // reinstall/reactivation.
        $this->syncFeatureGrants($installation->fresh());

        return $installation;
    }

    /**
     * Grant/revoke the app's grants_features to match the installation state.
     *
     * F1-4 (feature-platform gap-fix): this is a DIFF, not a one-way grant. The
     * app's CURRENT grants_features is the authority; any override this
     * installation previously wrote for a key that is no longer in the set (an
     * app published a v2 that dropped it) is revoked. Without the diff a
     * dropped key kept granting forever — and reconcile never noticed, because
     * the installation was still perfectly active.
     */
    public function syncFeatureGrants(AppInstallation $installation): void
    {
        $app = $installation->app ?? App::find($installation->app_id);
        if (! $app) {
            return;
        }
        $keys = array_values(array_unique(array_filter(array_map(
            'strval',
            (array) ($app->grants_features ?? []),
        ), 'strlen')));

        $user = User::find($installation->user_id);
        if (! $user) {
            return;
        }

        $isActive = $installation->status === 'active';

        // Keys this installation has already written an override for. Anything
        // here that is NOT in the current key set is stale and must go, whether
        // or not the installation is still active.
        $granted = EntitlementOverride::query()
            ->where('user_id', $user->id)
            ->where('source_type', 'app_installation')
            ->where('source_id', $installation->id)
            ->pluck('feature_key')
            ->map('strval')
            ->unique()
            ->all();

        $stale = array_values(array_diff($granted, $keys));

        // Inactive installs revoke everything (current + stale) — same as before.
        $toRevoke = $isActive ? $stale : array_values(array_unique(array_merge($granted, $keys)));
        $toGrant = $isActive ? $keys : [];

        foreach ($toGrant as $key) {
            try {
                EntitlementWriter::grant($user, $key, 'app_installation', $installation->id, '1', null, 'app: '.$app->slug);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[app-grants] sync failed for '.$key.': '.$e->getMessage());
            }
        }

        foreach ($toRevoke as $key) {
            $reason = $isActive
                ? 'app no longer grants this feature: '.$app->slug
                : 'app '.$installation->status.': '.$app->slug;
            try {
                EntitlementWriter::revoke($user, $key, 'app_installation', $installation->id, $reason);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[app-grants] sync failed for '.$key.': '.$e->getMessage());
            }
        }
    }

    public function uninstall(AppInstallation $installation): void
    {
        $app = $installation->app;

        // 1. Revoke every token (access + its refresh chain) the app's OAuth
        //    client holds for this tenant — the partner loses API access NOW.
        if ($app->oauth_client_id) {
            Token::where('client_id', $app->oauth_client_id)
                ->where('user_id', $installation->user_id)
                ->get()
                ->each(function (Token $token) {
                    $token->revoke();
                    \Illuminate\Support\Facades\DB::table('oauth_refresh_tokens')
                        ->where('access_token_id', $token->id)
                        ->update(['revoked' => true]);
                });
        }

        // 2. Tell the partner (through the signed/retried delivery rails).
        //    The endpoint must stay ACTIVE while the queued delivery (and its
        //    retries) run — DeliverWebhookJob re-checks `active` at handle
        //    time. So instead of deactivating we empty its subscription: the
        //    in-flight app.uninstalled delivery completes, and no future event
        //    can ever match again.
        if ($installation->webhook_endpoint_id) {
            WebhookService::dispatch($installation->user_id, 'app.uninstalled', [
                'app' => $app->slug,
                'user_id' => $installation->user_id,
                'uninstalled_at' => now()->toIso8601String(),
            ]);

            WebhookEndpoint::where('id', $installation->webhook_endpoint_id)
                ->update(['events' => json_encode([]), 'disabled_reason' => 'app uninstalled']);
        }

        // 3. Keep the row as the audit trail.
        $installation->update([
            'status' => 'uninstalled',
            'uninstalled_at' => now(),
        ]);

        // P4-2: uninstall revokes the app's feature grants.
        $this->syncFeatureGrants($installation->fresh());
    }
}
