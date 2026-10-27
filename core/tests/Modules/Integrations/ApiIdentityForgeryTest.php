<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Models\ApiToken;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * H1 — trusted identity lives in request ATTRIBUTES, never client input.
 * A caller who forges `_api_user_id` (or the other identity keys) in the query
 * string or body must not be able to act as another user: the token middleware
 * strips the forged key first, then sets the real identity in attributes.
 */
class ApiIdentityForgeryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function user(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]),
            'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function storeFixture(User $user, string $title): Store
    {
        return Store::create([
            'user_id' => $user->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function token(User $user): string
    {
        $raw = Str::random(40);
        ApiToken::create(['user_id' => $user->id, 'name' => 'T', 'token' => hash('sha256', $raw), 'abilities' => ['*']]);

        return $raw;
    }

    public function test_forged_api_user_id_in_query_does_not_win(): void
    {
        $attacker = $this->user();
        $victim = $this->user();
        $this->storeFixture($attacker, 'Attacker Diner');
        $this->storeFixture($victim, 'Victim Bistro');

        // Attacker forges the victim's id in the query string.
        $res = $this->withToken($this->token($attacker))
            ->getJson(route('api.v1.stores.index').'?_api_user_id='.$victim->id);

        $res->assertOk();
        $titles = collect($res->json('data'))->pluck('title')->all();
        $this->assertContains('Attacker Diner', $titles);
        $this->assertNotContains('Victim Bistro', $titles, 'Forged _api_user_id must not expose the victim\'s data.');
    }

    public function test_forged_api_user_id_in_body_does_not_win(): void
    {
        $attacker = $this->user();
        $victim = $this->user();
        $this->storeFixture($attacker, 'Attacker Grill');
        $this->storeFixture($victim, 'Victim Cafe');

        // Attacker forges the victim's id in the JSON body.
        $res = $this->withToken($this->token($attacker))
            ->json('GET', route('api.v1.stores.index'), ['_api_user_id' => $victim->id]);

        $res->assertOk();
        $titles = collect($res->json('data'))->pluck('title')->all();
        $this->assertContains('Attacker Grill', $titles);
        $this->assertNotContains('Victim Cafe', $titles);
    }
}
