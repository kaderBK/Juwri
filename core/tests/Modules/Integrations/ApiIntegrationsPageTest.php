<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * D3 + D4 (integrations pack): the API & Webhooks page grows the automation
 * "Connect" cards (pre-scoped token mint) and the dead-endpoint warning banner.
 */
class ApiIntegrationsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->shareLayoutGlobals();
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $owner;
    }

    public function test_connect_cards_render(): void
    {
        $owner = $this->owner();
        $res = $this->actingAs($owner)->get(route('api.index'))->assertStatus(200);
        $res->assertSeeHtml('connect-tools')->assertSeeHtml('data-connect="zapier"')->assertSeeHtml('data-connect="make"')->assertSeeHtml('data-connect="n8n"');
    }

    public function test_resuming_an_auto_disabled_endpoint_starts_a_clean_failure_streak(): void
    {
        $owner = $this->owner();
        $ep = WebhookEndpoint::create([
            'user_id' => $owner->id, 'url' => 'https://example.com/h', 'events' => ['order.created'], 'secret' => Str::random(32),
            'active' => false, 'consecutive_failures' => WebhookEndpoint::MAX_CONSECUTIVE_FAILURES,
            'disabled_reason' => 'Auto-disabled after 15 consecutive delivery failures.',
        ]);

        $this->actingAs($owner)->post(route('api.webhook.toggle', $ep->id))->assertRedirect();

        $ep->refresh();
        $this->assertTrue($ep->active);
        $this->assertSame(0, (int) $ep->consecutive_failures, 'a resumed endpoint is not one failure away from being disabled again');
        $this->assertNull($ep->disabled_reason);
        $this->actingAs($owner)->get(route('api.index'))->assertDontSeeHtml('data-testid="dead-endpoint-banner"');

        $ep->recordDeliveryResult(false);
        $this->assertTrue($ep->fresh()->active, 'one failure after resuming leaves it on');

        // pausing a healthy endpoint does not touch its history
        $this->actingAs($owner)->post(route('api.webhook.toggle', $ep->id))->assertRedirect();
        $ep->refresh();
        $this->assertFalse($ep->active);
        $this->assertSame(1, (int) $ep->consecutive_failures);
    }

    public function test_dead_endpoint_banner_only_when_auto_disabled(): void
    {
        $owner = $this->owner();

        // Healthy endpoint → no banner.
        WebhookEndpoint::create(['user_id' => $owner->id, 'url' => 'https://93.184.216.34/ok', 'events' => ['order.created'], 'secret' => Str::random(32), 'active' => true]);
        $this->actingAs($owner)->get(route('api.index'))->assertStatus(200)->assertDontSeeHtml('dead-endpoint-banner');

        // Auto-disabled endpoint → banner shows.
        WebhookEndpoint::create(['user_id' => $owner->id, 'url' => 'https://93.184.216.34/dead', 'events' => ['order.created'], 'secret' => Str::random(32), 'active' => false, 'disabled_reason' => 'Auto-disabled after 15 consecutive delivery failures.']);
        $this->actingAs($owner)->get(route('api.index'))->assertStatus(200)->assertSeeHtml('dead-endpoint-banner');
    }

    public function test_connect_form_mints_pre_scoped_token(): void
    {
        $owner = $this->owner();
        // password.confirm guards api.token.create — mark it confirmed for this test.
        session(['auth.password_confirmed_at' => time()]);

        $this->actingAs($owner)->post(route('api.token.create'), [
            'name'      => 'Zapier connection',
            'abilities' => ['webhooks:write', 'orders:read', 'menu:read'],
        ])->assertRedirect();

        $token = ApiToken::where('user_id', $owner->id)->where('name', 'Zapier connection')->firstOrFail();
        $this->assertEqualsCanonicalizing(['webhooks:write', 'orders:read', 'menu:read'], $token->abilities);
    }
}
