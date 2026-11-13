<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content\Studio;

use Juwri\Modules\Content\Models\StudioCreditLedger;
use Juwri\Modules\Content\Models\StudioSession;
use Juwri\Modules\Content\Services\Studio\StudioCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * W1-CREDIT — the credit ledger becomes account-scoped for owners while guests
 * stay exactly session-scoped. Balance/debit/dedupe span all of an owner's
 * sessions; a guest session's rows carry no user_id and are isolated.
 */
class StudioCreditAccountScopeTest extends TestCase
{
    use RefreshDatabase;

    private function guestSession(): StudioSession
    {
        return StudioSession::create([
            'guest_token' => Str::random(48),
            'expires_at' => now()->addHours(48),
        ]);
    }

    private function ownedSession(int $userId): StudioSession
    {
        return StudioSession::create([
            'guest_token' => Str::random(48),
            'user_id' => $userId,
            'expires_at' => now()->addHours(48),
        ]);
    }

    /** Guest behavior is unchanged: session-scoped balance, ref-dedupe, debit guard, isolation. */
    public function test_guest_balance_and_debit_are_session_scoped_as_before(): void
    {
        $svc = new StudioCreditService;
        $s = $this->guestSession();
        $other = $this->guestSession();

        $svc->credit($s, 10, 'topup', 'g-1');
        $svc->credit($s, 10, 'topup', 'g-1'); // replay -> no-op
        $svc->credit($s, 5, 'topup', 'g-2');

        $this->assertSame(15, $svc->balance($s));
        $this->assertSame(2, StudioCreditLedger::where('studio_session_id', $s->id)->count());
        $this->assertSame(0, $svc->balance($other)); // isolated from other guests

        $this->assertTrue($svc->debit($s, 5, 'pdf'));
        $this->assertSame(10, $svc->balance($s));
        $this->assertFalse($svc->debit($s, 100, 'pdf')); // insufficient -> no row
        $this->assertSame(10, $svc->balance($s));

        // New guest rows never carry a user_id.
        $this->assertSame(0, StudioCreditLedger::where('studio_session_id', $s->id)->whereNotNull('user_id')->count());
    }

    /** An owner sees the SUM across every session they own. */
    public function test_owner_balance_sums_across_all_their_sessions(): void
    {
        $svc = new StudioCreditService;
        $userId = 4242;
        $a = $this->ownedSession($userId);
        $b = $this->ownedSession($userId);

        $svc->credit($a, 30, 'topup');
        $svc->credit($b, 20, 'topup');

        $this->assertSame(50, $svc->balance($a));
        $this->assertSame(50, $svc->balance($b));

        // Rows written on an owned session stamp the user_id.
        $this->assertSame(2, StudioCreditLedger::where('user_id', $userId)->count());
    }

    /** A debit draws down the whole account, not just the session it runs on. */
    public function test_debit_spends_across_the_account(): void
    {
        $svc = new StudioCreditService;
        $userId = 4243;
        $a = $this->ownedSession($userId);
        $b = $this->ownedSession($userId);

        $svc->credit($a, 30, 'topup');
        $svc->credit($b, 20, 'topup'); // account balance = 50

        $this->assertTrue($svc->debit($b, 40, 'pdf')); // spends across a+b
        $this->assertSame(10, $svc->balance($a));
        $this->assertSame(10, $svc->balance($b));

        $this->assertFalse($svc->debit($a, 20, 'pdf')); // only 10 left account-wide
        $this->assertSame(10, $svc->balance($b));
    }

    /** Ref-dedupe spans the account: a re-delivered event on another session is a no-op. */
    public function test_credit_ref_dedupe_holds_across_owner_sessions(): void
    {
        $svc = new StudioCreditService;
        $userId = 4244;
        $a = $this->ownedSession($userId);
        $b = $this->ownedSession($userId);

        $svc->credit($a, 30, 'bundle', 'stripe-evt-9');
        $svc->credit($b, 30, 'bundle', 'stripe-evt-9'); // same event, different session -> ignored

        $this->assertSame(30, $svc->balance($a));
        $this->assertSame(1, StudioCreditLedger::where('user_id', $userId)->where('ref', 'stripe-evt-9')->count());
    }

    /** transferSessionToUser backfills a session's rows onto the account, idempotently. */
    public function test_transfer_session_to_user_moves_rows_idempotently(): void
    {
        $svc = new StudioCreditService;
        $userId = 4245;
        $g = $this->guestSession();

        $svc->credit($g, 25, 'topup', 'g-25');
        $this->assertSame(1, StudioCreditLedger::where('studio_session_id', $g->id)->whereNull('user_id')->count());

        $svc->transferSessionToUser($g, $userId);
        $this->assertSame(0, StudioCreditLedger::where('studio_session_id', $g->id)->whereNull('user_id')->count());
        $this->assertSame(1, StudioCreditLedger::where('studio_session_id', $g->id)->where('user_id', $userId)->count());

        // Idempotent: a second call moves nothing new.
        $svc->transferSessionToUser($g, $userId);
        $this->assertSame(1, StudioCreditLedger::where('user_id', $userId)->count());

        // The credits now belong to the account — a fresh owned session sees them.
        $owned = $this->ownedSession($userId);
        $this->assertSame(25, $svc->balance($owned));
    }
}
