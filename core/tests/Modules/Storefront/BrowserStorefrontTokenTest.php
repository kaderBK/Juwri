<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StorefrontToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Http\Middleware\StorefrontTokenAuth;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Browser-bound storefront tokens: a token flagged `browser` is meant to be
 * embedded in front-end JS, so for the sensitive scopes (order:write, agent) an
 * origin allowlist is MANDATORY and the request's Origin must match — fail
 * closed. A server token (browser=false, the default) is unchanged: its optional
 * allowlist is enforced only when set, so no existing server-to-server token —
 * which sends no Origin — is affected.
 *
 * @see StorefrontTokenAuth
 */
class BrowserStorefrontTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'storefront_api' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => 1]);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => 9, 'active' => 1]);

        return $store;
    }

    /** @param  array<string,mixed>  $attrs */
    private function token(Store $store, array $attrs = []): string
    {
        $raw = Str::random(48);
        StorefrontToken::create(array_merge([
            'store_id' => $store->id, 'token' => hash('sha256', $raw),
            'scopes' => ['order:write'], 'active' => true,
        ], $attrs));

        return $raw;
    }

    /** @param  array<string,string>  $headers */
    private function postOrder(Store $store, string $raw, array $headers = [])
    {
        $item = (int) Menu::where('store_id', $store->id)->value('id');

        return $this->postJson(
            route('api.v1.storefront.orders.create'),
            ['type' => 'takeaway', 'customer_name' => 'Sam', 'items' => [['item_id' => $item, 'quantity' => 1]]],
            array_merge(['X-Storefront-Token' => $raw], $headers),
        );
    }

    public function test_browser_write_token_without_origin_header_is_rejected(): void
    {
        $r = $this->storeFixture();
        $raw = $this->token($r, ['browser' => true, 'allowed_origins' => ['https://shop.test']]);

        $this->postOrder($r, $raw)->assertStatus(403); // no Origin header at all
        $this->assertDatabaseMissing('orders', ['store_id' => $r->id, 'source' => 'storefront_api']);
    }

    public function test_browser_write_token_with_matching_origin_succeeds(): void
    {
        $r = $this->storeFixture();
        $raw = $this->token($r, ['browser' => true, 'allowed_origins' => ['https://shop.test']]);

        $this->postOrder($r, $raw, ['Origin' => 'https://shop.test'])->assertStatus(201);
    }

    public function test_browser_write_token_with_wrong_origin_is_rejected(): void
    {
        $r = $this->storeFixture();
        $raw = $this->token($r, ['browser' => true, 'allowed_origins' => ['https://shop.test']]);

        $this->postOrder($r, $raw, ['Origin' => 'https://evil.test'])->assertStatus(403);
    }

    public function test_browser_token_without_allowlist_fails_closed(): void
    {
        $r = $this->storeFixture();
        // A browser token minted (e.g. via API) with no allowlist must not accept
        // writes from any origin — fail closed.
        $raw = $this->token($r, ['browser' => true, 'allowed_origins' => null]);

        $this->postOrder($r, $raw, ['Origin' => 'https://anything.test'])->assertStatus(403);
    }

    public function test_server_write_token_is_unchanged_and_needs_no_origin(): void
    {
        $r = $this->storeFixture();
        // Default server token: browser=false, no allowlist — the pre-existing
        // behaviour. A server-to-server write (no Origin header) still works.
        $raw = $this->token($r); // scopes order:write, browser default false

        $this->postOrder($r, $raw)->assertStatus(201);
    }

    public function test_server_token_with_allowlist_enforces_origin_like_before(): void
    {
        $r = $this->storeFixture();
        $raw = $this->token($r, ['allowed_origins' => ['https://shop.test']]); // browser=false

        // A matching Origin passes; a non-listed Origin is rejected. This is the
        // pre-existing opt-in enforcement (unchanged) for a server token that
        // happens to declare an allowlist.
        $this->postOrder($r, $raw, ['Origin' => 'https://shop.test'])->assertStatus(201);
        $this->postOrder($r, $raw, ['Origin' => 'https://evil.test'])->assertStatus(403);
    }

    // --- issuance UI validation -------------------------------------------

    public function test_creating_browser_token_without_origins_is_rejected(): void
    {
        $r = $this->storeFixture();
        $owner = User::find($r->user_id);

        $this->actingAs($owner)->post(route('storefront.token.create'), [
            'store_id' => $r->id, 'scopes' => ['order:write'], 'browser' => '1', 'allowed_origins' => '',
        ])->assertSessionHasErrors('allowed_origins');

        $this->assertDatabaseMissing('storefront_tokens', ['store_id' => $r->id]);
    }

    public function test_creating_browser_token_with_origins_persists_flag_and_list(): void
    {
        $r = $this->storeFixture();
        $owner = User::find($r->user_id);

        $this->actingAs($owner)->post(route('storefront.token.create'), [
            'store_id' => $r->id, 'scopes' => ['order:write'], 'browser' => '1',
            // textarea: newlines + a trailing slash to be normalized away
            'allowed_origins' => "https://shop.test/\nhttps://www.shop.test",
        ])->assertRedirectContains('new_storefront_token='); // shown once from the server-side store (OneTimeSecrets)

        $tok = StorefrontToken::where('store_id', $r->id)->firstOrFail();
        $this->assertTrue($tok->browser);
        $this->assertSame(['https://shop.test', 'https://www.shop.test'], $tok->allowed_origins);
    }

    public function test_creating_browser_token_with_malformed_origin_is_rejected(): void
    {
        $r = $this->storeFixture();
        $owner = User::find($r->user_id);

        $this->actingAs($owner)->post(route('storefront.token.create'), [
            'store_id' => $r->id, 'scopes' => ['order:write'], 'browser' => '1',
            'allowed_origins' => 'shop.test/path', // no scheme, has a path
        ])->assertSessionHasErrors('allowed_origins');
    }

    public function test_api_settings_page_renders_the_browser_token_controls(): void
    {
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->shareLayoutGlobals();

        $r = $this->storeFixture();
        $owner = User::find($r->user_id);

        // The new issuance controls compile + render on the dashboard API page.
        $html = $this->actingAs($owner)->get(route('api.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Allowed origins', $html);
        $this->assertStringContainsString('Browser token', $html);
    }
}
