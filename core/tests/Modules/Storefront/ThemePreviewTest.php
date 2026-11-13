<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\Theme;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * P4-5: theme previews render against the DEMO dataset — public for published
 * themes, admin-only for review-queue versions, never live data, no license.
 */
class ThemePreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\PreventRequestForgery::class,
        ]);
        config(['settings.currency_sign' => '$', 'settings.currency_pos' => 1, 'settings.currency_code' => 'USD']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/marketplace-themes/prevtest'));
        parent::tearDown();
    }

    private function makeTheme(string $themeStatus, string $versionStatus, ?string $liquid = null): Theme
    {
        File::ensureDirectoryExists(storage_path('app/marketplace-themes/prevtest/1.0.0/templates'));
        File::put(
            storage_path('app/marketplace-themes/prevtest/1.0.0/templates/index.liquid'),
            $liquid ?? '<h1 class="prev-theme">{{ restaurant.title | escape }}</h1>'
                .'{% for c in menu.categories %}<h2>{{ c.name }}</h2>{% for i in c.items %}<p>{{ i.name }} {{ i.price | money }}</p>{% endfor %}{% endfor %}'
                .'{% commerce %}'
        );

        $theme = Theme::create(['name' => 'Prev Test', 'slug' => 'prevtest', 'status' => $themeStatus, 'price' => 49]);
        $theme->versions()->create([
            'semver' => '1.0.0',
            'manifest' => ['name' => 'Prev Test', 'slug' => 'prevtest', 'version' => '1.0.0'],
            'checksum' => str_repeat('b', 64),
            'status' => $versionStatus,
        ]);

        return $theme;
    }

    public function test_published_theme_previews_publicly_with_demo_data_and_no_license(): void
    {
        $this->makeTheme('published', 'published');

        $html = $this->get('marketplace/prevtest/preview')->assertOk()->getContent();

        $this->assertStringContainsString('prev-theme', $html, 'theme markup rendered');
        $this->assertStringContainsString('Demo Bistro', $html, 'demo restaurant, not live data');
        $this->assertStringContainsString('Signature Burger', $html, 'demo items through the Drop contract');
        $this->assertStringContainsString('window.MB_COMMERCE=', $html, 'commerce runtime emitted');
        $this->assertStringContainsString('demo-bistro', $html); // slug in the commerce config
    }

    public function test_review_version_is_admin_only(): void
    {
        $this->makeTheme('review', 'review');

        // Guest → 404 (unpublished theme).
        $this->get('marketplace/prevtest/preview')->assertNotFound();
        $this->get('marketplace/prevtest/preview?version=1.0.0')->assertNotFound();

        // Admin with explicit version → renders.
        $admin = User::factory()->create(['group_id' => '1', 'status' => 1, 'email_verified_at' => now(), 'user_type' => 'admin']);
        $this->actingAs($admin)->get('marketplace/prevtest/preview?version=1.0.0')
            ->assertOk()->assertSee('Demo Bistro');
    }

    public function test_broken_theme_previews_as_a_422_not_a_blank_page(): void
    {
        // Structural break the lenient live env cannot recover from: the
        // sectioned engine requires a template JSON — point at a missing one.
        $this->makeTheme('published', 'published');
        File::delete(storage_path('app/marketplace-themes/prevtest/1.0.0/templates/index.liquid'));
        File::put(
            storage_path('app/marketplace-themes/prevtest/1.0.0/templates/index.json'),
            '{"sections": "not-an-object", "order": 12' // invalid JSON
        );

        $resp = $this->get('marketplace/prevtest/preview');

        $resp->assertStatus(422);
        $this->assertStringContainsString('failed to render', $resp->getContent());
    }
}
