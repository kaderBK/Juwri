<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Owner-facing BYOK courier credentials form (Uber Direct / DoorDash):
 * renders for an entitled owner, saves into the encrypted Credential vault,
 * never echoes a secret back, refuses another tenant, and is feature-gated.
 */
class CourierCredentialsUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
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

    protected function tearDown(): void
    {
        putenv('THEME_NAME');
        unset($_ENV['THEME_NAME'], $_SERVER['THEME_NAME']);
        parent::tearDown();
    }

    /** @return array{0: User, 1: Store} */
    private function owner(bool $entitled = true): array
    {
        $settings = ['restaurant_limit' => 999, 'menu_limit' => 999];
        if ($entitled) {
            $settings['courier_dispatch'] = '1';
        }
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode($settings),
        ]);
        $user = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $user->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$user, $r];
    }

    public function test_form_renders_for_entitled_owner(): void
    {
        $this->withoutMiddleware();
        [$user, $r] = $this->owner();

        $this->actingAs($user)
            ->get(route('stores.dispatch.couriers', ['store_id' => $r->id]))->assertOk()->assertSeeHtml('Uber Direct')->assertSeeHtml('DoorDash');
    }

    public function test_save_writes_credentials_to_the_vault(): void
    {
        $this->withoutMiddleware();
        [$user, $r] = $this->owner();

        $this->actingAs($user)->post(route('stores.dispatch.couriers.save', ['provider' => 'uber_direct', 'store_id' => $r->id]), [
            'customer_id' => 'CUST', 'client_id' => 'CID', 'client_secret' => 'SUPERSECRET',
        ])->assertRedirect();

        $creds = Credential::for('uber_direct', $r->id);
        $this->assertSame('CID', $creds['client_id']);
        $this->assertSame('SUPERSECRET', $creds['client_secret']);

        // Stored encrypted — the raw column never holds the plaintext.
        $raw = (string) DB::table('credentials')
            ->where('feature', 'uber_direct')->where('store_id', $r->id)->value('secrets');
        $this->assertStringNotContainsString('SUPERSECRET', $raw);
    }

    public function test_secret_is_masked_on_redisplay_and_blank_submit_keeps_it(): void
    {
        $this->withoutMiddleware();
        [$user, $r] = $this->owner();
        Credential::create([
            'store_id' => $r->id, 'feature' => 'doordash', 'provider' => 'doordash',
            'secrets' => ['developer_id' => 'DEV', 'key_id' => 'KEY', 'signing_secret' => 'SUPERSECRET'],
            'active' => true,
        ]);

        $res = $this->actingAs($user)->get(route('stores.dispatch.couriers', ['store_id' => $r->id]))->assertOk();
        $res->assertDontSeeHtml('SUPERSECRET');
        $res->assertSeeHtml('DEV');
        $res->assertSeeHtml('leave blank to keep');

        // Re-saving without the secret keeps the stored one.
        $this->actingAs($user)->post(route('stores.dispatch.couriers.save', ['provider' => 'doordash', 'store_id' => $r->id]), [
            'developer_id' => 'DEV2', 'key_id' => 'KEY2', 'signing_secret' => '',
        ])->assertRedirect();

        $creds = Credential::for('doordash', $r->id);
        $this->assertSame('DEV2', $creds['developer_id']);
        $this->assertSame('SUPERSECRET', $creds['signing_secret']);
    }

    public function test_first_connect_rejects_a_blank_secret(): void
    {
        $this->withoutMiddleware();
        [$user, $r] = $this->owner();

        // Blank only means "keep what is stored" — with nothing stored it would
        // otherwise write an empty secret and leave the vault row half-built.
        $this->actingAs($user)->post(route('stores.dispatch.couriers.save', ['provider' => 'uber_direct', 'store_id' => $r->id]), [
            'customer_id' => 'CUST', 'client_id' => 'CID', 'client_secret' => '',
        ])->assertSessionHasErrors('client_secret');

        $this->assertNull(Credential::for('uber_direct', $r->id));
    }

    public function test_another_tenant_is_refused_on_read_and_write(): void
    {
        $this->withoutMiddleware();
        [$userA] = $this->owner();
        [$userB, $rB] = $this->owner();

        $this->actingAs($userA)
            ->get(route('stores.dispatch.couriers', ['store_id' => $rB->id]))
            ->assertForbidden();

        $this->actingAs($userA)->post(route('stores.dispatch.couriers.save', ['provider' => 'uber_direct', 'store_id' => $rB->id]), [
            'customer_id' => 'X', 'client_id' => 'X', 'client_secret' => 'X',
        ])->assertForbidden();

        $this->assertNull(Credential::where('store_id', $rB->id)->where('feature', 'uber_direct')->first());
    }

    public function test_route_is_behind_the_courier_dispatch_feature_gate(): void
    {
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
        [$user, $r] = $this->owner();

        // Baseline with the real route middleware in place: the gate lets an
        // entitled owner through.
        config(['settings.admin_allow_courier_dispatch' => true]);
        $this->actingAs($user)
            ->get(route('stores.dispatch.couriers', ['store_id' => $r->id]))
            ->assertOk();

        // Superadmin kill-switch off → 403 from feature:courier_dispatch.
        config(['settings.admin_allow_courier_dispatch' => false]);
        $this->actingAs($user)
            ->get(route('stores.dispatch.couriers', ['store_id' => $r->id]))
            ->assertForbidden();
    }

    public function test_unknown_provider_404s(): void
    {
        $this->withoutMiddleware();
        [$user, $r] = $this->owner();

        $this->actingAs($user)->post(route('stores.dispatch.couriers.save', ['provider' => 'grubhub', 'store_id' => $r->id]), [
            'client_id' => 'X',
        ])->assertNotFound();
    }
}
