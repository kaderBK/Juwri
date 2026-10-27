<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Jobs\GenerateMenuItemImagesJob;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Tests\Support\TestPlans;
use Tests\TestCase;

class AiMenuFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Disable ONLY the staff-permission gate (it redirects in a bare test
        // env). Keep auth + SubstituteBindings so the {store} model binding
        // resolves — a blanket withoutMiddleware() would inject a blank Post.
        $this->withoutMiddleware([
            EnsureStaffDashboardPermission::class,
            Installed::class, // APP_INSTALLED=false in tests -> would 302 to /install
        ]);

        config([
            'ai.default' => 'mimo',
            'ai.fallback' => [],
            'ai.vision' => [],
            'ai.retries' => 0,
            'ai.providers.mimo' => [
                'driver' => 'openai', 'base_url' => 'https://api.xiaomimimo.com/v1',
                'api_key' => 'k', 'model' => 'm', 'supports_json' => true, 'supports_vision' => false,
            ],
        ]);
    }

    private function owner(int $menuLimit = 999, int $categoryLimit = 999): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'x',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            // Pass an array (not json_encode): the model's `object` cast encodes
            // it on save; a pre-encoded string would double-encode and read back
            // as a string, defeating effective_limit().
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => $menuLimit, 'category_limit' => $categoryLimit],
            'status' => 1,
        ]);
        $user = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'x', 'description' => 'x',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$user, $store];
    }

    private function fakeAi(array $json): void
    {
        Http::fake(['api.xiaomimimo.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode($json)]]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5],
        ], 200)]);
    }

    public function test_generate_returns_normalized_draft(): void
    {
        [$user, $store] = $this->owner();
        $this->fakeAi(['categories' => [
            ['name' => 'Mains', 'items' => [['name' => 'Burger', 'description' => 'Beef', 'price' => '$9,50']]],
        ]]);

        $res = $this->actingAs($user)->postJson("/stores/{$store->id}/menu/ai-generate", ['text' => 'Burger 9.50']);

        $res->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('data.categories.0.name', 'Mains')
            ->assertJsonPath('data.categories.0.items.0.price', 9.50);
    }

    public function test_save_persists_categories_and_items(): void
    {
        [$user, $store] = $this->owner();

        $payload = ['categories' => [
            ['name' => ' Drinks ', 'items' => [
                ['name' => 'Cola', 'description' => '', 'price' => '3.00'],
                ['name' => 'Juice', 'description' => 'Fresh', 'price' => 5],
            ]],
        ]];

        $res = $this->actingAs($user)->postJson("/stores/{$store->id}/menu/ai-save", $payload);

        $res->assertOk()->assertJsonPath('success', true)->assertJsonPath('imported', 2);
        $this->assertDatabaseHas('categories', ['store_id' => $store->id, 'name' => 'Drinks']);
        $this->assertDatabaseHas('items', ['store_id' => $store->id, 'name' => 'Cola', 'price_minor' => 300]);
        $this->assertDatabaseHas('items', ['store_id' => $store->id, 'name' => 'Juice', 'price_minor' => 500]);
    }

    public function test_save_respects_menu_plan_limit(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        [$user, $store] = $this->owner(menuLimit: 1);

        $payload = ['categories' => [
            ['name' => 'Cat', 'items' => [
                ['name' => 'A', 'description' => '', 'price' => 1],
                ['name' => 'B', 'description' => '', 'price' => 2],
                ['name' => 'C', 'description' => '', 'price' => 3],
            ]],
        ]];

        $res = $this->actingAs($user)->postJson("/stores/{$store->id}/menu/ai-save", $payload);

        $res->assertOk()->assertJsonPath('limit_hit', true)->assertJsonPath('imported', 1);
        $this->assertSame(1, Menu::where('store_id', $store->id)->count());
    }

    public function test_cannot_generate_for_another_owners_restaurant(): void
    {
        [$intruder] = $this->owner();
        [, $victimPost] = $this->owner();

        $this->fakeAi(['categories' => []]);
        $this->actingAs($intruder)
            ->postJson("/stores/{$victimPost->id}/menu/ai-generate", ['text' => 'x'])
            ->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)
    }

    public function test_empty_text_is_rejected(): void
    {
        [$user, $store] = $this->owner();
        $this->actingAs($user)
            ->postJson("/stores/{$store->id}/menu/ai-generate", ['text' => '   '])
            ->assertStatus(400);
    }

    /** S1-P1: scan/upload path — an image file is read and extracted into a draft. */
    public function test_generate_from_image_returns_draft(): void
    {
        // A vision-capable provider must exist for the photo path.
        config(['ai.vision' => ['mimo'], 'ai.providers.mimo.supports_vision' => true]);

        [$user, $store] = $this->owner();
        $this->fakeAi(['categories' => [
            ['name' => 'Grill', 'items' => [['name' => 'Kebab', 'description' => '', 'price' => 7]]],
        ]]);

        $res = $this->actingAs($user)->post("/stores/{$store->id}/menu/ai-generate", [
            'image' => UploadedFile::fake()->image('menu.jpg', 800, 600),
        ]);

        $res->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('data.categories.0.name', 'Grill')
            ->assertJsonPath('data.categories.0.items.0.name', 'Kebab');
    }

    /** S1-P1: a non-image upload is rejected, not processed. */
    public function test_generate_rejects_non_image_upload(): void
    {
        [$user, $store] = $this->owner();
        $this->fakeAi(['categories' => []]);

        $res = $this->actingAs($user)->post("/stores/{$store->id}/menu/ai-generate", [
            'image' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ]);

        $res->assertStatus(400);
    }

    /** S1-P2: two page drafts merge — same category combines, item gaps fill. */
    public function test_merge_endpoint_combines_pages(): void
    {
        [$user, $store] = $this->owner();

        $drafts = [
            ['categories' => [['name' => 'Mains', 'items' => [
                ['name' => 'Burger', 'description' => '', 'price' => null],  // page 1: no price
            ]]]],
            ['categories' => [['name' => 'mains', 'items' => [               // case-insensitive same cat
                ['name' => 'burger', 'description' => 'Beef', 'price' => 9], // page 2: fills price + desc
                ['name' => 'Fries', 'description' => '', 'price' => 3],
            ]]]],
        ];

        $res = $this->actingAs($user)->postJson("/stores/{$store->id}/menu/ai-merge", ['drafts' => $drafts]);

        $res->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('data.categories.0.name', 'Mains')
            ->assertJsonCount(1, 'data.categories')       // one merged category
            ->assertJsonCount(2, 'data.categories.0.items'); // burger (deduped) + fries
        $items = collect($res->json('data.categories.0.items'))->keyBy('name');
        $this->assertEquals(9.0, $items['Burger']['price']);     // gap filled from page 2
        $this->assertSame('Beef', $items['Burger']['description']);
    }

    /** Describe tab: generative mode invents a draft (incl. variants/extras). */
    public function test_describe_mode_generates_draft_with_variants_and_extras(): void
    {
        [$user, $store] = $this->owner();
        $this->fakeAi(['categories' => [
            ['name' => 'Pizzas', 'items' => [[
                'name' => 'Margherita', 'description' => 'Classic', 'price' => 8,
                'variants' => [['title' => 'Size', 'options' => ['Small', 'Large'], 'prices' => [8, 12]]],
                'extras' => [['title' => 'Extra cheese', 'price' => 2]],
            ]]],
        ]]);

        $res = $this->actingAs($user)->postJson("/stores/{$store->id}/menu/ai-generate", [
            'text' => 'A cozy Italian pizzeria', 'mode' => 'describe', 'with_variants' => 1,
        ]);

        $res->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('data.categories.0.items.0.variants.0.title', 'Size')
            ->assertJsonPath('data.categories.0.items.0.variants.0.options.1', 'Large')
            ->assertJsonPath('data.categories.0.items.0.extras.0.title', 'Extra cheese');

        // The generative system prompt was used (invents a menu), not the extractor.
        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return str_contains($body['messages'][0]['content'] ?? '', 'design a realistic restaurant menu');
        });
    }

    /** Malformed variants coming back from the model are dropped, not saved. */
    public function test_describe_mode_normalizes_bad_variants(): void
    {
        [$user, $store] = $this->owner();
        $this->fakeAi(['categories' => [
            ['name' => 'Mains', 'items' => [[
                'name' => 'Dish', 'description' => '', 'price' => 5,
                'variants' => [
                    ['title' => '', 'options' => ['A', 'B']],           // no title
                    ['title' => 'Size', 'options' => ['Only one']],     // < 2 options
                    'garbage',
                ],
                'extras' => ['garbage', ['title' => '', 'price' => 1]],
            ]]],
        ]]);

        $res = $this->actingAs($user)->postJson("/stores/{$store->id}/menu/ai-generate", [
            'text' => 'x', 'mode' => 'describe',
        ]);

        $res->assertOk()
            ->assertJsonCount(0, 'data.categories.0.items.0.variants')
            ->assertJsonCount(0, 'data.categories.0.items.0.extras');
    }

    public function test_save_persists_variants_and_extras(): void
    {
        [$user, $store] = $this->owner();

        $payload = ['categories' => [
            ['name' => 'Pizzas', 'items' => [[
                'name' => 'Margherita', 'description' => '', 'price' => 8,
                'variants' => [['title' => 'Size', 'options' => ['Small', 'Large'], 'prices' => [8, 12]]],
                'extras' => [['title' => 'Extra cheese', 'price' => 2]],
            ]]],
        ]];

        $res = $this->actingAs($user)->postJson("/stores/{$store->id}/menu/ai-save", $payload);
        $res->assertOk()->assertJsonPath('imported', 1);

        $item = Menu::where('store_id', $store->id)->where('name', 'Margherita')->firstOrFail();

        $group = MenuVariantOption::where('menu_id', $item->id)->firstOrFail();
        $this->assertSame('Size', $group->getRawOriginal('title'));
        $this->assertEquals(['Small', 'Large'], (array) $group->options);
        $this->assertEquals([8.0, 12.0], $group->option_prices);

        $this->assertDatabaseHas('modifiers', [
            'menu_id' => $item->id, 'store_id' => $store->id, 'title' => 'Extra cheese', 'price_minor' => 200,
        ]);
    }

    public function test_save_with_generate_images_queues_photo_job(): void
    {
        config(['studio.gemini.api_key' => 'test-key']);
        Queue::fake();

        [$user, $store] = $this->owner();
        $payload = [
            'generate_images' => true,
            'categories' => [['name' => 'Drinks', 'items' => [['name' => 'Cola', 'description' => '', 'price' => 3]]]],
        ];

        $res = $this->actingAs($user)->postJson("/stores/{$store->id}/menu/ai-save", $payload);
        $res->assertOk()->assertJsonPath('images_queued', true);

        Queue::assertPushed(
            GenerateMenuItemImagesJob::class,
            fn ($j) => $j->storeId === $store->id && count($j->menuIds) === 1
        );
    }

    public function test_save_without_image_key_does_not_queue_photo_job(): void
    {
        config(['studio.gemini.api_key' => null]);
        Queue::fake();

        [$user, $store] = $this->owner();
        $payload = [
            'generate_images' => true,
            'categories' => [['name' => 'Drinks', 'items' => [['name' => 'Cola', 'description' => '', 'price' => 3]]]],
        ];

        $res = $this->actingAs($user)->postJson("/stores/{$store->id}/menu/ai-save", $payload);
        $res->assertOk()->assertJsonPath('images_queued', false);

        Queue::assertNothingPushed();
    }

    public function test_merge_rejects_empty_and_foreign(): void
    {
        [$user, $store] = $this->owner();
        $this->actingAs($user)->postJson("/stores/{$store->id}/menu/ai-merge", ['drafts' => []])
            ->assertStatus(400);

        [$intruder] = $this->owner();
        $this->actingAs($intruder)->postJson("/stores/{$store->id}/menu/ai-merge", ['drafts' => [['categories' => []]]])
            ->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)
    }
}
