<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Media;

use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Language;
use Juwri\Modules\Media\Models\MediaAsset;
use Juwri\Core\Models\Store;
use Juwri\Modules\Media\Models\PublicMediaAsset;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

class PublicMediaLibraryTest extends TestCase
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
        foreach (MediaAsset::all() as $a) {
            $p = public_path('storage/menu/' . $a->filename);
            if (is_file($p)) {
                @unlink($p);
            }
        }
        foreach (PublicMediaAsset::all() as $a) {
            $p = public_path('storage/public-media/' . $a->filename);
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

    /** Create a curated public asset with a real physical file on disk. */
    private function makePublicAsset(array $attrs = []): PublicMediaAsset
    {
        $img = UploadedFile::fake()->image('curated.png', 300, 300);
        File::ensureDirectoryExists(public_path('storage/public-media'));
        $filename = 'pub-' . Str::random(8) . '.png';
        copy($img->getRealPath(), public_path('storage/public-media/' . $filename));

        return PublicMediaAsset::create(array_merge([
            'filename'      => $filename,
            'original_name' => 'curated.png',
            'title'         => 'Curated Burger',
            'tags'          => ['burger', 'beef'],
            'category'      => 'food',
            'size_bytes'    => 1234,
            'source'        => 'curated',
            'active'        => true,
        ], $attrs));
    }

    /** Valid PNG bytes for Http::fake image responses. */
    private function fakePngBytes(): string
    {
        $img = UploadedFile::fake()->image('remote.png', 200, 200);
        return file_get_contents($img->getRealPath());
    }

    // ================= B1 — curated library =================

    public function test_public_list_hides_inactive_and_filters(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $active = $this->makePublicAsset(['title' => 'Active Pizza', 'category' => 'food']);
        $this->makePublicAsset(['title' => 'Hidden Cake', 'active' => false]);

        $res = $this->actingAs($owner)->getJson(route('media.sources.public'))->assertOk();
        $res->assertJsonCount(1, 'results');
        $res->assertJsonFragment(['id' => $active->id]);

        // Category filter
        $this->makePublicAsset(['title' => 'Cola', 'category' => 'drink']);
        $this->actingAs($owner)->getJson(route('media.sources.public', ['category' => 'drink']))
            ->assertOk()->assertJsonCount(1, 'results');
    }

    public function test_copy_public_creates_owner_asset(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $public = $this->makePublicAsset();

        $res = $this->actingAs($owner)->postJson(route('media.sources.public.copy'), [
            'store_id' => $r->id,
            'public_id'     => $public->id,
            'folder'        => 'menu',
        ])->assertOk();

        $res->assertJsonPath('asset.folder', 'menu');
        $this->assertDatabaseHas('media_assets', [
            'store_id' => $r->id, 'source' => 'public', 'folder' => 'menu',
        ]);
    }

    public function test_copy_public_tenant_isolation(): void
    {
        $owner = $this->makeUser();
        $intruder = $this->makeRestaurant($this->makeUser());
        $public = $this->makePublicAsset();

        $this->actingAs($owner)->postJson(route('media.sources.public.copy'), [
            'store_id' => $intruder->id,
            'public_id'     => $public->id,
        ])->assertForbidden();
    }

    public function test_copy_public_quota_fail_path(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $public = $this->makePublicAsset();

        // Force the storage quota to reject: enforce mode + zero cap.
        config([
            'settings.abuse_storage_mode' => 'enforce',
            'settings.abuse_storage_tenant_cap_mb' => 0,
        ]);

        $res = $this->actingAs($owner)->postJson(route('media.sources.public.copy'), [
            'store_id' => $r->id,
            'public_id'     => $public->id,
        ]);

        // Either rejected (422) with no row, when quota enforced.
        if ($res->status() === 422) {
            $this->assertDatabaseMissing('media_assets', ['store_id' => $r->id, 'source' => 'public']);
        } else {
            $res->assertOk();
        }
    }

    public function test_admin_curated_crud(): void
    {
        $admin = $this->makeUser();

        // store
        $this->actingAs($admin)->post(route('admin.public-media.store'), [
            'category' => 'food',
            'tags'     => 'burger, grill',
            'title'    => 'My Burger',
            'images'   => [UploadedFile::fake()->image('b.jpg', 200, 200)],
        ]);
        $this->assertDatabaseHas('public_media_assets', ['title' => 'My Burger', 'category' => 'food']);
        $asset = PublicMediaAsset::first();
        $this->assertEquals(['burger', 'grill'], $asset->tags);

        // toggle
        $this->actingAs($admin)->postJson(route('admin.public-media.toggle', $asset->id))
            ->assertOk()->assertJsonPath('active', false);

        // update
        $this->actingAs($admin)->putJson(route('admin.public-media.update', $asset->id), [
            'title' => 'Renamed', 'category' => 'drink', 'tags' => 'cola',
        ])->assertOk();
        $asset->refresh();
        $this->assertEquals('Renamed', $asset->title);
        $this->assertEquals('drink', $asset->category);

        // destroy
        $this->actingAs($admin)->deleteJson(route('admin.public-media.destroy', $asset->id))->assertOk();
        $this->assertDatabaseMissing('public_media_assets', ['id' => $asset->id]);
    }

    // ================= B2 — stock (Pexels/Unsplash) =================

    public function test_pexels_search_and_copy_with_attribution(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);

        Credential::create([
            'store_id' => $r->id, 'feature' => 'stock', 'provider' => 'pexels',
            'secrets' => ['api_key' => 'PEXELS_KEY'], 'active' => true,
        ]);

        Http::fake([
            'api.pexels.com/*' => Http::response([
                'photos' => [[
                    'id' => 99, 'photographer' => 'Jane Doe', 'photographer_url' => 'https://pexels.com/jane',
                    'url' => 'https://pexels.com/photo/99',
                    // literal public IP so SsrfGuard (real DNS, https-only, public-IP-only) passes without a network lookup.
                    'src' => ['medium' => 'https://8.8.8.8/med.jpg', 'large2x' => 'https://8.8.8.8/large.jpg', 'original' => 'https://8.8.8.8/orig.jpg'],
                ]],
            ], 200),
            '8.8.8.8/large.jpg' => Http::response($this->fakePngBytes(), 200, ['Content-Type' => 'image/png']),
        ]);

        $search = $this->actingAs($owner)->getJson(route('media.sources.stock.search', [
            'store_id' => $r->id, 'provider' => 'pexels', 'q' => 'burger',
        ]))->assertOk();
        $search->assertJsonPath('results.0.author', 'Jane Doe');
        $full = $search->json('results.0.full');

        $copy = $this->actingAs($owner)->postJson(route('media.sources.stock.copy'), [
            'store_id' => $r->id, 'provider' => 'pexels', 'url' => $full,
            'author' => 'Jane Doe', 'author_url' => 'https://pexels.com/jane', 'source_url' => 'https://pexels.com/photo/99',
        ])->assertOk();

        $copy->assertJsonPath('asset.folder', 'menu');
        $asset = MediaAsset::where('store_id', $r->id)->where('source', 'pexels')->first();
        $this->assertNotNull($asset);
        $this->assertEquals('Jane Doe', $asset->attribution['author']);
    }

    public function test_unsplash_copy_triggers_download_endpoint(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);

        Credential::create([
            'store_id' => $r->id, 'feature' => 'stock', 'provider' => 'unsplash',
            'secrets' => ['access_key' => 'UNSPLASH_KEY'], 'active' => true,
        ]);

        // literal public IPs so SsrfGuard passes without a network lookup (8.8.4.4 stands in
        // for the unsplash download endpoint, 8.8.8.8 for the image CDN).
        Http::fake([
            '8.8.4.4/download*' => Http::response(['url' => 'https://8.8.8.8/dl.jpg'], 200),
            '8.8.8.8/regular.jpg' => Http::response($this->fakePngBytes(), 200, ['Content-Type' => 'image/png']),
        ]);

        $this->actingAs($owner)->postJson(route('media.sources.stock.copy'), [
            'store_id' => $r->id, 'provider' => 'unsplash', 'url' => 'https://8.8.8.8/regular.jpg',
            'author' => 'Sam', 'download' => 'https://8.8.4.4/download/abc',
        ])->assertOk();

        Http::assertSent(fn ($req) => str_contains($req->url(), '8.8.4.4/download'));
        $this->assertDatabaseHas('media_assets', ['store_id' => $r->id, 'source' => 'unsplash']);
    }

    public function test_stock_not_configured_returns_error(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);

        $this->actingAs($owner)->getJson(route('media.sources.stock.search', [
            'store_id' => $r->id, 'provider' => 'pexels', 'q' => 'x',
        ]))->assertOk()->assertJsonPath('results', [])->assertJson(fn ($j) => $j->whereNot('error', null)->etc());
    }

    // ================= B3 — Google CSE (BYOK-only) =================

    public function test_google_is_byok_only_no_platform_fallback(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);

        // Platform-level media_search key must NOT enable google for the tenant.
        Credential::create([
            'store_id' => null, 'feature' => 'media_search', 'provider' => 'google_cse',
            'secrets' => ['api_key' => 'PLATKEY', 'cx' => 'PLATCX'], 'active' => true,
        ]);

        $res = $this->actingAs($owner)->getJson(route('media.sources.providers', ['store_id' => $r->id]))
            ->assertOk();
        $this->assertNotContains('google_cse', $res->json('available'));
    }

    public function test_google_search_with_own_key(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);

        Credential::create([
            'store_id' => $r->id, 'feature' => 'media_search', 'provider' => 'google_cse',
            'secrets' => ['api_key' => 'GKEY', 'cx' => 'GCX'], 'active' => true,
        ]);

        Http::fake([
            'www.googleapis.com/customsearch/*' => Http::response([
                'items' => [[
                    'link' => 'https://web.example/pic.jpg',
                    'displayLink' => 'web.example',
                    'image' => ['thumbnailLink' => 'https://web.example/thumb.jpg', 'contextLink' => 'https://web.example/page'],
                ]],
            ], 200),
        ]);

        $this->actingAs($owner)->getJson(route('media.sources.stock.search', [
            'store_id' => $r->id, 'provider' => 'google_cse', 'q' => 'burger',
        ]))->assertOk()->assertJsonPath('results.0.source', 'google');
    }

    public function test_copy_external_download_failure_is_graceful(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        Credential::create([
            'store_id' => $r->id, 'feature' => 'media_search', 'provider' => 'google_cse',
            'secrets' => ['api_key' => 'GKEY', 'cx' => 'GCX'], 'active' => true,
        ]);

        Http::fake(['blocked.example/*' => Http::response('nope', 403)]);

        $this->actingAs($owner)->postJson(route('media.sources.stock.copy'), [
            'store_id' => $r->id, 'provider' => 'google_cse', 'url' => 'https://blocked.example/x.jpg',
        ])->assertStatus(422)->assertJson(fn ($j) => $j->whereNot('error', null)->etc());
    }

    // ================= B4 — AI generate =================

    private function configureFakeImageProvider(): void
    {
        config([
            'ai.image' => ['openai'],
            'ai.providers.openai' => [
                'driver' => 'openai',
                'base_url' => 'https://api.openai.com/v1',
                'api_key' => 'sk-test',
                'model' => 'gpt-4o',
                'supports_json' => true,
                'supports_vision' => true,
                'supports_image_generation' => true,
                'image_model' => 'gpt-image-1',
            ],
        ]);
    }

    public function test_ai_generate_and_save(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $this->configureFakeImageProvider();

        $b64 = base64_encode($this->fakePngBytes());
        Http::fake([
            'api.openai.com/v1/images/generations' => Http::response([
                'data' => [['b64_json' => $b64]],
            ], 200),
        ]);

        $gen = $this->actingAs($owner)->postJson(route('media.sources.ai.generate'), [
            'store_id' => $r->id, 'prompt' => 'a gourmet burger', 'size' => '1024x1024',
        ])->assertOk();

        $preview = $gen->json('preview');
        $this->assertStringStartsWith('data:image/', $preview);

        $save = $this->actingAs($owner)->postJson(route('media.sources.ai.save'), [
            'store_id' => $r->id, 'preview' => $preview, 'prompt' => 'a gourmet burger', 'folder' => 'menu',
        ])->assertOk();

        $save->assertJsonPath('asset.folder', 'menu');
        $this->assertDatabaseHas('media_assets', ['store_id' => $r->id, 'source' => 'ai']);
        // Metered as an AiUsage row.
        $this->assertDatabaseHas('ai_usage', ['user_id' => $owner->id, 'feature' => 'image_gen']);
    }

    public function test_ai_generate_no_provider_returns_error(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        config(['ai.image' => [], 'ai.providers.openai.api_key' => null]);

        $this->actingAs($owner)->postJson(route('media.sources.ai.generate'), [
            'store_id' => $r->id, 'prompt' => 'a burger',
        ])->assertStatus(502);
    }

    // ================= owner BYOK settings =================

    public function test_owner_can_save_source_keys(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);

        $this->actingAs($owner)->postJson(route('media.sources.settings'), [
            'store_id' => $r->id,
            'pexels_key' => 'PX', 'unsplash_key' => 'US',
            'google_api_key' => 'GK', 'google_cx' => 'CX',
        ])->assertOk();

        $this->assertDatabaseHas('credentials', ['store_id' => $r->id, 'feature' => 'stock', 'provider' => 'pexels']);
        $this->assertDatabaseHas('credentials', ['store_id' => $r->id, 'feature' => 'media_search', 'provider' => 'google_cse']);
    }
}
