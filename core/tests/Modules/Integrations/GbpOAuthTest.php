<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Services\Gbp\GbpTokenService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * google-partner Lane A / A2 — GBP OAuth connect + token vault + refresh choke point.
 * All HTTP is faked; no live Google call.
 */
class GbpOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        config([
            'settings.admin_allow_gbp_link' => true,
            'services.gbp.driver' => 'real',
            'services.gbp.client_id' => 'gbp-client',
            'services.gbp.client_secret' => 'gbp-secret',
        ]);
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

    public function test_connect_redirects_to_google_consent(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->withoutMiddleware();

        $res = $this->actingAs($owner)->get(route('stores.gbp.oauth.connect').'?store_id='.$r->id);
        $res->assertRedirect();
        $this->assertStringContainsString('accounts.google.com/o/oauth2', $res->headers->get('Location'));
        $this->assertStringContainsString('business.manage', $res->headers->get('Location'));
        $this->assertStringContainsString('access_type=offline', $res->headers->get('Location'));
    }

    public function test_connect_falls_back_dark_when_unconfigured(): void
    {
        config(['services.gbp.client_id' => null]);
        [$owner, $r] = $this->ownerRestaurant();
        $this->withoutMiddleware();

        // Dark: no OAuth app → redirect back with an error, NOT a redirect to Google.
        $res = $this->actingAs($owner)->get(route('stores.gbp.oauth.connect').'?store_id='.$r->id);
        $res->assertRedirect();
        $this->assertStringNotContainsString('accounts.google.com', (string) $res->headers->get('Location'));
    }

    public function test_callback_rejects_bad_state(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->withoutMiddleware();

        $this->actingAs($owner)
            ->withSession(['gbp_oauth' => ['state' => 'expected-state', 'rid' => $r->id]])
            ->get(route('stores.gbp.oauth.callback').'?state=WRONG&code=abc')
            ->assertForbidden();
    }

    public function test_callback_exchanges_and_stores_tokens_encrypted(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'ACCESS-1',
                'refresh_token' => 'REFRESH-1',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
                'scope' => 'https://www.googleapis.com/auth/business.manage',
            ], 200),
        ]);

        [$owner, $r] = $this->ownerRestaurant();
        $this->withoutMiddleware();

        $this->actingAs($owner)
            ->withSession(['gbp_oauth' => ['state' => 'st', 'rid' => $r->id]])
            ->get(route('stores.gbp.oauth.callback').'?state=st&code=the-code')
            ->assertRedirect();

        $creds = Credential::for('gbp', $r->id);
        $this->assertSame('ACCESS-1', $creds['access_token']);
        $this->assertSame('REFRESH-1', $creds['refresh_token']);
        $this->assertGreaterThan(time() + 3000, (int) $creds['expires_at']);

        // Stored encrypted: the truly-raw DB column (no model cast) is not plaintext.
        $raw = DB::table('credentials')
            ->where('store_id', $r->id)->where('feature', 'gbp')->value('secrets');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('ACCESS-1', $raw);
    }

    public function test_callback_is_tenant_scoped(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        [$other, $otherR] = $this->ownerRestaurant();
        $this->withoutMiddleware();

        // Session says a restaurant this owner does NOT control → 403.
        $this->actingAs($owner)
            ->withSession(['gbp_oauth' => ['state' => 'st', 'rid' => $otherR->id]])
            ->get(route('stores.gbp.oauth.callback').'?state=st&code=abc')
            ->assertForbidden();
    }

    public function test_disconnect_removes_credential(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        Credential::create([
            'store_id' => $r->id, 'feature' => 'gbp', 'provider' => 'gbp', 'active' => true,
            'secrets' => ['access_token' => 'x', 'location_id' => 'LOC'],
        ]);
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('stores.gbp.disconnect').'?store_id='.$r->id)->assertRedirect();
        $this->assertNull(Credential::for('gbp', $r->id));
    }

    public function test_access_token_refreshes_when_expired(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'FRESH', 'expires_in' => 3600], 200),
        ]);

        [$owner, $r] = $this->ownerRestaurant();
        Credential::create([
            'store_id' => $r->id, 'feature' => 'gbp', 'provider' => 'gbp', 'active' => true,
            'secrets' => ['access_token' => 'STALE', 'refresh_token' => 'RT', 'expires_at' => time() - 10],
        ]);

        $token = GbpTokenService::accessTokenFor($r->id);
        $this->assertSame('FRESH', $token);
        // Persisted back into the row + old refresh_token preserved (merge).
        $creds = Credential::for('gbp', $r->id);
        $this->assertSame('FRESH', $creds['access_token']);
        $this->assertSame('RT', $creds['refresh_token']);
    }

    public function test_access_token_not_refreshed_when_valid(): void
    {
        Http::fake(); // any call would throw the fake's default; we assert none happen

        [$owner, $r] = $this->ownerRestaurant();
        Credential::create([
            'store_id' => $r->id, 'feature' => 'gbp', 'provider' => 'gbp', 'active' => true,
            'secrets' => ['access_token' => 'LIVE', 'refresh_token' => 'RT', 'expires_at' => time() + 3600],
        ]);

        $this->assertSame('LIVE', GbpTokenService::accessTokenFor($r->id));
        Http::assertNothingSent();
    }

    public function test_access_token_null_when_dark(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        // No credential row at all.
        $this->assertNull(GbpTokenService::accessTokenFor($r->id));
    }
}
