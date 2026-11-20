<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\AdminImpersonationGuard;

/**
 * The route names an impersonating session may not use (R1 RP9a spec §1 guard list + WI-H2 families). Single source
 * of truth for the reseller→customer impersonation guard (Platform, ee), the admin log-in-as guard
 * ({@see AdminImpersonationGuard}) and owner views that disable guarded controls.
 * P1-13: moved out of Platform's ImpersonationGuard so Community code reads it from Core.
 */
final class ImpersonationRoutes
{
    /** Route names blocked while impersonating (spec §1 guard list). */
    private const GUARDED = [
        'settings.editProfile',   // email + password change
        'settings.editBilling',   // payment methods
        'subscription.cancel',
        'reseller-subscription.cancel', // R13c: a reseller customer's own cancel of their reseller subscription
        'checkout.index',
        'checkout.submit',        // the plan checkout POST (named so it can be guarded)
        'wallet.topup.pay',       // platform money: wallet top-up, add-on / credit-pack purchase
        'wallet.withdraw', 'wallet.redeem', // sec-w1 #2: cash-out debits the wallet; promo redeem mutates it
        'addons.pay',
        'addons.trial', 'addons.cancel', // sec-w1 #7: consumes the one-time trial / cancels a paid add-on
        'subscription.scheduleSwitch', 'subscription.cancelSwitch', 'subscription.renewNow', // sec-w1 #7: plan changes, wallet debit
        'partner.sandbox', 'partner.reviews.reply', // sec-w1 #10: creates a Store / replies as the customer
        'credits.pay',
        'payment.pay',
        // SEC-1119 review MUST 1: theme / app purchases debit the wallet. marketplace.apply stays open on purpose: it
        // switches the storefront theme (the impersonator's support job), it moves no money.
        'marketplace.buy', 'apps.install',
        '2fa.show', '2fa.enable', '2fa.confirm', '2fa.disable',
        '2fa.recovery', '2fa.recovery.regenerate',
        'reseller.customers.impersonate', // nested impersonation
        'verification.verify', 'verification.resend', // R7b: an impersonator must not verify a reseller-set email
        'social.connect', 'social.disconnect', 'social.callback', // R7b: nor link its own social identity to the account
    ];

    /**
     * WI-H2 — whole route-name FAMILIES that are off-limits while impersonating.
     * Deny-by-family (not by exact name) so a route added to one of these namespaces
     * later is blocked without touching this file. Matched with Str::startsWith.
     */
    private const GUARDED_PREFIXES = [
        'transfer.',            // store/branch ownership transfer: offer, accept, decline, cancel, move
        'api.token.',           // platform API tokens (password.confirm does NOT cover this — see WI-H2 §1e)
        'api.webhook.',         // webhook create/delete/toggle/test/redeliver
        'storefront.token.',    // per-restaurant public storefront API tokens
        'passkeys.',            // P1-19: adding/renaming/removing/confirming the account's passkeys
        'staff.passkeys.',      // P1-19b: staff passkeys (owner revoke + staff self-service)
        'passport.',            // R7b: OAuth approve/deny/refresh must not mint tokens for the customer
        'partner.apps.',        // R7b: an impersonator must not create/rotate its own OAuth clients
        'partner.themes.',      // sec-w1 #10: theme listing submit/edit/screenshots
        'wallet.stripe.',       // sec-w1 #2: Stripe Express payout onboarding (connect + the syncing return leg)
        'reseller-access.',     // reseller rework v2: the store's consent to support login / its reseller's access — the store's own decision
        'user.store.',          // SEC-1119 review MUST 1: in-dashboard store buy/install (wallet debit), buy-card + topup (gateway charge)
    ];

    /**
     * Read-only names inside a guarded family that must still render, so the page can
     * show WHY its controls are disabled instead of returning a bare 403.
     */
    private const GUARDED_EXCEPT = [
        'transfer.index',
        'reseller-access.index',
        'user.store.apps', 'user.store.apps.show',     // browse-only store pages (GET)
        'user.store.themes', 'user.store.themes.show',
    ];

    /** Is this route name off-limits while impersonating? */
    public static function blocks(?string $routeName): bool
    {
        if ($routeName === null || $routeName === '') {
            return false;
        }

        if (in_array($routeName, self::GUARDED_EXCEPT, true)) {
            return false;
        }

        if (in_array($routeName, self::GUARDED, true)) {
            return true;
        }

        return Str::startsWith($routeName, self::GUARDED_PREFIXES);
    }

    /** True when THIS request is inside a (reseller) impersonation session and $routeName is blocked. */
    public static function blocksNow(?string $routeName): bool
    {
        return is_array(session('impersonating')) && self::blocks($routeName);
    }
}
