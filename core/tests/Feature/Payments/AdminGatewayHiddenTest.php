<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Tests\Feature\Admin\InteractsWithAdminPanel;
use Tests\TestCase;

/**
 * J51a: only Stripe and PayPal at launch — Tap and Moyasar are hidden from the
 * platform gateway list and their edit/save is refused unless the
 * payments.show_deferred_gateways flag is switched on.
 */
class AdminGatewayHiddenTest extends TestCase
{
    use InteractsWithAdminPanel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdminPanel();
    }

    public function test_index_hides_deferred_gateways_but_shows_stripe(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.gateways.index'))
            ->assertOk()
            ->assertSee('Credit & Debit Card')
            ->assertDontSee('Moyasar')
            ->assertDontSeeHtml('alt="tap"')
            ->assertDontSeeHtml('alt="moyasar"');
    }

    public function test_edit_and_update_abort_404_for_a_hidden_gateway(): void
    {
        $admin = $this->admin();
        $tap = PaymentGateway::where('payment_folder', 'tap')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('admin.gateways.edit', $tap), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertNotFound();

        $this->actingAs($admin)
            ->panelPost(route('admin.gateways.update', $tap), ['title' => 'Tap', 'active' => 1, 'tap_secret_key' => 'sk_test_t'])
            ->assertNotFound();

        $this->assertFalse($tap->fresh()->payment_install);
    }

    public function test_deferred_gateways_appear_and_can_be_enabled_when_the_flag_is_on(): void
    {
        config(['payments.show_deferred_gateways' => true]);
        $admin = $this->admin();
        $tap = PaymentGateway::where('payment_folder', 'tap')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('admin.gateways.index'))
            ->assertOk()
            ->assertSee('Moyasar')
            ->assertSeeHtml('alt="tap"');

        $this->actingAs($admin)
            ->get(route('admin.gateways.edit', $tap), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk();

        $this->assertPanelOk($this->actingAs($admin)->panelPost(route('admin.gateways.update', $tap), [
            'title' => 'Tap', 'active' => 1, 'tap_secret_key' => 'sk_test_t',
        ]));

        $this->assertTrue($tap->fresh()->payment_install);
    }
}
