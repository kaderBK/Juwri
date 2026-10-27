<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Ai\Models\AiUsage;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Superadmin operations pages: the Webhooks observability page
 * (endpoints + recent deliveries) and the AI Usage page (aggregates
 * per provider/feature/user), plus access control on both.
 */
class AdminOpsPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png', 'currency_sign' => '$', 'currency_pos' => 1]) {
            public function __construct(private array $data) {}
            public function __get(string $key): mixed { return $this->data[$key] ?? null; }
            public function __set(string $key, mixed $value): void { $this->data[$key] = $value; }
            public function __isset(string $key): bool { return array_key_exists($key, $this->data); }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);
        view()->share([
            'admin_languages' => collect(),
            'notifications' => collect(),
            'totalUnreadNotifications' => 0,
            'totalUnapprovedComments' => 0,
        ]);

        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\PreventRequestForgery::class,
        ]);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['group_id' => '1', 'status' => 1, 'email_verified_at' => now(), 'user_type' => 'admin']);
    }

    private function makeOwner(): User
    {
        return User::factory()->create(['group_id' => '2', 'status' => 1, 'email_verified_at' => now()]);
    }

    /* ---------------------------------------------------------------- */
    /* Webhooks page                                                    */
    /* ---------------------------------------------------------------- */

    public function test_webhooks_page_shows_endpoints_deliveries_and_stats(): void
    {
        $admin = $this->makeAdmin();
        $owner = $this->makeOwner();

        $live = WebhookEndpoint::forceCreate([
            'user_id' => $owner->id,
            'url' => 'https://alpha.example.com/hooks',
            'events' => ['order.created', 'order.paid', 'order.status_changed', 'order.cancelled'],
            'active' => true,
            'consecutive_failures' => 0,
        ]);
        WebhookEndpoint::forceCreate([
            'user_id' => $owner->id,
            'url' => 'https://beta.example.com/hooks',
            'events' => ['order.paid'],
            'active' => true,
            'consecutive_failures' => 0,
        ]);
        WebhookEndpoint::forceCreate([
            'user_id' => $owner->id,
            'url' => 'https://dead.example.com/hooks',
            'events' => ['order.created'],
            'active' => false,
            'consecutive_failures' => 15,
            'disabled_reason' => 'Auto-disabled after 15 consecutive delivery failures.',
        ]);

        // 4 deliveries in the last 7 days: 3 success + 1 failed => 25% failure rate.
        foreach (range(1, 3) as $i) {
            WebhookDelivery::forceCreate([
                'endpoint_id' => $live->id,
                'event' => 'order.paid',
                'http_status' => 200,
                'attempt' => 1,
                'success' => true,
                'created_at' => now()->subHours($i),
            ]);
        }
        WebhookDelivery::forceCreate([
            'endpoint_id' => $live->id,
            'event' => 'order.created',
            'http_status' => 500,
            'attempt' => 3,
            'success' => false,
            'created_at' => now()->subHours(4),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.webhooks.index'));

        $response->assertStatus(200);
        $response->assertSee('Endpoints');
        // ___('Recent deliveries') resolves to the pre-existing lang key 'recent_deliveries'.
        $response->assertSee('Recent Deliveries');
        $response->assertSee('https://alpha.example.com/hooks');
        $response->assertSee('https://dead.example.com/hooks');
        $response->assertSee($owner->name);
        $response->assertSee($owner->email);
        $response->assertSee(route('admin.users.edit', $owner->id));
        // Stats: 3 endpoints total, 2 active, 4 deliveries, 25% failure rate.
        $response->assertSee('Total endpoints');
        $response->assertSee('25%');
        // Events overflow: 4 events on the first endpoint => 3 badges + "+1".
        $response->assertSee('order.status_changed');
        $response->assertSee('+1');
        // Disabled badge carries the reason as a title attribute.
        $response->assertSee('Auto-disabled after 15 consecutive delivery failures.');
        $response->assertSee('Disabled');
    }

    public function test_webhooks_filters_narrow_endpoints_and_deliveries(): void
    {
        $admin = $this->makeAdmin();
        $owner = $this->makeOwner();

        WebhookEndpoint::forceCreate([
            'user_id' => $owner->id,
            'url' => 'https://alive.example.com/hooks',
            'events' => ['order.paid'],
            'active' => true,
            'consecutive_failures' => 0,
        ]);
        $dead = WebhookEndpoint::forceCreate([
            'user_id' => $owner->id,
            'url' => 'https://gone.example.com/hooks',
            'events' => ['order.paid'],
            'active' => false,
            'consecutive_failures' => 15,
            'disabled_reason' => 'Manually disabled.',
        ]);

        WebhookDelivery::forceCreate([
            'endpoint_id' => $dead->id,
            'event' => 'evt.was.delivered',
            'http_status' => 200,
            'attempt' => 1,
            'success' => true,
            'created_at' => now()->subHour(),
        ]);
        WebhookDelivery::forceCreate([
            'endpoint_id' => $dead->id,
            'event' => 'evt.was.rejected',
            'http_status' => 503,
            'attempt' => 2,
            'success' => false,
            'created_at' => now()->subHours(2),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.webhooks.index', ['status' => 'disabled', 'result' => 'failed']));

        $response->assertStatus(200);
        $response->assertSee('https://gone.example.com/hooks');
        $response->assertDontSee('https://alive.example.com/hooks');
        $response->assertSee('evt.was.rejected');
        $response->assertDontSee('evt.was.delivered');
    }

    /* ---------------------------------------------------------------- */
    /* AI Usage page                                                    */
    /* ---------------------------------------------------------------- */

    private function seedAiUsage(User $owner): void
    {
        foreach (range(1, 2) as $i) {
            AiUsage::forceCreate([
                'user_id' => $owner->id,
                'provider' => 'kimi',
                'model' => 'kimi-k2',
                'feature' => 'menu_scan',
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'fell_back' => false,
                'created_at' => now()->subDays($i),
            ]);
        }
        AiUsage::forceCreate([
            'user_id' => null,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'feature' => 'chatbot',
            'prompt_tokens' => 40,
            'completion_tokens' => 10,
            'fell_back' => true,
            'created_at' => now()->subDays(3),
        ]);
        // Outside every selectable range once the default (30d) applies.
        AiUsage::forceCreate([
            'user_id' => $owner->id,
            'provider' => 'oldprov',
            'model' => 'legacy-1',
            'feature' => 'menu_scan',
            'prompt_tokens' => 9999,
            'completion_tokens' => 9999,
            'fell_back' => false,
            'created_at' => now()->subDays(45),
        ]);
    }

    public function test_ai_usage_page_shows_aggregates_for_default_range(): void
    {
        $admin = $this->makeAdmin();
        $owner = $this->makeOwner();
        $this->seedAiUsage($owner);

        $response = $this->actingAs($admin)->get(route('admin.ai-usage.index'));

        $response->assertStatus(200);
        $response->assertSee('By provider');
        $response->assertSee('By feature');
        $response->assertSee('Top users');
        $response->assertSee('Recent generations');
        // Aggregates: 3 generations, 350 tokens (2x150 + 50), 33.3% fallback, 1 distinct user.
        $response->assertSee('350');
        $response->assertSee('33.3%');
        $response->assertSee('kimi');
        $response->assertSee('openai');
        $response->assertSee('menu_scan');
        $response->assertSee('chatbot');
        // Null user_id renders as System; real user links to their edit page.
        $response->assertSee('System');
        $response->assertSee($owner->name);
        $response->assertSee(route('admin.users.edit', $owner->id));
        // The 45-day-old row is outside the default 30-day range.
        $response->assertDontSee('oldprov');
    }

    public function test_ai_usage_rejects_unknown_range_and_falls_back_to_30_days(): void
    {
        $admin = $this->makeAdmin();
        $owner = $this->makeOwner();
        $this->seedAiUsage($owner);

        $response = $this->actingAs($admin)->get(route('admin.ai-usage.index', ['days' => 999]));

        $response->assertStatus(200);
        $response->assertSee('350');
        $response->assertDontSee('oldprov');

        // A whitelisted wider range does include the old row.
        $response = $this->actingAs($admin)->get(route('admin.ai-usage.index', ['days' => 90]));
        $response->assertStatus(200);
        $response->assertSee('oldprov');
    }

    /* ---------------------------------------------------------------- */
    /* Access control                                                   */
    /* ---------------------------------------------------------------- */

    public function test_non_admin_cannot_open_either_page(): void
    {
        $user = $this->makeOwner();

        $this->actingAs($user)->get(route('admin.webhooks.index'))->assertRedirect();
        $this->actingAs($user)->get(route('admin.ai-usage.index'))->assertRedirect();
    }
}
