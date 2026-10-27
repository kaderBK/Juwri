<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Store\StoreSettingsService;
use Juwri\Modules\Media\Services\StorageQuota;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-320 / P6-321 — uploads outside the media library must respect the
 * per-tenant StorageQuota, notification sounds are capped at 10 per store, and
 * a bad menu font is reported instead of silently dropped.
 */
class UploadsQuotaTest extends TestCase
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

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(6), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999], 'status' => 1,
        ]);

        return User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
        ]);
    }

    private function store(User $owner): Store
    {
        $title = 'R '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return list<string> */
    private function soundFiles(Store $store): array
    {
        return Storage::disk('public')->files('notif-sounds/'.$store->id);
    }

    /** @return array<string, mixed> */
    private function basePayload(Store $store): array
    {
        return [
            'color' => '#111',
            'title' => $store->title,
            'sub_title' => $store->sub_title,
            'description' => $store->description,
            'address' => $store->address,
            'phone' => $store->phone,
            'slug' => $store->slug,
            'currency_code' => 'USD',
            'currency_sign' => '$',
            'currency_pos' => '1',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, UploadedFile>  $files
     */
    private function updateRequest(Store $store, array $overrides = [], array $files = []): Request
    {
        return Request::create(
            '/stores/'.$store->id,
            'PUT',
            array_merge($this->basePayload($store), $overrides),
            [],
            $files
        );
    }

    private function removeFont(?string $saved): void
    {
        if (! $saved) {
            return;
        }
        foreach ([public_path('storage/fonts/'.$saved), public_path('fonts/'.$saved)] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        foreach (['fonts/'.$saved, 'menu-fonts/'.$saved, 'storage/fonts/'.$saved] as $rel) {
            Storage::disk('public')->delete($rel);
        }
    }

    public function test_subtracting_the_whole_counted_total_releases_it(): void
    {
        $owner = $this->owner();
        StorageQuota::put($owner->id, 500);
        StorageQuota::sub($owner->id, 500);
        $this->assertSame(0, StorageQuota::used($owner->id));
    }

    // ── notification sounds ───────────────────────────────────────────────────

    public function test_sound_upload_counts_storage_and_delete_releases_it(): void
    {
        Storage::fake('public');
        $owner = $this->owner();
        $store = $this->store($owner);
        $before = StorageQuota::used($owner->id);

        $file = UploadedFile::fake()->create('a.mp3', 10, 'audio/mpeg');
        $size = $file->getSize();

        $res = $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $store->id])
            ->post(route('dashboard.sounds.upload'), ['sound' => $file])
            ->assertOk();

        $custom = collect($res->json('library'))->firstWhere('custom', true);
        $this->assertNotNull($custom, 'the uploaded sound is in the store library');
        $this->assertStringStartsWith('/storage/notif-sounds/'.$store->id.'/', $custom['id']);
        $this->assertTrue(Storage::disk('public')->exists(substr($custom['id'], strlen('/storage/'))));
        $this->assertCount(1, $this->soundFiles($store));
        $this->assertSame($before + $size, StorageQuota::used($owner->id));

        // The fake upload has no body; give the stored file the size that was counted so the delete can release it.
        Storage::disk('public')->put(substr($custom['id'], strlen('/storage/')), str_repeat('a', $size));

        $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $store->id])
            ->postJson(route('dashboard.sounds.delete'), ['id' => $custom['id']])
            ->assertOk();

        $this->assertCount(0, $this->soundFiles($store));
        $this->assertSame($before, StorageQuota::used($owner->id));
    }

    public function test_an_eleventh_sound_is_refused_and_nothing_is_written(): void
    {
        Storage::fake('public');
        $owner = $this->owner();
        $store = $this->store($owner);

        for ($i = 1; $i <= 10; $i++) {
            $this->actingAs($owner)
                ->withSession(['dashboard_store_id' => $store->id])
                ->post(route('dashboard.sounds.upload'), [
                    'sound' => UploadedFile::fake()->create('s'.$i.'.mp3', 10, 'audio/mpeg'),
                ])
                ->assertOk();
        }

        $this->assertCount(10, $this->soundFiles($store));

        $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $store->id])
            ->post(route('dashboard.sounds.upload'),
                ['sound' => UploadedFile::fake()->create('eleventh.mp3', 10, 'audio/mpeg')],
                ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->assertCount(10, $this->soundFiles($store));
    }

    public function test_sound_upload_is_refused_when_the_storage_cap_is_exhausted(): void
    {
        Storage::fake('public');
        config([
            'settings.abuse_storage_mode' => 'enforce',
            'settings.abuse_storage_tenant_cap_mb' => 1,
        ]);
        $owner = $this->owner();
        $store = $this->store($owner);
        StorageQuota::put($owner->id, 1024 * 1024);

        $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $store->id])
            ->post(route('dashboard.sounds.upload'),
                ['sound' => UploadedFile::fake()->create('a.mp3', 10, 'audio/mpeg')],
                ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->assertCount(0, $this->soundFiles($store));
        $this->assertSame(1024 * 1024, StorageQuota::used($owner->id));
    }

    // ── menu font ─────────────────────────────────────────────────────────────

    public function test_an_oversized_menu_font_is_refused_and_the_old_font_kept(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        StoreOption::updateStoreOption($store->id, 'menu_font_file', 'existing_font.woff2');
        $before = StorageQuota::used($owner->id);

        $file = UploadedFile::fake()->createWithContent('big.woff2', 'wOF2'.str_repeat('a', 3 * 1024 * 1024));
        $this->actingAs($owner);

        $error = app(StoreSettingsService::class)->applyOwnerUpdate(
            $store,
            $this->updateRequest($store, [], ['menu_font_file' => $file])
        );

        $this->assertSame('Use a .woff2 font under 2 MB.', $error);
        $this->assertSame('existing_font.woff2', StoreOption::getStoreOption($store->id, 'menu_font_file'));
        $this->assertSame($before, StorageQuota::used($owner->id));
    }

    public function test_a_real_woff2_font_is_accepted_and_counts_toward_storage(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $before = StorageQuota::used($owner->id);

        $file = UploadedFile::fake()->createWithContent('brand.woff2', 'wOF2'.str_repeat('a', 200));
        $size = $file->getSize();
        $this->actingAs($owner);
        File::ensureDirectoryExists(public_path('storage/fonts'));

        $saved = null;

        try {
            $error = app(StoreSettingsService::class)->applyOwnerUpdate(
                $store,
                $this->updateRequest($store, [], ['menu_font_file' => $file])
            );

            $this->assertNull($error);
            $saved = StoreOption::getStoreOption($store->id, 'menu_font_file');
            $this->assertNotEmpty($saved, 'menu_font_file is stored after a valid woff2 upload');
            $this->assertSame($before + $size, StorageQuota::used($owner->id));
        } finally {
            $this->removeFont($saved);
        }
    }
}
