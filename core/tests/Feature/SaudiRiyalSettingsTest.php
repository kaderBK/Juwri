<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Saudi Riyal Symbol (U+20C1) rollout — S4: settings one-click button + the
 * copy-paste normalization guard.
 *
 * The discriminating test is the normalization: a SAR store that saves the wrong
 * riyal codepoint (U+20C0 Kyrgyz som, or the pre-2025 private-use U+E900) must
 * end up storing the canonical U+20C1 — but ONLY when the code is SAR (locked
 * §1), so a KGS som or any other currency's sign is left exactly as typed.
 */
class SaudiRiyalSettingsTest extends TestCase
{
    private const RIYAL = "\u{20C1}";   // ⃁  SAUDI RIYAL SIGN (canonical)

    private const SOM = "\u{20C0}";     // ⃀  KYRGYZ SOM SIGN (look-alike error)

    private const PUA = "\u{E900}";     //    pre-2025 private-use riyal glyph

    public function test_normalize_rewrites_wrong_riyal_codepoints_only_for_sar(): void
    {
        // SAR: both wrong codepoints collapse to U+20C1; the canonical stays put.
        $this->assertSame(self::RIYAL, normalize_currency_sign(self::SOM, 'SAR'));
        $this->assertSame(self::RIYAL, normalize_currency_sign(self::PUA, 'SAR'));
        $this->assertSame(self::RIYAL, normalize_currency_sign(self::RIYAL, 'SAR'));
        // case-insensitive on the code.
        $this->assertSame(self::RIYAL, normalize_currency_sign(self::SOM, 'sar'));

        // SAR but an unrelated sign — untouched (e.g. a merchant who kept ر.س).
        $this->assertSame('ر.س', normalize_currency_sign('ر.س', 'SAR'));
        $this->assertSame('$', normalize_currency_sign('$', 'SAR'));

        // NON-SAR: the guard must not fire — a KGS store may legitimately use the
        // som sign, and no other currency's sign is rewritten.
        $this->assertSame(self::SOM, normalize_currency_sign(self::SOM, 'KGS'));
        $this->assertSame(self::PUA, normalize_currency_sign(self::PUA, 'USD'));
        $this->assertSame(self::SOM, normalize_currency_sign(self::SOM, ''));
    }

    public function test_both_settings_controllers_normalize_currency_sign_on_save(): void
    {
        // reseller-store F0-1: the owner save now flows through the shared
        // StoreSettingsService seam, which normalizes the sign in applyOptions().
        $owner = (string) file_get_contents(app_path('Core/Services/Store/StoreSettingsService.php'));
        $this->assertStringContainsString(
            'normalize_currency_sign((string) $value, (string) $currencyCode)',
            $owner,
            'the shared store-settings save must normalize the currency sign'
        );

        $admin = (string) file_get_contents(app_path('Core/Http/Controllers/Admin/SettingsController.php'));
        $this->assertStringContainsString('normalize_currency_sign(', $admin);
        $this->assertStringContainsString("\$options['currency_sign']", $admin); // WI-08f rename; behaviour: SettingsAdminContractTest
    }

    public function test_settings_blades_offer_the_one_click_riyal_button(): void
    {
        foreach ([
            'owner/stores/edit.blade.php',
            'admin/settings/international.blade.php',
        ] as $blade) {
            $src = (string) file_get_contents(resource_path('views/'.$blade));
            $this->assertStringContainsString('mb-use-riyal-symbol', $src, "[{$blade}] must render the one-click button");
            // The shared partial carries the button + autofill behaviour (S10).
            $this->assertStringContainsString("@include('partials.currency-autofill')", $src, "[{$blade}] must include the currency-autofill partial");
        }

        // The shared partial fills the sign with the canonical glyph and gates the
        // button on the codes that own a new sign (SAR + AED since 2026-08-12).
        $partial = (string) file_get_contents(resource_path('views/partials/currency-autofill.blade.php'));
        $this->assertStringContainsString("sign.value = '".self::RIYAL."'", $partial, 'the button must fill U+20C1');
        $this->assertStringContainsString('NEW_SIGN', $partial, 'the button must be gated on the new-sign table');
        $this->assertStringContainsString('SAR:', $partial, 'SAR must be in the new-sign table');
        $this->assertStringContainsString('AED:', $partial, 'AED must be in the new-sign table');
    }
}
