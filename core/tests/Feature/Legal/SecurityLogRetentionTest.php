<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Legal;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Models\LoginAudit;
use Tests\TestCase;

/** Privacy notice §8: security and access logs are kept 12 months (VERIFY-CLAIMS). */
class SecurityLogRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sign_in_records_older_than_twelve_months_are_deleted_and_newer_kept(): void
    {
        $user = User::factory()->create();
        $old = LoginAudit::create(['user_id' => $user->id, 'ip' => '10.0.0.1', 'user_agent' => 'x', 'device_hash' => 'a', 'new_device' => true, 'created_at' => now()->subDays(366)]);
        $recent = LoginAudit::create(['user_id' => $user->id, 'ip' => '10.0.0.2', 'user_agent' => 'x', 'device_hash' => 'b', 'new_device' => false, 'created_at' => now()->subDays(364)]);

        $this->artisan('accounts:prune-security-logs')->assertSuccessful();

        $this->assertNull(LoginAudit::find($old->id));
        $this->assertNotNull(LoginAudit::find($recent->id));
    }
}
