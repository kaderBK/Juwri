<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Models\StudioCreditLedger;
use Juwri\Modules\Content\Models\StudioSession;
use Juwri\Modules\Content\Services\Studio\InsufficientCreditsException;
use Juwri\Modules\Content\Services\Studio\StudioCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * S4-P4: studio credit ledger — server-side balance, atomic debit guard, refund.
 */
class StudioCreditTest extends TestCase
{
    use RefreshDatabase;

    private function mkSession(): StudioSession
    {
        return StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);
    }

    public function test_credit_adds_balance_and_is_idempotent_by_ref(): void
    {
        $svc = new StudioCreditService();
        $s = $this->mkSession();

        $svc->credit($s, 10, 'topup', 'txn-1');
        $svc->credit($s, 10, 'topup', 'txn-1'); // replay -> no-op
        $svc->credit($s, 5, 'topup', 'txn-2');

        $this->assertSame(15, $svc->balance($s));
        $this->assertSame(2, StudioCreditLedger::where('studio_session_id', $s->id)->count());
    }

    public function test_debit_respects_balance(): void
    {
        $svc = new StudioCreditService();
        $s = $this->mkSession();
        $svc->credit($s, 8, 'topup');

        $this->assertTrue($svc->debit($s, 5, 'pdf'));   // within balance
        $this->assertSame(3, $svc->balance($s));

        $this->assertFalse($svc->debit($s, 10, 'pdf')); // insufficient -> blocked
        $this->assertSame(3, $svc->balance($s));        // unchanged, no row written
    }

    public function test_spend_runs_action_and_debits(): void
    {
        $svc = new StudioCreditService();
        $s = $this->mkSession();
        $svc->credit($s, 6, 'topup');

        $out = $svc->spend($s, 4, 'gen', fn () => 'image-bytes');

        $this->assertSame('image-bytes', $out);
        $this->assertSame(2, $svc->balance($s));
    }

    public function test_spend_refunds_on_failure(): void
    {
        $svc = new StudioCreditService();
        $s = $this->mkSession();
        $svc->credit($s, 6, 'topup');

        try {
            $svc->spend($s, 4, 'gen', function () { throw new \RuntimeException('provider down'); });
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('provider down', $e->getMessage());
        }

        $this->assertSame(6, $svc->balance($s)); // debit refunded
    }

    public function test_spend_blocks_when_insufficient(): void
    {
        $svc = new StudioCreditService();
        $s = $this->mkSession();
        $svc->credit($s, 2, 'topup');

        $this->expectException(InsufficientCreditsException::class);
        $svc->spend($s, 5, 'gen', fn () => 'never');
    }
}
