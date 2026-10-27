<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ai\Services\AiProviderException;
use Juwri\Modules\Ai\Services\Studio\GeminiImageProvider;
use Juwri\Modules\Menu\Jobs\GenerateMenuItemImagesJob;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;

class AiMenuImagesJobTest extends TestCase
{
    use RefreshDatabase;

    private function restaurantWithItems(int $count): array
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = Menu::create([
                'store_id' => $store->id, 'name' => 'Dish '.$i, 'description' => 'Tasty',
                'price' => 5, 'active' => '1',
            ]);
        }

        return [$store, $items];
    }

    /** 1x1 transparent PNG. */
    private function pngBytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    }

    public function test_generates_and_attaches_images(): void
    {
        [$store, $items] = $this->restaurantWithItems(2);

        $provider = $this->createMock(GeminiImageProvider::class);
        $provider->method('isConfigured')->willReturn(true);
        $provider->expects($this->exactly(2))->method('generate')->willReturn($this->pngBytes());

        new GenerateMenuItemImagesJob($store->id, array_map(fn ($m) => $m->id, $items))->handle($provider);

        foreach ($items as $item) {
            $item->refresh();
            $this->assertNotSame('default.png', $item->getRawOriginal('image'));
            $this->assertNotEmpty($item->getRawOriginal('image'));
            $full = public_path('storage/menu/'.$item->getRawOriginal('image'));
            $this->assertFileExists($full);
            @unlink($full);
        }

        $p = Cache::get(GenerateMenuItemImagesJob::progressKey($store->id));
        $this->assertSame('done', $p['status']);
        $this->assertSame(2, $p['generated']);
    }

    public function test_skips_items_that_already_have_a_photo(): void
    {
        [$store, $items] = $this->restaurantWithItems(1);
        $items[0]->update(['image' => 'owner-photo.webp']);

        $provider = $this->createMock(GeminiImageProvider::class);
        $provider->method('isConfigured')->willReturn(true);
        $provider->expects($this->never())->method('generate');

        new GenerateMenuItemImagesJob($store->id, [$items[0]->id])->handle($provider);

        $this->assertSame('owner-photo.webp', $items[0]->refresh()->getRawOriginal('image'));
    }

    public function test_bails_out_after_consecutive_provider_failures(): void
    {
        [$store, $items] = $this->restaurantWithItems(6);

        $provider = $this->createMock(GeminiImageProvider::class);
        $provider->method('isConfigured')->willReturn(true);
        // Fails every time (e.g. out of quota) — must stop after 3, not try all 6.
        $provider->expects($this->exactly(3))->method('generate')
            ->willThrowException(new AiProviderException('Gemini image HTTP 429'));

        new GenerateMenuItemImagesJob($store->id, array_map(fn ($m) => $m->id, $items))->handle($provider);

        $p = Cache::get(GenerateMenuItemImagesJob::progressKey($store->id));
        $this->assertSame('error', $p['status']);
        $this->assertSame(0, $p['generated']);
    }

    public function test_errors_when_provider_not_configured(): void
    {
        [$store, $items] = $this->restaurantWithItems(1);

        $provider = $this->createMock(GeminiImageProvider::class);
        $provider->method('isConfigured')->willReturn(false);

        new GenerateMenuItemImagesJob($store->id, [$items[0]->id])->handle($provider);

        $p = Cache::get(GenerateMenuItemImagesJob::progressKey($store->id));
        $this->assertSame('error', $p['status']);
    }
}
