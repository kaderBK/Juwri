<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\Studio;

use Juwri\Modules\Content\Models\StudioSession;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Ownership overlay for Menu Studio sessions. ONE place decides which session a
 * request belongs to so the controller and the auth listener can share the
 * logic without editing each other's files.
 *
 * Design: one session model, ownership overlay. Guests stay cookie-scoped with
 * a 48h TTL (unchanged); an authed request binds to the account and its owned
 * sessions never purge. A signed-in user may CLAIM only an UNOWNED session — a
 * session owned by another user is never reassigned and never handed back to a
 * different user.
 */
class StudioSessionService
{
    /** Cookie that names the current guest session (mirrors StudioController). */
    private const GUEST_COOKIE = 'studio_guest';

    /** Guest session lifetime; owned sessions don't purge. */
    private const TTL_HOURS = 48;

    /**
     * The session this request belongs to.
     * - authed: resume the owner's latest live (non-published, unexpired) session;
     *   else claim an unowned cookie session; else a fresh session stamped user_id.
     * - guest ($userId === null): cookie session, else fresh guest session.
     * NEVER returns a session owned by a different user.
     */
    public function resolve(Request $request, ?int $userId): StudioSession
    {
        $token = $request->cookie(self::GUEST_COOKIE);

        if ($userId !== null) {
            // 1. The owner's own session wins, across cookie clears + devices.
            if ($owned = $this->ownedLiveSession($userId)) {
                return $owned;
            }

            // 2. Convert an UNOWNED cookie session (guest -> owner keeps their work).
            //    A cookie session owned by someone else is skipped (never returned).
            if (is_string($token) && $token !== '') {
                $existing = StudioSession::where('guest_token', $token)->first();
                if ($existing && ! $existing->isExpired() && $existing->user_id === null) {
                    $this->claimForUser($existing, $userId);

                    return $existing;
                }
            }

            // 3. Nothing to resume/claim: mint a fresh session already stamped.
            return $this->freshSession($userId);
        }

        // Guest: cookie session, else fresh guest session (behavior unchanged).
        if (is_string($token) && $token !== '') {
            $existing = StudioSession::where('guest_token', $token)->first();
            if ($existing && ! $existing->isExpired()) {
                return $existing;
            }
        }

        return $this->freshSession(null);
    }

    /** Latest non-published, unexpired session owned by $userId, or null. */
    public function ownedLiveSession(int $userId): ?StudioSession
    {
        return StudioSession::where('user_id', $userId)
            ->where('status', '!=', 'published')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Idempotent claim. If $session->user_id is null: set user_id, transfer the
     * session's credit ledger to the user (StudioCreditService::transferSessionToUser),
     * and clear expiry (owned sessions don't purge). No-op if already owned
     * (by anyone) — never reassigns an owned session.
     */
    public function claimForUser(StudioSession $session, int $userId): void
    {
        if ($session->user_id !== null) {
            return; // absent-owner only; never reassign a claimed session
        }

        $session->user_id = $userId;
        $session->expires_at = null; // owned sessions don't purge
        $session->save();

        // Carry paid credits over to the account (moves this session's ledger rows).
        app(StudioCreditService::class)->transferSessionToUser($session, $userId);
    }

    /**
     * Resume-by-token with ownership rules (used by landingVariant):
     * restore the session named by $guestToken; if unowned AND $userId, claim it;
     * if owned by someone else, return it read-as-is WITHOUT reassigning. null if
     * missing/expired.
     */
    public function resumeByToken(?string $guestToken, ?int $userId): ?StudioSession
    {
        if (! is_string($guestToken) || $guestToken === '') {
            return null;
        }

        $session = StudioSession::where('guest_token', $guestToken)->first();
        if (! $session || $session->isExpired()) {
            return null;
        }

        if ($session->user_id === null && $userId !== null) {
            $this->claimForUser($session, $userId);
        }

        return $session;
    }

    /**
     * Mint a new session. A guest ($userId === null) gets the 48h TTL; an owner's
     * fresh session is stamped and never purges. Mirrors StudioController's mint
     * (welcome credits included) so the guest funnel is unchanged.
     */
    private function freshSession(?int $userId): StudioSession
    {
        $session = StudioSession::create([
            'guest_token' => Str::random(48),
            'status' => 'exploring',
            'locale' => app()->getLocale(),
            'user_id' => $userId,
            'expires_at' => $userId === null ? now()->addHours(self::TTL_HOURS) : null,
        ]);

        // Optional welcome credits (config-gated, default 0 = off), same as the
        // controller. Idempotent per session via the ledger ref.
        $welcome = (int) config('studio.welcome_credits', 0);
        if ($welcome > 0) {
            app(StudioCreditService::class)->credit($session, $welcome, 'welcome', 'welcome');
        }

        return $session;
    }
}
