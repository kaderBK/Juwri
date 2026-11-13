<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Accounting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GAPS-RUN-2 B3-P2: accounting owner UI.
 */
class AccountingUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        config(['settings.admin_allow_accounting_sync' => true]);
        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'd.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'f.png'])
        {
            public function __construct(private array $d) {}

            public function __get($k): mixed
            {
                return $this->d[$k] ?? null;
            }

            public function __set($k, $v): void
            {
                $this->d[$k] = $v;
            }

            public function __isset($k): bool
            {
                return array_key_exists($k, $this->d);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect()]);
    }

    private function ownerRestaurant(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $r];
    }

    public function test_page_renders_with_nav(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->withoutMiddleware();
        $res = $this->actingAs($owner)->get(route('stores.accounting.index').'?store_id='.$r->id)->assertOk();
        $res->assertSee('Connection');
        $res->assertSeeHtml('accounting'); // nav URL
    }

    public function test_connect_stores_encrypted_xero_creds(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('stores.accounting.connect').'?store_id='.$r->id, [
            // UI persistence test: use a literal public IP so the SSRF guard is
            // deterministic even when the test runner has no DNS access.
            'provider' => 'xero', 'base_url' => 'https://8.8.8.8/api.xro/2.0',
            'access_token' => 'AT', 'refresh_token' => 'RT', 'tenant_id' => 'TEN',
        ])->assertRedirect();

        $cred = Credential::where('store_id', $r->id)->where('feature', 'accounting')->first();
        $this->assertNotNull($cred);
        $this->assertSame('xero', $cred->provider);
        $this->assertSame('AT', $cred->secrets['access_token']);
        $this->assertSame('TEN', $cred->secrets['tenant_id']);
    }

    public function test_secrets_are_never_rendered_and_blank_keeps_them(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        Credential::create([
            'store_id' => $r->id, 'feature' => 'accounting', 'provider' => 'xero', 'active' => true,
            'secrets' => ['provider' => 'xero', 'base_url' => 'https://8.8.8.8/api.xro/2.0', 'access_token' => 'SECRET_AT', 'refresh_token' => 'SECRET_RT'],
        ]);
        $this->withoutMiddleware();

        // The page must not leak the stored tokens into the HTML.
        $res = $this->actingAs($owner)->get(route('stores.accounting.index').'?store_id='.$r->id)->assertOk();
        $res->assertDontSeeHtml('SECRET_AT');
        $res->assertDontSeeHtml('SECRET_RT');

        // Re-saving with the token fields left blank keeps the stored secret.
        $this->actingAs($owner)->post(route('stores.accounting.connect').'?store_id='.$r->id, [
            'provider' => 'xero', 'base_url' => 'https://8.8.8.8/api.xro/2.0', 'tenant_id' => 'TEN2',
        ])->assertRedirect();

        $cred = Credential::for('accounting', $r->id);
        $this->assertSame('SECRET_AT', $cred['access_token']); // preserved
        $this->assertSame('TEN2', $cred['tenant_id']);         // updated
    }

    public function test_index_is_tenant_scoped(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        [$other, $otherR] = $this->ownerRestaurant();
        $this->withoutMiddleware();
        $this->actingAs($owner)->get(route('stores.accounting.index').'?store_id='.$otherR->id)->assertForbidden();
    }
}
