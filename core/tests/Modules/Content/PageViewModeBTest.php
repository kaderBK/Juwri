<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Page View Mode B (V2): the owner can set the display source (live data vs an
 * uploaded PDF) and upload/replace the PDF that the template renders with pdf.js.
 * Tenant-scoped: an owner can only touch their own restaurant.
 */
class PageViewModeBTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureStaffDashboardPermission::class,
            Installed::class,
        ]);
        Storage::fake('public');
    }

    private function owner(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'x',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999],
            'status' => 1,
        ]);
        $user = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'x', 'description' => 'x',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'pageview');

        return [$user, $store];
    }

    public function test_owner_uploads_display_pdf_sets_source_and_stores_file(): void
    {
        [$user, $store] = $this->owner();

        $res = $this->actingAs($user)->postJson("/theme-customizer/{$store->id}/pageview-source", [
            'source' => 'pdf',
            'pdf' => UploadedFile::fake()->create('menu.pdf', 120, 'application/pdf'),
        ]);

        $res->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('pdf', store_options($store->id)->restaurant_pageview_source);
        $stored = store_options($store->id)->restaurant_pageview_pdf;
        $this->assertNotEmpty($stored);
        Storage::disk('public')->assertExists($stored);
    }

    public function test_source_data_needs_no_file(): void
    {
        [$user, $store] = $this->owner();

        $this->actingAs($user)
            ->postJson("/theme-customizer/{$store->id}/pageview-source", ['source' => 'data'])
            ->assertOk();

        $this->assertSame('data', store_options($store->id)->restaurant_pageview_source);
    }

    public function test_non_pdf_upload_is_rejected(): void
    {
        [$user, $store] = $this->owner();

        $this->actingAs($user)
            ->postJson("/theme-customizer/{$store->id}/pageview-source", [
                'source' => 'pdf',
                'pdf' => UploadedFile::fake()->image('menu.jpg'),
            ])
            ->assertStatus(422);
    }

    public function test_owner_cannot_set_source_on_another_owners_restaurant(): void
    {
        [, $victimPost] = $this->owner();
        [$attacker] = $this->owner();

        $this->actingAs($attacker)
            ->postJson("/theme-customizer/{$victimPost->id}/pageview-source", ['source' => 'data'])
            ->assertNotFound();
    }
}
