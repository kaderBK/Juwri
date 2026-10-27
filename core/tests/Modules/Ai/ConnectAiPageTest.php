<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ai;

use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** MCP-expansion P4-1: the owner "Connect AI" dashboard page. */
class ConnectAiPageTest extends TestCase
{
    use RefreshDatabase;

    private function boot(): User
    {
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'Test', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png']) {
            public function __construct(private array $data) {}
            public function __get(string $key): mixed { return $this->data[$key] ?? null; }
            public function __set(string $key, mixed $value): void { $this->data[$key] = $value; }
            public function __isset(string $key): bool { return array_key_exists($key, $this->data); }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);

        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\PreventRequestForgery::class,
            \App\Http\Middleware\EnsureStaffDashboardPermission::class,
        ]);

        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
        return $owner;
    }

    public function test_connect_ai_page_shows_all_endpoints_and_snippets(): void
    {
        $owner = $this->boot();
        $html = $this->actingAs($owner)->get(route('connect-ai'))->assertOk()->getContent();

        $this->assertStringContainsString(url('/mcp'), $html);
        $this->assertStringContainsString(url('/mcp/storefront'), $html);
        $this->assertStringContainsString(url('/mcp/customer'), $html);
        $this->assertStringContainsString(url('/mcp/catalog'), $html);
        $this->assertStringContainsString('claude mcp add', $html);
        $this->assertStringContainsString('claude_desktop_config.json', $html);
        $this->assertStringContainsString('.cursor/mcp.json', $html);
    }

    public function test_connect_ai_page_renders_arabic(): void
    {
        $owner = $this->boot();
        app()->setLocale('ar');
        $_COOKIE['Quick_user_lang_code'] = 'ar';

        $html = $this->actingAs($owner)->get(route('connect-ai'))->assertOk()->getContent();
        // A translated heading proves the ___() strings resolve in Arabic.
        $this->assertStringContainsString('اربط مساعدي الذكاء الاصطناعي', $html);
        unset($_COOKIE['Quick_user_lang_code']);
    }
}
