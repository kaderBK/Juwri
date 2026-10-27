<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Pci;

use App\Http\Middleware\AbuseHoldWall;
use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\App;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\SyncDashboardStore;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\AssertsPaymentPagePolicy;
use Tests\Support\EditionTestHooks;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * PCI DSS 4.0 req. 6.4.3: an owner-dashboard payment page (App Store purchase with wallet top-up) gets the strict
 * payment CSP over the dashboard policy — 'unsafe-eval' only because the inventory records the shell's Alpine — and
 * opts out of Turbo Drive, so the policy is the one in force whenever the page is shown (a Turbo visit keeps the
 * previous document's CSP). The dashboard shell's brand-tile <style> carries the nonce now (it was refused by an
 * enforced policy before).
 */
class DashboardPaymentPagesTest extends TestCase
{
    use AssertsPaymentPagePolicy;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
            SyncDashboardStore::class,
            ...EditionTestHooks::middleware(),
            AbuseHoldWall::class,
        ]);
    }

    public function test_the_app_store_purchase_page(): void
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999])]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        Store::create(['user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'R', 'sub_title' => 'T',
            'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
        $app = App::create(['name' => 'Paid App', 'slug' => 'paid-app', 'scopes' => ['menu:read'], 'status' => 'approved', 'price' => 9]);

        $res = $this->actingAs($owner)->get(route('user.store.apps.show', $app->slug))->assertOk();

        $d = $this->assertStrictPaymentPolicy($res, 'owner.app_purchase');
        $this->assertStringContainsString("'unsafe-eval'", $d['script-src']);
        $html = (string) $res->getContent();
        $this->assertStringContainsString('<meta name="turbo-visit-control" content="reload">', $html);
        $this->assertDoesNotMatchRegularExpression('/<style>/', $html, 'every <style> carries the nonce');
    }

    public function test_a_dashboard_page_that_is_not_a_payment_page_keeps_turbo_and_its_own_policy(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        $res = $this->actingAs($owner)->get(route('user.store.apps'));

        $this->assertNull($res->headers->get('Reporting-Endpoints'));
        $this->assertStringNotContainsString('turbo-visit-control', (string) $res->getContent());
    }
}
