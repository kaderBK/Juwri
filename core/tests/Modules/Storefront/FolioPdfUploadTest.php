<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Folio (flip-book theme) — B2: the owner uploads/replaces/removes the display
 * PDF and sets the content source. The file lives under the repo-root
 * storage/folio-pdf/ directory (public_path() IS the repo root), the option
 * contract is rev/folio-theme/SPEC.md §3, and responses follow the
 * imageMenuResponse() idiom: redirect + flash for HTML form posts, JSON for
 * AJAX. Tenant-scoped: an owner can only touch their own restaurant.
 */
class FolioPdfUploadTest extends TestCase
{
    use RefreshDatabase;

    /** Files this test created on the real disk; unlinked in tearDown. */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureStaffDashboardPermission::class,
            Installed::class,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->createdFiles = [];
        parent::tearDown();
    }

    /** Remember (and later clean up) the file the endpoint just stored. */
    private function trackStored(int $storeId): string
    {
        $stored = (string) (store_options($storeId)->restaurant_folio_pdf ?? '');
        if ($stored !== '') {
            $this->createdFiles[] = public_path('storage/'.$stored);
        }

        return $stored;
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
        StoreOption::updateStoreOption($store->id, 'store_template', 'folio');

        return [$user, $store];
    }

    private function fakePdf(): UploadedFile
    {
        return UploadedFile::fake()->create('menu.pdf', 120, 'application/pdf');
    }

    public function test_html_form_upload_redirects_with_flash_and_stores_the_file(): void
    {
        [$user, $store] = $this->owner();

        $res = $this->actingAs($user)->post("/theme-customizer/{$store->id}/folio-pdf", [
            'source' => 'pdf',
            'pdf' => $this->fakePdf(),
        ], ['Accept' => 'text/html']);

        $res->assertRedirect()->assertSessionHas('app_alert_type', 'success');

        $stored = $this->trackStored($store->id);
        $this->assertStringStartsWith('folio-pdf/', $stored);
        $this->assertFileExists(public_path('storage/'.$stored));
        $this->assertSame('pdf', store_options($store->id)->restaurant_folio_source);
    }

    public function test_ajax_upload_returns_json_with_state(): void
    {
        [$user, $store] = $this->owner();

        $res = $this->actingAs($user)->postJson("/theme-customizer/{$store->id}/folio-pdf", [
            'source' => 'pdf',
            'pdf' => $this->fakePdf(),
        ]);

        $res->assertOk()->assertJson(['success' => true])
            ->assertJsonPath('folio.source', 'pdf');

        $stored = $this->trackStored($store->id);
        $this->assertFileExists(public_path('storage/'.$stored));
    }

    public function test_wrong_mime_is_rejected(): void
    {
        [$user, $store] = $this->owner();

        // AJAX: JSON failure payload
        $this->actingAs($user)->postJson("/theme-customizer/{$store->id}/folio-pdf", [
            'source' => 'pdf',
            'pdf' => UploadedFile::fake()->image('menu.jpg'),
        ])->assertOk()->assertJson(['success' => false]);

        // HTML form: redirect + error flash
        $this->actingAs($user)->post("/theme-customizer/{$store->id}/folio-pdf", [
            'source' => 'pdf',
            'pdf' => UploadedFile::fake()->image('menu.jpg'),
        ], ['Accept' => 'text/html'])
            ->assertRedirect()->assertSessionHas('app_alert_type', 'error');

        $this->assertEmpty((string) (store_options($store->id)->restaurant_folio_pdf ?? ''));
    }

    public function test_foreign_owner_is_rejected(): void
    {
        [, $victimPost] = $this->owner();
        [$attacker] = $this->owner();

        $this->actingAs($attacker)->postJson("/theme-customizer/{$victimPost->id}/folio-pdf", [
            'source' => 'pdf', 'pdf' => $this->fakePdf(),
        ])->assertNotFound();

        $this->assertEmpty((string) (store_options($victimPost->id)->restaurant_folio_pdf ?? ''));
    }

    public function test_replace_unlinks_the_previous_file(): void
    {
        [$user, $store] = $this->owner();

        $this->actingAs($user)->postJson("/theme-customizer/{$store->id}/folio-pdf", [
            'source' => 'pdf', 'pdf' => $this->fakePdf(),
        ])->assertOk();
        $first = $this->trackStored($store->id);
        $this->assertFileExists(public_path('storage/'.$first));

        $this->actingAs($user)->postJson("/theme-customizer/{$store->id}/folio-pdf", [
            'source' => 'pdf', 'pdf' => $this->fakePdf(),
        ])->assertOk();
        $second = $this->trackStored($store->id);

        $this->assertNotSame($first, $second);
        $this->assertFileDoesNotExist(public_path('storage/'.$first));
        $this->assertFileExists(public_path('storage/'.$second));
    }

    public function test_remove_clears_both_options_and_the_file(): void
    {
        [$user, $store] = $this->owner();

        $this->actingAs($user)->postJson("/theme-customizer/{$store->id}/folio-pdf", [
            'source' => 'pdf', 'pdf' => $this->fakePdf(),
        ])->assertOk();
        $stored = $this->trackStored($store->id);
        $this->assertFileExists(public_path('storage/'.$stored));

        $this->actingAs($user)->post("/theme-customizer/{$store->id}/folio-pdf", [
            'action' => 'remove',
        ], ['Accept' => 'text/html'])
            ->assertRedirect()->assertSessionHas('app_alert_type', 'success');

        $this->assertFileDoesNotExist(public_path('storage/'.$stored));
        $this->assertSame('', (string) store_options($store->id)->restaurant_folio_pdf);
        $this->assertSame('images', store_options($store->id)->restaurant_folio_source);
    }

    public function test_source_only_save_writes_the_option(): void
    {
        [$user, $store] = $this->owner();

        $this->actingAs($user)->postJson("/theme-customizer/{$store->id}/folio-pdf", [
            'source' => 'images',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertSame('images', store_options($store->id)->restaurant_folio_source);
    }
}
