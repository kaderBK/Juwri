<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\AppStore;

use App\Models\App;
use App\Models\AppInstallation;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * stores P2-2: the app detail page — pricing table per billing_type, scopes as
 * human strings, developer/support/docs, and the guest/owner/installed states
 * of the install CTA.
 */
class AppStoreDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class]);
    }

    private function app(array $overrides = []): App
    {
        return App::create(array_merge([
            'name' => 'Loyalty Plus', 'slug' => 'loyalty-plus', 'scopes' => ['menu:read'],
            'status' => 'approved', 'category' => 'loyalty', 'tagline' => 'Points and rewards',
            'billing_type' => 'free', 'price' => 0, 'billing_period_days' => 30,
            'support_url' => 'https://help.test/lp', 'docs_url' => 'https://docs.test/lp',
        ], $overrides));
    }

    /** English locale so translated strings assert deterministically. */
    private function getEn(string $url)
    {
        return $this->withSession(['locale' => 'en'])->get($url);
    }

    public function test_pricing_table_free(): void
    {
        $this->app(['billing_type' => 'free', 'price' => 0]);
        $this->getEn(route('apps.store.show', 'loyalty-plus'))->assertOk()
            ->assertSee('This app is free to install.');
    }

    public function test_pricing_table_recurring(): void
    {
        $this->app(['billing_type' => 'recurring', 'price' => 12, 'billing_period_days' => 30]);
        $res = $this->getEn(route('apps.store.show', 'loyalty-plus'))->assertOk();
        $res->assertSee('Billed monthly');
        $res->assertSee('data-billing="recurring"', false);
    }

    public function test_pricing_table_usage(): void
    {
        $this->app(['billing_type' => 'usage', 'price' => 0, 'usage_unit' => 'call', 'usage_price' => 0.05]);
        $res = $this->getEn(route('apps.store.show', 'loyalty-plus'))->assertOk();
        $res->assertSee('Usage');
        $res->assertSee('call');                                // per :unit
        $res->assertSee('data-billing="usage"', false);
    }

    public function test_scopes_render_human_labels_and_unknown_raw(): void
    {
        $this->app(['scopes' => ['menu:read', 'bogus:scope']]);
        $res = $this->getEn(route('apps.store.show', 'loyalty-plus'))->assertOk();
        $res->assertSee('Read menus, categories and items');   // Passport label
        $res->assertSee('bogus:scope');                        // unknown → raw key
    }

    public function test_guest_sees_sign_in_not_install(): void
    {
        $this->app();
        $res = $this->getEn(route('apps.store.show', 'loyalty-plus'))->assertOk();
        $res->assertSee(route('login'), false);
        $res->assertDontSee('id="installBtn"', false);
    }

    public function test_owner_not_installed_sees_install_button(): void
    {
        $this->app();
        $this->actingAs(User::factory()->create(['status' => 1, 'email_verified_at' => now()]));
        $this->getEn(route('apps.store.show', 'loyalty-plus'))->assertOk()->assertSee('id="installBtn"', false);
    }

    public function test_owner_installed_sees_manage_link(): void
    {
        $app = $this->app();
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        AppInstallation::create([
            'app_id' => $app->id, 'user_id' => $user->id, 'granted_scopes' => ['menu:read'],
            'status' => 'active', 'installed_at' => now(),
        ]);
        $this->actingAs($user);

        $res = $this->getEn(route('apps.store.show', 'loyalty-plus'))->assertOk();
        $res->assertDontSee('id="installBtn"', false);
        $res->assertSee(route('apps.page'), false);
    }

    public function test_draft_app_is_404(): void
    {
        $this->app(['status' => 'draft', 'slug' => 'draft-app']);
        $this->get(route('apps.store.show', 'draft-app'))->assertNotFound();
    }

    public function test_developer_and_docs_links_render(): void
    {
        $this->app();
        $res = $this->getEn(route('apps.store.show', 'loyalty-plus'))->assertOk();
        $res->assertSee('https://help.test/lp', false);
        $res->assertSee('https://docs.test/lp', false);
    }
}
