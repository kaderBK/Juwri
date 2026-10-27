<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\WebhookEndpoint;
use App\Services\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Channels\Models\AggregatorConnection;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * D1 (integrations pack): the owner Integrations hub renders its cards from real
 * data when `integrations` is enabled, and is gated when the kill-switch is off.
 */
class IntegrationsHubTest extends TestCase
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
        $this->shareLayoutGlobals(); // $activeTheme/$settings/etc. (APP_INSTALLED=false skips the composer)
    }

    /** @return array{0: User, 1: Store} */
    private function owner(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $store];
    }

    public function test_hub_renders_all_cards_when_enabled(): void
    {
        config(['settings.admin_allow_integrations' => true]);
        [$owner] = $this->owner();

        $res = $this->actingAs($owner)->get(route('integrations.index'))->assertStatus(200);
        $res->assertSee('Deliverect')->assertSee('Zapier')->assertSee('Make')->assertSee('n8n');
        $res->assertSeeHtml('integration-cards'); // the grid rendered
    }

    public function test_status_chips_reflect_real_data(): void
    {
        config(['settings.admin_allow_integrations' => true]);
        [$owner, $store] = $this->owner();

        // A connected Deliverect location + a webhook endpoint → connected chips.
        AggregatorConnection::create(['provider' => 'deliverect', 'store_id' => $store->id, 'external_store_id' => 'C1', 'status' => 'active']);
        WebhookEndpoint::create(['user_id' => $owner->id, 'url' => 'https://93.184.216.34/x', 'events' => ['order.created'], 'secret' => Str::random(32), 'active' => true]);

        $res = $this->actingAs($owner)->get(route('integrations.index'))->assertStatus(200);
        $res->assertSeeHtml('data-integration="deliverect"')->assertSeeHtml('data-status="connected"');
    }

    public function test_kill_switch_off_gates_the_hub(): void
    {
        config(['settings.admin_allow_integrations' => false]);
        FeatureAccess::flush();
        [$owner] = $this->owner();

        // integrations is plan_gated=0 at foundation → the kill-switch refuses (403),
        // exactly like every other dark-off feature (FeatureGateAuditTest).
        $this->actingAs($owner)->get(route('integrations.index'))->assertStatus(403);
    }
}
