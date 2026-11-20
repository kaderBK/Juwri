<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CouponCheckThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);

        Route::middleware(['web', 'coupon.check:storefront'])->post('/_test/coupon-check/storefront', function (Request $request) {
            $code = $request->input('coupon_code');
            if ($code === 'VALID') {
                return response()->json(['ok' => true, 'valid' => true]);
            }
            if ($code === 'NO_COUPON') {
                return response()->json(['ok' => true]);
            }

            return response()->json(['ok' => false, 'valid' => false], 422);
        });

        Route::middleware(['web', 'coupon.check:pos'])->post('/_test/coupon-check/pos', function (Request $request) {
            if ($request->input('coupon_code') === 'VALID') {
                return response()->json(['ok' => true, 'valid' => true]);
            }

            return response()->json(['ok' => false, 'valid' => false], 422);
        });
    }

    public function test_valid_codes_are_never_throttled(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->postJson('/_test/coupon-check/storefront', ['coupon_code' => 'VALID'])
                ->assertOk()
                ->assertJson(['valid' => true]);
        }

        $this->assertSame(0, RateLimiter::attempts('coupon-check:storefront:ip:127.0.0.1:60'));
    }

    public function test_eleventh_failed_attempt_returns_429_with_retry_after_and_json_body(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/_test/coupon-check/storefront', ['coupon_code' => 'BAD'.$i])
                ->assertStatus(422);
        }

        $key = 'coupon-check:storefront:ip:127.0.0.1:60';
        $this->assertStringStartsWith('coupon-check:', $key);
        $this->assertSame(10, RateLimiter::attempts($key));

        $res = $this->postJson('/_test/coupon-check/storefront', ['coupon_code' => 'BAD10']);
        $res->assertStatus(429);
        $res->assertHeader('Retry-After');
        $res->assertJson([
            'ok' => false,
            'valid' => false,
            'success' => false,
        ]);
        $this->assertNotEmpty($res->json('error'));
        $this->assertNotEmpty($res->json('message'));
    }

    public function test_non_json_eleventh_failed_attempt_returns_429_with_retry_after(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post('/_test/coupon-check/storefront', ['coupon_code' => 'BAD'.$i])
                ->assertStatus(422);
        }

        $res = $this->post('/_test/coupon-check/storefront', ['coupon_code' => 'BAD10']);
        $res->assertStatus(429);
        $res->assertHeader('Retry-After');
    }

    public function test_requests_without_coupon_field_are_untouched(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/_test/coupon-check/storefront', ['coupon_code' => 'BAD'.$i])
                ->assertStatus(422);
        }

        // Even though storefront is now throttled for coupon checks, a request without a coupon field passes through.
        $this->postJson('/_test/coupon-check/storefront', ['note' => 'plain checkout'])
            ->assertStatus(422);

        $this->postJson('/_test/coupon-check/storefront', ['coupon_code' => '   '])
            ->assertStatus(422);
    }

    public function test_surfaces_have_separate_buckets(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/_test/coupon-check/storefront', ['coupon_code' => 'BAD'.$i])
                ->assertStatus(422);
        }

        $this->postJson('/_test/coupon-check/storefront', ['coupon_code' => 'BAD10'])
            ->assertStatus(429);

        // POS surface has its own bucket and is unaffected.
        $this->postJson('/_test/coupon-check/pos', ['coupon_code' => 'VALID'])
            ->assertOk()
            ->assertJson(['valid' => true]);
        $this->assertSame(0, RateLimiter::attempts('coupon-check:pos:ip:127.0.0.1:60'));
    }
}
