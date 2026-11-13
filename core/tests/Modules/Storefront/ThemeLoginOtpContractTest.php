<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Messaging\Data\OtpScope;
use Juwri\Modules\Messaging\Services\SmsOtpService;
use Juwri\Modules\Messaging\Services\SmsService;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Lane A / #1 (rev/theme-audit-followups): the storefront phone-OTP LOGIN
 * contract. The shipped bug was a theme's login JS posting the collected
 * one-time code under `otp:` while the backend
 * `CustomerAuthController::verifyOtp` reads `$request->code` — so login
 * silently 401'd. Every test at the time was server-side and missed it.
 *
 * This pins the contract from BOTH ends, deterministically and in-process
 * (no browser / Node), so it runs under `artisan test --parallel`:
 *
 *  1. Backend — a real HTTP round-trip through the controller proves the
 *     verify endpoint logs the customer in when the code is sent under
 *     `code`, and rejects it when the same code is sent under `otp`.
 *  2. Themes — every kept theme that ships a phone-OTP login sheet (its
 *     index blade emits the `customer.otp.verify` route) must build its
 *     verify-request body with a `code:` key and never smuggle the code
 *     under an `otp:` key — the exact regression.
 *
 * RESIDUAL GAP (documented; owner note): this is a static + backend
 * contract test, not a real headless-browser click-through. Laravel Dusk
 * is NOT installed and no chromedriver is available on the runners
 * (see [[ci-self-hosted-mac-runners]]), so a true DOM login e2e is out of
 * scope. Part 2 asserts the SOURCE of each theme's collect-and-post logic
 * rather than executing it in a browser. If Dusk is added later, port
 * Part 2 to a Browser test that fills the OTP boxes and asserts the
 * emitted network payload uses `code`.
 */
class ThemeLoginOtpContractTest extends TestCase
{
    use RefreshDatabase;

    /** The kept themes that ship a phone-OTP login sheet — now all of them (A3). */
    private const LOGIN_THEMES = KeptTemplates::MODERN;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function makeRestaurant(): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5, 'allow_ordering' => '1'], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Provision a known code into the real OTP cache (bypasses SMS send). */
    private function provisionCode(Store $store, string $phone, string $code): void
    {
        $norm = app(SmsService::class)->normalizePhone($phone);
        $key = app(SmsOtpService::class)->key(OtpScope::store((int) $store->id), $norm);
        Cache::put($key, ['hash' => Hash::make($code), 'attempts' => 0], 600);
    }

    // ───────────────────────── Part 1 · backend contract ─────────────────

    /** The code sent under `code` (what the fixed themes post) logs the customer in. */
    public function test_backend_verify_logs_in_when_code_key_is_used(): void
    {
        $r = $this->makeRestaurant();
        $phone = '+15550100200';
        $this->provisionCode($r, $phone, '135790');

        $this->postJson(route('customer.otp.verify', $r), [
            'phone' => $phone, 'code' => '135790', 'store_id' => $r->id,
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertAuthenticated('customer');
    }

    /** The SAME code sent under `otp` (the shipped bug) is rejected — never a login. */
    public function test_backend_verify_rejects_code_sent_under_otp_key(): void
    {
        $r = $this->makeRestaurant();
        $phone = '+15550100200';
        $this->provisionCode($r, $phone, '135790');

        $this->postJson(route('customer.otp.verify', $r), [
            'phone' => $phone, 'otp' => '135790', 'store_id' => $r->id,
        ])->assertStatus(422); // `code` is required → validation fails before verify

        $this->assertGuest('customer');
    }

    // ───────────────────────── Part 2 · theme JS contract ────────────────

    public static function keptThemeProvider(): array
    {
        return array_map(fn ($t) => [$t], KeptTemplates::MODERN);
    }

    /**
     * Extract each login-verify request body: the `otpVerify` / `otpVerifyUrl`
     * endpoint token (NOT the distinct account/checkout `otpVerify2` flow),
     * immediately followed by its request-body object literal — brace-matched
     * so a nested `{}` (e.g. `($('#otpPhone') || {}).value`) is captured whole.
     *
     * @return list<string>
     */
    private function loginVerifyBodies(string $js): array
    {
        $bodies = [];
        if (! preg_match_all('/otpVerify(?!2)\w*\s*,\s*\{/', $js, $m, PREG_OFFSET_CAPTURE)) {
            return $bodies;
        }
        $n = strlen($js);
        foreach ($m[0] as $hit) {
            $open = strpos($js, '{', $hit[1]);
            if ($open === false) {
                continue;
            }
            $depth = 0;
            for ($i = $open; $i < $n; $i++) {
                if ($js[$i] === '{') {
                    $depth++;
                } elseif ($js[$i] === '}') {
                    if (--$depth === 0) {
                        $bodies[] = substr($js, $open, $i - $open + 1);
                        break;
                    }
                }
            }
        }

        return $bodies;
    }

    /**
     * Every kept theme whose blade emits the login-verify route must build
     * its verify body with `code:` and never `otp:`. Themes without a login
     * sheet are skipped (nothing to contract) — and are auto-covered the
     * moment one gains a login sheet.
     */
    #[DataProvider('keptThemeProvider')]
    public function test_theme_login_verify_posts_code_not_otp(string $theme): void
    {
        $blade = resource_path("views/store_templates/{$theme}/index.blade.php");
        $this->assertFileExists($blade, "[$theme] index.blade.php missing");

        if (! str_contains(file_get_contents($blade), 'customer.otp.verify')) {
            $this->assertTrue(true, "[$theme] ships no phone-OTP login sheet — skipped");

            return;
        }

        $script = base_path("../assets/store_templates/{$theme}/js/script.js");
        $this->assertFileExists($script, "[$theme] script.js missing");
        $js = file_get_contents($script);

        $bodies = $this->loginVerifyBodies($js);

        $this->assertNotEmpty(
            $bodies,
            "[$theme] emits customer.otp.verify but no login-verify send body was found in script.js"
        );

        foreach ($bodies as $body) {
            $this->assertMatchesRegularExpression(
                '/\bcode\s*:/', $body,
                "[$theme] login-verify body must send the one-time code under `code:` — got: $body"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/\botp\s*:/', $body,
                "[$theme] login-verify body must NOT send the code under `otp:` (backend reads `code`) — got: $body"
            );
        }
    }

    /** Regression guard: none of the known login themes may quietly lose its sheet. */
    public function test_known_login_themes_still_ship_a_login_sheet(): void
    {
        $found = [];
        foreach (KeptTemplates::MODERN as $t) {
            $blade = resource_path("views/store_templates/{$t}/index.blade.php");
            if (is_file($blade) && str_contains(file_get_contents($blade), 'customer.otp.verify')) {
                $found[] = $t;
            }
        }

        $missing = array_diff(self::LOGIN_THEMES, $found);
        $this->assertSame([], array_values($missing), 'A phone-OTP login theme lost its login sheet: '.implode(',', $missing));
    }
}
