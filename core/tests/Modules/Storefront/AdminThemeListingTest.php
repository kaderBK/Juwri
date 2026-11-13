<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\MarketplaceMedia;
use App\Models\Theme;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * stores P1-4: the admin theme-listing surface — category/tagline editor and the
 * screenshot gallery (upload validation for mime/size/count, plus move + delete).
 */
class AdminThemeListingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            \App\Http\Middleware\AdminMiddleware::class,
            \Juwri\Core\Http\Middleware\IsDemoMode::class,
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\PreventRequestForgery::class,
        ]);
        $this->actingAs(User::factory()->create(['status' => 1, 'email_verified_at' => now()]));
    }

    private function makeTheme(): Theme
    {
        return Theme::create([
            'name' => 'Noir', 'slug' => 'noir', 'status' => 'published', 'price' => 49, 'category' => 'fine_dining',
        ]);
    }

    private function seedShots(Theme $theme, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $theme->media()->create(['disk_path' => "storage/marketplace/theme/{$theme->id}/s{$i}.webp", 'position' => $i, 'kind' => 'screenshot']);
        }
    }

    private function cleanup(Theme $theme): void
    {
        File::deleteDirectory(public_path("storage/marketplace/theme/{$theme->id}"));
    }

    // Page RENDER (full admin layout) is browser-verified in e2e/p1-4-admin-listing.mjs:
    // the layout needs a global $settings that withoutMiddleware() strips here, so we
    // cover the mutation endpoints below and let the browser prove the view renders.

    public function test_upload_rejects_non_image(): void
    {
        $theme = $this->makeTheme();
        $res = $this->post(route('admin.theme-reviews.media.upload', $theme->id), [
            'screenshots' => [UploadedFile::fake()->create('menu.pdf', 200, 'application/pdf')],
        ]);
        $res->assertSessionHasErrors('screenshots.0');
        $this->assertSame(0, $theme->media()->count());
    }

    public function test_upload_rejects_oversize(): void
    {
        $theme = $this->makeTheme();
        $maxKb = (int) config('marketplace.media.max_kb');
        $res = $this->post(route('admin.theme-reviews.media.upload', $theme->id), [
            'screenshots' => [UploadedFile::fake()->image('big.png', 1280, 800)->size($maxKb + 500)],
        ]);
        $res->assertSessionHasErrors('screenshots.0');
        $this->assertSame(0, $theme->media()->count());
    }

    public function test_upload_rejects_over_count(): void
    {
        $theme = $this->makeTheme();
        $this->seedShots($theme, (int) config('marketplace.media.max_screenshots')); // fill to max
        $res = $this->post(route('admin.theme-reviews.media.upload', $theme->id), [
            'screenshots' => [UploadedFile::fake()->image('one.png', 1280, 800)],
        ]);
        $res->assertSessionHasErrors('screenshots');
        $this->assertSame((int) config('marketplace.media.max_screenshots'), $theme->media()->count());
    }

    public function test_upload_creates_media(): void
    {
        $theme = $this->makeTheme();
        $res = $this->post(route('admin.theme-reviews.media.upload', $theme->id), [
            'screenshots' => [
                UploadedFile::fake()->image('a.png', 1280, 800),
                UploadedFile::fake()->image('b.png', 1280, 800),
            ],
        ]);
        $res->assertRedirect(route('admin.theme-reviews.listing', $theme->id));
        $this->assertSame(2, $theme->media()->count());
        $this->assertSame([1, 2], $theme->media()->orderBy('position')->pluck('position')->all());
        $this->cleanup($theme);
    }

    public function test_move_reorders(): void
    {
        $theme = $this->makeTheme();
        $this->seedShots($theme, 3);
        $ids = $theme->media()->orderBy('position')->pluck('id')->all();

        $this->post(route('admin.theme-reviews.media.move', $theme->id), ['media_id' => $ids[0], 'dir' => 'down'])
            ->assertRedirect(route('admin.theme-reviews.listing', $theme->id));

        $after = $theme->media()->orderBy('position')->pluck('id')->all();
        $this->assertSame([$ids[1], $ids[0], $ids[2]], $after); // first swapped past second
    }

    public function test_delete_removes_media(): void
    {
        $theme = $this->makeTheme();
        $this->seedShots($theme, 2);
        $victim = $theme->media()->orderBy('position')->first();

        $this->post(route('admin.theme-reviews.media.delete', $victim->id))
            ->assertRedirect(route('admin.theme-reviews.listing', $theme->id));

        $this->assertNull(MarketplaceMedia::find($victim->id));
        $this->assertSame(1, $theme->media()->count());
    }

    public function test_save_listing_rejects_unknown_category(): void
    {
        $theme = $this->makeTheme();
        $this->post(route('admin.theme-reviews.listing.save', $theme->id), [
            'category' => 'not_a_real_category', 'tagline' => 'x',
        ])->assertSessionHasErrors('category');
    }

    public function test_save_listing_updates_metadata(): void
    {
        $theme = $this->makeTheme();
        $this->post(route('admin.theme-reviews.listing.save', $theme->id), [
            'category' => 'cafe', 'tagline' => 'Warm cafe theme', 'support_url' => 'https://help.test/x',
        ])->assertRedirect(route('admin.theme-reviews.listing', $theme->id));

        $theme->refresh();
        $this->assertSame('cafe', $theme->category);
        $this->assertSame('Warm cafe theme', $theme->tagline);
        // Listing edit must NOT touch commerce/review state.
        $this->assertSame('published', $theme->status);
        $this->assertEquals(49, (int) $theme->price);
    }
}
