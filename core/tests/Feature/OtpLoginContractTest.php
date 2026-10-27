<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Tests\TestCase;

/**
 * A3 guard for the storefront customer phone-OTP LOGIN.
 *
 * The backend contract is fixed: User\CustomerAuthController::verifyOtp validates
 * `'code' => 'required|string'` and reads $request->code, and SmsOtpService::request()
 * generates a 6-digit code. So every storefront theme that offers a phone-OTP login
 * MUST (1) post the collected code under the key `code:` (never `otp:`) and (2) give
 * the user a field that can actually hold 6 digits.
 *
 * This test reads each kept theme's script.js + index.blade.php as source text — it
 * does not render — so it locks the contract against silent regressions.
 */
class OtpLoginContractTest extends TestCase
{
    /** The 17 kept storefront templates (single source of truth: KeptTemplates::MODERN). */
    private const THEMES = KeptTemplates::MODERN;

    /**
     * Login OTP box-group ids, checked in order. crave/glow/matcha use the cv-prefixed
     * id; the other box themes use the bare id. Only consulted once a phone-OTP LOGIN
     * sheet is confirmed present, so a theme that reuses `otpBoxes` for its checkout
     * order-gate (e.g. dough) is not mistaken for a login field.
     */
    private const LOGIN_BOX_GROUP_IDS = ['cvOtpBoxes', 'otpBoxes', 'ff-otp-boxes', 'v-otp-boxes', 'crumbOtpBoxes', 'emberOtpBoxes', 'doughOtpBoxes'];

    /**
     * A1: the storefront LOGIN OTP verify must post `code:`, and no theme may ever post
     * a bare `otp:` key. The checkout order-gate already posts `code:` too, so "no bare
     * `otp:` key anywhere in the theme JS" is the correct, robust invariant for all 17
     * themes — no storefront flow should send a key literally named `otp`. The five
     * themes without a phone-OTP login sheet pass trivially (there is no such call).
     */
    public function test_storefront_otp_verify_never_posts_otp_key(): void
    {
        foreach (self::THEMES as $theme) {
            $script = $this->scriptSource($theme);

            // Matches an object property key `otp:` (optional whitespace before the
            // colon) but NOT endpoint config keys such as otpVerify:, otpRequest:,
            // otpSend:, otpVerifyUrl: — those have letters immediately after "otp".
            $this->assertDoesNotMatchRegularExpression(
                '/(?<![A-Za-z0-9_$])otp\s*:/',
                $script,
                "{$theme}: storefront JS posts a bare `otp:` key — the OTP verify must send `code:` (backend reads \$request->code)."
            );
        }
    }

    /**
     * A2: any theme that HAS a phone-OTP login sheet must let the user enter the full
     * 6-digit code — EITHER >= 6 single-char boxes in the login group, OR a single
     * input with maxlength >= 6 (aurora name="code", vivid #vd-otp-code). Such a theme
     * must also post the code under `code:`. Themes without a login sheet
     * (crumb, dough, ember, feast, velvet) are skipped.
     */
    public function test_login_otp_field_can_hold_six_digits(): void
    {
        $checked = 0;

        foreach (self::THEMES as $theme) {
            $field = $this->loginOtpField($this->bladeSource($theme));

            if ($field === null) {
                continue; // no phone-OTP login sheet in this theme
            }

            $checked++;

            $this->assertNotSame(
                'unknown',
                $field['type'],
                "{$theme}: has a phone-OTP login sheet but the login OTP field could not be located."
            );

            if ($field['type'] === 'boxes') {
                $this->assertGreaterThanOrEqual(
                    6,
                    $field['count'],
                    "{$theme}: login OTP box group (#{$field['id']}) has {$field['count']} boxes; a 6-digit code needs >= 6."
                );
            } else { // single input
                $this->assertGreaterThanOrEqual(
                    6,
                    $field['maxlength'],
                    "{$theme}: single login OTP input has maxlength={$field['maxlength']}; a 6-digit code needs maxlength >= 6."
                );
            }

            // Positive contract: a login sheet must post the code under `code:`.
            $this->assertMatchesRegularExpression(
                '/(?<![A-Za-z0-9_$])code\s*:/',
                $this->scriptSource($theme),
                "{$theme}: login OTP verify must post a `code:` key."
            );
        }

        // Sanity: the 12 login-bearing themes (10 box + aurora + vivid) were exercised,
        // so a refactor that removes a login sheet does not silently shrink coverage.
        $this->assertGreaterThanOrEqual(12, $checked, 'Expected at least 12 themes with a phone-OTP login sheet.');
    }

    // ── helpers ──────────────────────────────────────────────

    private function bladeSource(string $theme): string
    {
        return file_get_contents(resource_path("views/store_templates/{$theme}/index.blade.php"));
    }

    private function scriptSource(string $theme): string
    {
        return file_get_contents(base_path("../assets/store_templates/{$theme}/js/script.js"));
    }

    /**
     * Locate the LOGIN phone-OTP field in a theme's blade source, or null when the
     * theme has no phone-OTP login sheet.
     *
     * A login sheet is marked by `data-auth="otp"` (most themes) or aurora's tabbed
     * `id="au-otp-form"`. Only then is a box group / single input treated as the login
     * field — this keeps a checkout order-gate that happens to reuse the `otpBoxes` id
     * from being mistaken for the login field.
     *
     * @return array{type:string,id?:string,count?:int,maxlength?:int}|null
     */
    private function loginOtpField(string $blade): ?array
    {
        $hasLoginSheet = preg_match('/data-auth="otp"/', $blade)
            || preg_match('/id="au-otp-form"/', $blade);

        if (! $hasLoginSheet) {
            return null;
        }

        // Box-group login themes: count the single-char inputs inside the group div.
        // These groups contain only <input> elements (no nested <div>), so a
        // non-greedy match to the next </div> is exact.
        foreach (self::LOGIN_BOX_GROUP_IDS as $id) {
            if (preg_match('/<div\b[^>]*\bid="'.preg_quote($id, '/').'"[^>]*>(.*?)<\/div>/s', $blade, $m)) {
                return [
                    'type' => 'boxes',
                    'id' => $id,
                    'count' => preg_match_all('/<input\b/', $m[1]),
                ];
            }
        }

        // Single-field login themes: aurora (name="code"), vivid (#vd-otp-code).
        foreach (['name="code"', 'id="vd-otp-code"'] as $marker) {
            if (preg_match('/<input\b[^>]*'.preg_quote($marker, '/').'[^>]*>/', $blade, $m)) {
                return [
                    'type' => 'single',
                    'maxlength' => preg_match('/\bmaxlength="(\d+)"/', $m[0], $mm) ? (int) $mm[1] : 0,
                ];
            }
        }

        return ['type' => 'unknown'];
    }
}
