<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Juwri\Core\Models\Language;
use Tests\TestCase;

/**
 * C2 — the shared checkout-feedback banner must consume the charge-to-room outcome the
 * /send-order response carries (charged_to_room / room_folio_message), which no theme
 * read before: on failure the guest is told the meal did NOT reach the room bill, on
 * success it is confirmed. The banner behaviour is client JS the orchestrator eyeballs
 * in Chrome; here we pin — server-side — that the partial actually wires both keys and
 * both message branches, so a refactor dropping them fails CI.
 */
class CheckoutFeedbackHotelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
    }

    private function render(): string
    {
        return Blade::render(
            "@include('store_templates._partials.checkout-feedback')",
            [],
            deleteCachedView: true
        );
    }

    public function test_partial_wires_both_charge_to_room_outcomes(): void
    {
        $html = $this->render();

        // Both branches must be present and distinct (success vs failure).
        $this->assertStringContainsString('j.charged_to_room === false', $html, 'failure branch missing');
        $this->assertStringContainsString('j.charged_to_room === true', $html, 'success branch missing');

        // The server message (room_folio_message) is preferred, with a localized fallback each way.
        $this->assertStringContainsString('room_folio_message', $html);
        $this->assertStringContainsString('Charged to your room', $html, 'success fallback string missing');
        $this->assertStringContainsString('could not add it to your room bill', $html, 'failure fallback string missing');

        // A positive-only notice flips to the confirmation (green) styling.
        $this->assertStringContainsString('mbfb-ok', $html);
    }

    /** The three pre-existing warning keys must survive the change (regression guard). */
    public function test_partial_still_wires_the_original_warning_keys(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('coupon_declined', $html);
        $this->assertStringContainsString('dropped_items', $html);
        $this->assertStringContainsString('wallet_ignored', $html);
    }

    /** CSP hygiene: the injected banner stays nonce'd with no inline event handlers. */
    public function test_partial_is_csp_clean(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('nonce=', $html);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=/i', $html, 'no inline on*= handlers allowed');
    }
}
