<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

/**
 * S8 sub-1 — shared password policy. Off (default) keeps the historical min-6;
 * on (PASSWORD_POLICY_STRICT) demands 10+ mixed-case-with-number. Asserted on
 * the shared Password::defaults() rule the register/reset/staff forms all use.
 */
class PasswordPolicyTest extends TestCase
{
    private function passes(string $password): bool
    {
        return Validator::make(
            ['password' => $password],
            ['password' => ['required', Password::defaults()]]
        )->passes();
    }

    public function test_lenient_default_accepts_six_chars_and_rejects_shorter(): void
    {
        config()->set('auth.password_policy_strict', false);

        $this->assertTrue($this->passes('abcdef'), '6 chars should pass when lenient');
        $this->assertFalse($this->passes('abc'), 'under 6 chars must fail');
    }

    public function test_strict_policy_rejects_a_weak_password(): void
    {
        // Http::fake so the uncompromised() check can never make a real network
        // call even if a case reaches it (short passwords short-circuit before it).
        Http::fake();
        config()->set('auth.password_policy_strict', true);

        $this->assertFalse($this->passes('Ab1cdef'), 'a 7-char password must fail the strict 10-char minimum');
        $this->assertFalse($this->passes('alllowercasenocaps'), 'no upper-case / number must fail strict');
    }
}
