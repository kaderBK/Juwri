<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Juwri\Core\Services\AbuseGuard;
use App\Services\QueueGuard;
use App\Services\SignupGuard;
use Juwri\Modules\Media\Services\StorageQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Abuse Guard P8 (8.1): warn-mode smoke — with DEFAULT settings (no admin config),
 * every guard defaults to WARN and blocks nothing. This is the ship-safety net:
 * turning the feature on must never lock out a real user on day one.
 */
class AbuseWarnDefaultsSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_guard_defaults_to_warn(): void
    {
        foreach (['ai', 'export', 'upload', 'public_write', 'signup', 'webhook_in', 'mcp', 'ai_spend', 'queue', 'storage', 'branch', 'hold'] as $g) {
            $this->assertSame(AbuseGuard::WARN, AbuseGuard::mode($g), "guard {$g} should default to warn");
        }
    }

    public function test_guards_allow_under_defaults(): void
    {
        // Rate/generic guard: hit() allows in warn.
        $this->assertTrue(AbuseGuard::hit('ai', 'probe'));

        // Queue quota: far below any default cap.
        $this->assertTrue(QueueGuard::allow(123));

        // Storage: a tiny upload is well under the 500MB default.
        $this->assertTrue(StorageQuota::allow(123, 1024));

        // Signup: a normal email passes with no throw under warn defaults.
        $req = Request::create('/signup', 'POST', []);
        SignupGuard::assertAllowed($req, 'real.person@gmail.com');
        $this->assertTrue(true);
    }
}
