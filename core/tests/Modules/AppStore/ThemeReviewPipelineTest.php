<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\AppStore;

use App\Models\ThemeVersion;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Storefront\LighthouseGate;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;
use App\Storefront\ThemeSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * P4-4: submission → automated gates → human review → publish. Lint failures
 * auto-reject with the report; the admin queue approves (publish → resolver
 * serves) or rejects with notes.
 */
class ThemeReviewPipelineTest extends TestCase
{
    use RefreshDatabase;

    private string $zipPath;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png', 'currency_sign' => '$', 'currency_pos' => 1, 'site_admin_logo' => 'logo.png']) {
            public function __construct(private array $data) {}
            public function __get(string $key): mixed { return $this->data[$key] ?? null; }
            public function __set(string $key, mixed $value): void { $this->data[$key] = $value; }
            public function __isset(string $key): bool { return array_key_exists($key, $this->data); }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);
        view()->share(['admin_languages' => collect(), 'notifications' => collect(), 'totalUnreadNotifications' => 0, 'totalUnapprovedComments' => 0]);

        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\PreventRequestForgery::class,
        ]);

        $this->zipPath = storage_path('app/test-review-theme.zip');
    }

    protected function tearDown(): void
    {
        @unlink($this->zipPath);
        File::deleteDirectory(storage_path('app/marketplace-themes/revtest'));
        File::deleteDirectory(public_path('assets/marketplace-themes/revtest'));
        parent::tearDown();
    }

    private function makeZip(bool $dirty = false, string $version = '1.0.0'): string
    {
        @unlink($this->zipPath);
        $zip = new ZipArchive();
        $zip->open($this->zipPath, ZipArchive::CREATE);
        $zip->addFromString('theme.json', json_encode(['name' => 'Rev Test', 'slug' => 'revtest', 'version' => $version]));
        $zip->addFromString(
            'templates/index.liquid',
            $dirty
                ? '{{ restaurant.title | totally_fake_filter }}{% render "missing-snippet" %}'
                : '<h1>{{ restaurant.title | escape }}</h1>'
        );
        $zip->close();

        return $this->zipPath;
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['group_id' => '1', 'status' => 1, 'email_verified_at' => now(), 'user_type' => 'admin']);
    }

    public function test_clean_submission_waits_in_review_with_its_lint_report(): void
    {
        $version = app(ThemeSubmissionService::class)->submit($this->makeZip(), sellerId: 42);

        $this->assertSame('review', $version->status);
        $this->assertTrue($version->lint_report['passed']);
        $this->assertSame(42, $version->theme->seller_id);
        // Not published → the storefront resolver refuses it.
        $this->assertNull(MarketplaceThemeResolver::resolve('mp:revtest@1.0.0'));
    }

    public function test_dirty_submission_is_auto_rejected_with_the_report(): void
    {
        $version = app(ThemeSubmissionService::class)->submit($this->makeZip(dirty: true));

        $this->assertSame('rejected', $version->status);
        $this->assertFalse($version->lint_report['passed']);
        $rules = array_column($version->lint_report['errors'], 'rule');
        $this->assertContains('unknown-filter', $rules);
        $this->assertContains('missing-snippet', $rules);
        $this->assertStringContainsString('Automated lint failed', $version->review_notes);
    }

    public function test_failed_quality_gate_auto_rejects(): void
    {
        $version = app(ThemeSubmissionService::class)->submit($this->makeZip());

        $gate = new class extends LighthouseGate
        {
            protected function run(string $url, string $formFactor): array
            {
                return ['performance' => 30.0, 'accessibility' => 95.0];
            }
        };

        $version = app(ThemeSubmissionService::class)->runGate($version, ['https://seed.test/x'], $gate);

        $this->assertSame('rejected', $version->status);
        $this->assertFalse($version->gate_report['passed']);
        $this->assertStringContainsString('Quality gate failed', $version->review_notes);
    }

    public function test_admin_queue_approve_publishes_and_resolver_serves(): void
    {
        $version = app(ThemeSubmissionService::class)->submit($this->makeZip());
        $admin = $this->makeAdmin();

        // Queue lists the pending version.
        $this->actingAs($admin)->get('admin/theme-reviews')
            ->assertOk()->assertSee('revtest');

        $this->actingAs($admin)->post('admin/theme-reviews/'.$version->id.'/approve')
            ->assertRedirect();

        $this->assertSame('published', $version->fresh()->status);
        $this->assertSame('published', $version->fresh()->theme->status);
        $this->assertNotNull(MarketplaceThemeResolver::resolve('mp:revtest@1.0.0'), 'published version now serves');
    }

    public function test_admin_reject_requires_and_stores_notes(): void
    {
        $version = app(ThemeSubmissionService::class)->submit($this->makeZip());
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post('admin/theme-reviews/'.$version->id.'/reject', ['notes' => ''])
            ->assertSessionHasErrors('notes');

        $this->actingAs($admin)->post('admin/theme-reviews/'.$version->id.'/reject', ['notes' => 'Too rough around checkout.'])
            ->assertRedirect();

        $fresh = $version->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('Too rough around checkout.', $fresh->review_notes);
    }

    public function test_non_admin_cannot_reach_the_queue(): void
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        $resp = $this->actingAs($user)->get('admin/theme-reviews');
        $this->assertNotSame(200, $resp->status(), 'plain user must not see the review queue');
    }
}
