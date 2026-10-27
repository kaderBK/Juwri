<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Driver;
use App\Models\StorefrontToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Security\OneTimeSecrets;
use Juwri\Modules\Integrations\Models\ApiToken;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * A just-created API / storefront / driver token is shown to its owner exactly once. Before, the plain token rode
 * in a session flash, and a dashboard poll in flight when the form posted could save its older session after the
 * POST and drop the flash (the owner never saw the token; found by the P4-12 journey). Now it waits server-side,
 * encrypted, keyed by kind + owner + row id; the redirect carries only the row id.
 */
class OneTimeSecretDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);
    }

    private function owner(): User
    {
        $plan = TestPlans::create(['name' => 'P '.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function shown(string $html, string $class = 'ui-api__secret'): ?string
    {
        return preg_match('#<code class="'.preg_quote($class, '#').'">([^<]+)</code>#', $html, $m) === 1 ? $m[1] : null;
    }

    public function test_a_poll_between_create_and_display_does_not_lose_the_token_and_it_is_shown_once(): void
    {
        $alice = $this->owner();
        $res = $this->actingAs($alice)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('api.token.create'), ['name' => 'Zapier', 'abilities' => ['read']]);
        $row = ApiToken::where('user_id', $alice->id)->sole();
        $location = (string) $res->headers->get('Location');
        $this->assertStringContainsString('new_token='.$row->id, $location);

        // The concurrent poll: whatever it does to the session (here: wipes it), the token is not in it.
        $this->assertNotContains(true, array_map(fn ($v) => is_string($v) && strlen($v) === 40 && hash('sha256', $v) === $row->token, session()->all()));
        $this->flushSession();
        $this->actingAs($alice)->get(route('dashboard')); // a dashboard request between the POST and the display

        $raw = $this->shown((string) $this->actingAs($alice)->get($location)->assertOk()->getContent());
        $this->assertNotNull($raw, 'the token was lost before it was shown');
        $this->assertSame($row->token, hash('sha256', $raw));
        $this->assertStringNotContainsString($raw, $location, 'the secret is never in the URL');

        // single read
        $this->assertNull($this->shown((string) $this->actingAs($alice)->get($location)->getContent()), 'shown a second time');
    }

    public function test_another_user_cannot_read_it_and_it_expires_after_ten_minutes(): void
    {
        $alice = $this->owner();
        $bob = $this->owner();
        $location = (string) $this->actingAs($alice)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('api.token.create'), ['name' => 'A'])->headers->get('Location');

        $this->app['auth']->forgetGuards();
        $this->assertNull($this->shown((string) $this->actingAs($bob)->get($location)->getContent()), "Bob read Alice's token");
        $this->app['auth']->forgetGuards();
        $this->assertNotNull($this->shown((string) $this->actingAs($alice)->get($location)->getContent()), "Bob's attempt consumed Alice's token");

        $second = (string) $this->actingAs($alice)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('api.token.create'), ['name' => 'B'])->headers->get('Location');
        $this->travel(OneTimeSecrets::TTL_SECONDS + 1)->seconds();
        $this->assertNull($this->shown((string) $this->actingAs($alice)->get($second)->getContent()), 'shown after 10 minutes');
    }

    public function test_storefront_and_driver_tokens_use_the_same_one_time_display(): void
    {
        $alice = $this->owner();
        $store = Store::create(['user_id' => $alice->id, 'slug' => 'ots-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'S',
            'sub_title' => 't', 'description' => 'd', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1']);

        $loc = (string) $this->actingAs($alice)->post(route('storefront.token.create'), ['store_id' => $store->id, 'scopes' => ['menu:read']])->headers->get('Location');
        $this->flushSession();
        $raw = $this->shown((string) $this->actingAs($alice)->get($loc)->getContent());
        $this->assertNotNull($raw);
        $this->assertSame(StorefrontToken::where('store_id', $store->id)->sole()->token, hash('sha256', $raw));
        $this->assertNull($this->shown((string) $this->actingAs($alice)->get($loc)->getContent()));

        $loc = (string) $this->actingAs($alice)->post(route('drivers.store'), ['name' => 'Dee'])->headers->get('Location');
        $this->flushSession();
        $raw = $this->shown((string) $this->actingAs($alice)->get($loc)->getContent(), 'ui-drivers__token ui-ltr');
        $this->assertNotNull($raw);
        $this->assertSame(Driver::where('owner_id', $alice->id)->sole()->token, hash('sha256', $raw));
        $this->assertNull($this->shown((string) $this->actingAs($alice)->get($loc)->getContent(), 'ui-drivers__token ui-ltr'));
    }

    public function test_the_store_is_single_read_and_scoped_to_kind_and_user(): void
    {
        $s = app(OneTimeSecrets::class);
        $s->put('api_token', 7, 3, 'secret-x');
        $this->assertNull($s->pull('driver_token', 7, 3));
        $this->assertNull($s->pull('api_token', 8, 3));
        $this->assertNull($s->pull('api_token', 7, 'x'));
        $this->assertSame('secret-x', $s->pull('api_token', 7, '3'));
        $this->assertNull($s->pull('api_token', 7, 3));
    }
}
