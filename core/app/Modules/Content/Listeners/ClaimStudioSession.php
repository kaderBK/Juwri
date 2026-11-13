<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Listeners;

use Juwri\Modules\Content\Models\StudioSession;
use Juwri\Modules\Content\Services\Studio\StudioSessionService;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;

/**
 * W2-LISTENER — auto-claim a guest Menu Studio session onto the account when a
 * visitor authenticates, so a guest → owner conversion keeps their session,
 * draft and paid credits (SPEC §1/§5.4).
 *
 * Wired to BOTH Illuminate\Auth\Events\Registered AND Illuminate\Auth\Events\Login
 * (see EventServiceProvider). CRITICAL: Login fires on EVERY request under
 * remember-me, so this handler is deliberately cheap + idempotent — it early
 * returns unless the request carries an UNOWNED `studio_guest` cookie session.
 * The ownership mutation (stamp user_id, clear expiry, carry the credit ledger)
 * lives once in StudioSessionService::claimForUser(), which is itself idempotent
 * and never reassigns a session already owned by anyone.
 */
class ClaimStudioSession
{
    /**
     * @param  Registered|Login  $event
     */
    public function handle($event): void
    {
        $userId = $event->user->getAuthIdentifier();
        if (! $userId) {
            return;
        }

        // Only a live guest funnel carries this cookie; its absence is the common
        // remember-me case, so bail before touching the database.
        $token = request()->cookie('studio_guest');
        if (! is_string($token) || $token === '') {
            return;
        }

        $session = StudioSession::where('guest_token', $token)->first();
        if (! $session || $session->user_id !== null) {
            return; // absent, or already owned (by anyone) → no-op
        }

        app(StudioSessionService::class)->claimForUser($session, (int) $userId);
    }
}
