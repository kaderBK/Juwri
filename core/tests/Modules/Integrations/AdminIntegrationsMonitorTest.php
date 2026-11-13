<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Modules\Integrations\Models\WebhookDelivery;
use Tests\TestCase;

/**
 * D5 (integrations pack): the superadmin integrations monitor — failure
 * leaderboard, Deliverect location table, kill-switch state. Admin-gated, no
 * feature gate (a superadmin always sees it, even while the feature ships dark).
 */
class AdminIntegrationsMonitorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->admin = User::factory()->create(['group_id' => '1', 'user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
        $_SERVER += ['SERVER_SOFTWARE' => 'phpunit', 'SERVER_PROTOCOL' => 'HTTP/1.1', 'HTTP_HOST' => 'localhost'];

        // Every settings key the admin chrome (layout + sidebar) reads, so the real
        // array_to_object stdClass never trips on an undefined prop.
        $this->shareLayoutGlobals([
            'site_admin_logo' => 'logo.png', 'site_logo_footer' => 'logo.png',
            'blog_enable' => 0, 'enable_contact_page' => 0, 'enable_faqs' => 0,
        ], true);
        Cache::flush();
    }

    public function test_monitor_renders_even_when_dark(): void
    {
        config(['settings.admin_allow_integrations' => false, 'settings.admin_allow_aggregator_deliverect' => false]);

        $res = $this->actingAs($this->admin)->get(route('admin.integrations.index'))->assertOk();
        $res->assertSeeHtml('admin-integrations')->assertSeeHtml('data-killswitch="integrations"')->assertSeeHtml('data-killswitch="aggregator_deliverect"')->assertSeeHtml('data-state="off"'); // both dark
    }

    public function test_leaderboard_ranks_failing_tenants(): void
    {
        $owner = User::factory()->create(['group_id' => '2', 'status' => 1, 'email_verified_at' => now(), 'name' => 'Busy Bistro']);
        $endpoint = WebhookEndpoint::create(['user_id' => $owner->id, 'url' => 'https://93.184.216.34/x', 'events' => ['order.created'], 'secret' => Str::random(32), 'active' => true]);
        foreach (range(1, 3) as $i) {
            WebhookDelivery::create(['endpoint_id' => $endpoint->id, 'event' => 'order.created', 'success' => false, 'http_status' => 500, 'attempt' => 1]);
        }

        $res = $this->actingAs($this->admin)->get(route('admin.integrations.index'))->assertOk();
        $res->assertSee('Busy Bistro')->assertSeeHtml('data-tenant="'.$owner->id.'"');
    }

    public function test_deliverect_locations_listed(): void
    {
        $owner = User::factory()->create(['group_id' => '2', 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Corner Cafe', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        AggregatorConnection::create(['provider' => 'deliverect', 'store_id' => $store->id, 'external_store_id' => 'CHLINK-Z', 'status' => 'active']);

        $res = $this->actingAs($this->admin)->get(route('admin.integrations.index'))->assertOk();
        $res->assertSee('Corner Cafe')->assertSee('CHLINK-Z');
    }

    public function test_non_admin_cannot_see_monitor(): void
    {
        $owner = User::factory()->create(['group_id' => '2', 'status' => 1, 'email_verified_at' => now()]);
        // AdminMiddleware bounces a non-admin (redirect), never renders the console.
        $this->actingAs($owner)->get(route('admin.integrations.index'))->assertRedirect();
    }
}
