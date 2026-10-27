<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\AppStore;

use App\Models\Theme;
use App\Services\ThemePurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Services\WalletService;
use Tests\Feature\Admin\InteractsWithAdminPanel;
use Tests\TestCase;

/** P6-243: the admin theme page lists paid licences with a "Refund purchase" action (reason required). */
class ThemePurchaseRefundAdminTest extends TestCase
{
    use InteractsWithAdminPanel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->bootAdminPanel();
    }

    public function test_admin_sees_the_refund_button_and_refunds_with_a_reason(): void
    {
        $admin = $this->admin();
        $buyer = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        WalletService::credit($buyer, 50, 'seed', 'seed-b');
        $theme = Theme::create(['name' => 'Paid', 'slug' => 'paid-adm', 'status' => 'published', 'price' => 20, 'seller_id' => null]);
        $purchase = new ThemePurchaseService()->purchase($buyer, $theme);

        $this->actingAs($admin)->get(route('admin.theme-reviews.index'))->assertOk()
            ->assertSee(route('admin.theme-purchases.refund', $purchase->id), false);

        $this->actingAs($admin)->post(route('admin.theme-purchases.refund', $purchase->id), [])->assertSessionHasErrors('reason');
        $this->assertNull($purchase->fresh()->refunded_at);

        $this->actingAs($admin)->post(route('admin.theme-purchases.refund', $purchase->id), ['reason' => 'wrong theme'])->assertRedirect();
        $this->assertNotNull($purchase->fresh()->refunded_at);
        $this->assertSame(50.0, WalletService::balanceFor($buyer->fresh()));

        auth()->logout();
        $this->assertAdminOnly('post', route('admin.theme-purchases.refund', $purchase->id), ['reason' => 'x']);
    }
}
