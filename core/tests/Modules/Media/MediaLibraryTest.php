<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Media;

use Juwri\Core\Models\Language;
use Juwri\Modules\Media\Models\MediaAsset;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

class MediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware();
    }

    protected function tearDown(): void
    {
        // Remove any physical files written during upload tests.
        foreach (MediaAsset::all() as $a) {
            $p = public_path('storage/menu/' . $a->filename);
            if (is_file($p)) {
                @unlink($p);
            }
        }
        parent::tearDown();
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create([
            'name' => 'P ' . Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);
        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'R ' . Str::random(6);
        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title) . '-' . Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_store_registers_assets(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);

        $res = $this->actingAs($owner)->postJson(route('media.store'), [
            'store_id' => $r->id,
            'folder' => 'menu',
            'images' => [
                UploadedFile::fake()->image('first.jpg', 200, 200),
                UploadedFile::fake()->image('second.png', 200, 200),
            ],
        ]);

        $res->assertOk()->assertJsonCount(2, 'assets');
        $this->assertEquals(2, MediaAsset::where('store_id', $r->id)->where('folder', 'menu')->count());
        $this->assertDatabaseHas('media_assets', ['store_id' => $r->id, 'original_name' => 'first.jpg']);
    }

    public function test_list_is_restaurant_scoped(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        MediaAsset::create(['store_id' => $r->id, 'filename' => 'a.webp', 'original_name' => 'a', 'folder' => 'menu']);

        $other = $this->makeRestaurant($this->makeUser());
        MediaAsset::create(['store_id' => $other->id, 'filename' => 'b.webp', 'original_name' => 'b', 'folder' => 'menu']);

        $this->actingAs($owner)->getJson(route('media.list', ['store_id' => $r->id]))
            ->assertOk()->assertJsonCount(1, 'assets');
    }

    public function test_tenant_isolation_on_store_and_list(): void
    {
        $owner = $this->makeUser();
        $intruderRestaurant = $this->makeRestaurant($this->makeUser());

        $this->actingAs($owner)->postJson(route('media.store'), [
            'store_id' => $intruderRestaurant->id,
            'images' => [UploadedFile::fake()->image('x.jpg', 50, 50)],
        ])->assertForbidden();

        $this->actingAs($owner)->getJson(route('media.list', ['store_id' => $intruderRestaurant->id]))
            ->assertForbidden();
    }

    public function test_destroy_removes_row(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $asset = MediaAsset::create(['store_id' => $r->id, 'filename' => 'gone.webp', 'original_name' => 'gone', 'folder' => 'other']);

        $this->actingAs($owner)->deleteJson(route('media.destroy', $asset->id))->assertOk();
        $this->assertDatabaseMissing('media_assets', ['id' => $asset->id]);
    }

    public function test_destroy_tenant_isolation(): void
    {
        $owner = $this->makeUser();
        $otherRestaurant = $this->makeRestaurant($this->makeUser());
        $asset = MediaAsset::create(['store_id' => $otherRestaurant->id, 'filename' => 'z.webp', 'original_name' => 'z', 'folder' => 'other']);

        $this->actingAs($owner)->deleteJson(route('media.destroy', $asset->id))->assertForbidden();
        $this->assertDatabaseHas('media_assets', ['id' => $asset->id]);
    }
}
