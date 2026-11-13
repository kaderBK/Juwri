<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ai;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Ai\Models\TranslationMemory;
use Juwri\Modules\Menu\Jobs\TranslateMenuJob;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Menu\Services\Ai\MenuTranslationService;
use Tests\Support\TestPlans;
use Tests\TestCase;

class AiTranslateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureStaffDashboardPermission::class,
            Installed::class,
        ]);

        config([
            'ai.default' => 'mimo', 'ai.fallback' => [], 'ai.vision' => [], 'ai.retries' => 0,
            'ai.providers.mimo' => [
                'driver' => 'openai', 'base_url' => 'https://api.xiaomimimo.com/v1',
                'api_key' => 'k', 'model' => 'm', 'supports_json' => true, 'supports_vision' => false,
            ],
        ]);

        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
    }

    private function storeFixture(array $langs = ['en', 'ar'], string $default = 'en'): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'x', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999],
            'status' => 1,
        ]);
        $user = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreSettings::set($store->id, 'menu_languages', json_encode($langs));
        StoreSettings::set($store->id, 'default_language', $default);

        return [$user, $store];
    }

    private function fakeEcho(string $prefix = 'AR:'): void
    {
        Http::fake(['api.xiaomimimo.com/*' => function ($request) use ($prefix) {
            $content = $request->data()['messages'][1]['content'] ?? '';
            preg_match('/\{.*\}/s', $content, $m);
            $payload = json_decode($m[0] ?? '{}', true) ?: [];
            $out = [];
            foreach ($payload as $k => $v) {
                $out[$k] = $prefix.$v;
            }

            return Http::response([
                'choices' => [['message' => ['content' => json_encode($out, JSON_UNESCAPED_UNICODE)]]],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5],
            ], 200);
        }]);
    }

    public function test_fills_missing_translations_with_machine_and_hash(): void
    {
        [, $store] = $this->storeFixture();
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Drinks', 'active' => '1']);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Water', 'description' => 'Cold water', 'price' => 2, 'active' => '1']);

        $this->fakeEcho();
        $res = app(MenuTranslationService::class)->translateRestaurant($store->id, 1);

        $this->assertSame(['ar'], $res['languages']);
        $this->assertSame(1, $res['categories']);
        $this->assertSame(1, $res['items']);

        $cat->refresh();
        $item->refresh();
        $this->assertSame('AR:Drinks', $cat->translations->ar->title);
        $this->assertTrue($cat->translations->ar->_machine);
        $this->assertNotEmpty($cat->translations->ar->_src_hash);
        $this->assertSame('AR:Water', $item->translations->ar->title);
        $this->assertSame('AR:Cold water', $item->translations->ar->description);
        $this->assertNotEmpty($item->translations->ar->_src_hash);

        // Batched: one AI call for the whole language.
        Http::assertSentCount(1);
    }

    public function test_covers_variant_options_and_extras(): void
    {
        [, $store] = $this->storeFixture();
        $item = Menu::create(['store_id' => $store->id, 'name' => 'Burger', 'description' => '', 'price' => 9, 'active' => '1']);
        $vo = MenuVariantOption::create(['menu_id' => $item->id, 'title' => 'Large']);
        $ex = MenuExtra::create(['menu_id' => $item->id, 'title' => 'Extra cheese']);

        $this->fakeEcho();
        $res = app(MenuTranslationService::class)->translateRestaurant($store->id, 1);

        $this->assertSame(1, $res['options']);
        $this->assertSame(1, $res['extras']);
        $this->assertSame('AR:Large', $vo->refresh()->translations->ar->title);
        $this->assertSame('AR:Extra cheese', $ex->refresh()->translations->ar->title);
    }

    public function test_reuses_translation_memory_before_calling_ai(): void
    {
        [, $store] = $this->storeFixture();
        Menu::create(['store_id' => $store->id, 'name' => 'Water', 'description' => '', 'price' => 2, 'active' => '1']);

        // Owner-approved memory already exists for this string.
        TranslationMemory::remember($store->id, 'ar', 'Water', 'ماء معتمد');

        Http::fake(); // any AI call would be recorded
        $item = Menu::where('store_id', $store->id)->first();
        app(MenuTranslationService::class)->translateRestaurant($store->id, 1);

        $this->assertSame('ماء معتمد', $item->refresh()->translations->ar->title);
        Http::assertNothingSent(); // served from memory, no AI call
    }

    public function test_does_not_overwrite_owner_edited_translation(): void
    {
        [, $store] = $this->storeFixture();
        $item = Menu::create([
            'store_id' => $store->id, 'name' => 'Tea', 'description' => '', 'price' => 3, 'active' => '1',
            'translations' => (object) ['ar' => (object) ['title' => 'شاي يدوي']], // owner (no _machine)
        ]);

        Http::fake();
        app(MenuTranslationService::class)->translateRestaurant($store->id, 1);

        $item->refresh();
        $this->assertSame('شاي يدوي', $item->translations->ar->title);
        Http::assertNothingSent();
    }

    public function test_stale_machine_translation_is_retranslated_when_source_changes(): void
    {
        [, $store] = $this->storeFixture();
        $item = Menu::create([
            'store_id' => $store->id, 'name' => 'Water', 'description' => '', 'price' => 2, 'active' => '1',
            // machine translation with a hash that no longer matches "Water"
            'translations' => (object) ['ar' => (object) ['title' => 'stale', '_machine' => true, '_src_hash' => 'OLD']],
        ]);

        $this->fakeEcho();
        app(MenuTranslationService::class)->translateRestaurant($store->id, 1);

        $item->refresh();
        $this->assertSame('AR:Water', $item->translations->ar->title);
    }

    public function test_up_to_date_machine_translation_is_left_alone(): void
    {
        [, $store] = $this->storeFixture();
        $hash = sha1('Water');
        $item = Menu::create([
            'store_id' => $store->id, 'name' => 'Water', 'description' => '', 'price' => 2, 'active' => '1',
            'translations' => (object) ['ar' => (object) ['title' => 'ماء', '_machine' => true, '_src_hash' => $hash]],
        ]);

        Http::fake();
        app(MenuTranslationService::class)->translateRestaurant($store->id, 1);

        $item->refresh();
        $this->assertSame('ماء', $item->translations->ar->title);
        Http::assertNothingSent();
    }

    public function test_endpoint_dispatches_job(): void
    {
        Queue::fake();
        [$user, $store] = $this->storeFixture();
        Menu::create(['store_id' => $store->id, 'name' => 'Water', 'description' => '', 'price' => 2, 'active' => '1']);

        $this->actingAs($user)
            ->postJson("/stores/{$store->id}/menu/ai-translate")
            ->assertOk()->assertJsonPath('success', true);

        Queue::assertPushed(TranslateMenuJob::class, fn ($j) => $j->storeId === $store->id);
    }

    public function test_endpoint_422_when_menu_empty(): void
    {
        Queue::fake();
        [$user, $store] = $this->storeFixture();

        $this->actingAs($user)
            ->postJson("/stores/{$store->id}/menu/ai-translate")
            ->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_endpoint_accepts_source_language_and_persists_default(): void
    {
        Queue::fake();
        [$user, $store] = $this->storeFixture(['en', 'ar'], 'en');
        Menu::create(['store_id' => $store->id, 'name' => 'ماء', 'description' => '', 'price' => 2, 'active' => '1']);

        $this->actingAs($user)
            ->postJson("/stores/{$store->id}/menu/ai-translate", ['source' => 'ar'])
            ->assertOk()->assertJsonPath('success', true);

        $this->assertSame('ar', store_options($store->id, 'default_language'));
        Queue::assertPushed(TranslateMenuJob::class);
    }

    public function test_rejected_request_does_not_mutate_default_language(): void
    {
        Queue::fake();
        [$user, $store] = $this->storeFixture(['en', 'ar'], 'en');
        // Menu is EMPTY → request must 422 without persisting the new source.

        $this->actingAs($user)
            ->postJson("/stores/{$store->id}/menu/ai-translate", ['source' => 'ar'])
            ->assertStatus(422);

        $this->assertSame('en', store_options($store->id, 'default_language'));
        Queue::assertNothingPushed();
    }

    public function test_source_outside_menu_languages_is_rejected(): void
    {
        Queue::fake();
        Language::updateOrCreate(['code' => 'fr'], ['name' => 'French', 'direction' => 'ltr', 'active' => 1]);
        [$user, $store] = $this->storeFixture(['en', 'ar'], 'en');
        Menu::create(['store_id' => $store->id, 'name' => 'Water', 'description' => '', 'price' => 2, 'active' => '1']);

        // 'fr' is an active platform language but NOT one of this store's menu languages.
        $this->actingAs($user)
            ->postJson("/stores/{$store->id}/menu/ai-translate", ['source' => 'fr'])
            ->assertStatus(422);

        $this->assertSame('en', store_options($store->id, 'default_language'));
        Queue::assertNothingPushed();
    }

    public function test_endpoint_rejects_unknown_source_language(): void
    {
        Queue::fake();
        [$user, $store] = $this->storeFixture();
        Menu::create(['store_id' => $store->id, 'name' => 'Water', 'description' => '', 'price' => 2, 'active' => '1']);

        $this->actingAs($user)
            ->postJson("/stores/{$store->id}/menu/ai-translate", ['source' => 'xx'])
            ->assertStatus(422);
        Queue::assertNothingPushed();
    }

    /**
     * #6: with a database queue and no worker heartbeat, the job must still run
     * (dispatchAfterResponse) instead of rotting in the jobs table forever.
     */
    public function test_translate_runs_after_response_when_no_queue_worker(): void
    {
        config(['queue.default' => 'database']);
        Cache::forget('queue_worker_alive');

        [$user, $store] = $this->storeFixture();
        $item = Menu::create(['store_id' => $store->id, 'name' => 'Water', 'description' => '', 'price' => 2, 'active' => '1']);
        $this->fakeEcho();

        $this->actingAs($user)
            ->postJson("/stores/{$store->id}/menu/ai-translate")
            ->assertOk()->assertJsonPath('success', true);

        // Terminate callbacks have run: the translation exists and no job was queued.
        $this->assertSame('AR:Water', $item->refresh()->translations->ar->title);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_translate_uses_queue_when_worker_alive(): void
    {
        config(['queue.default' => 'database']);
        Cache::put('queue_worker_alive', time(), 180);

        [$user, $store] = $this->storeFixture();
        Menu::create(['store_id' => $store->id, 'name' => 'Water', 'description' => '', 'price' => 2, 'active' => '1']);

        $this->actingAs($user)
            ->postJson("/stores/{$store->id}/menu/ai-translate")
            ->assertOk();

        $this->assertDatabaseCount('jobs', 1); // queued for the live worker, not run inline
    }

    public function test_endpoint_422_when_no_target_languages(): void
    {
        Queue::fake();
        [$user, $store] = $this->storeFixture(['en'], 'en');
        $this->actingAs($user)->postJson("/stores/{$store->id}/menu/ai-translate")->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_endpoint_blocks_other_owner(): void
    {
        [$intruder] = $this->storeFixture();
        [, $victim] = $this->storeFixture();
        Queue::fake();
        $this->actingAs($intruder)->postJson("/stores/{$victim->id}/menu/ai-translate")->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)
        Queue::assertNothingPushed();
    }

    public function test_job_publishes_progress_to_cache(): void
    {
        [, $store] = $this->storeFixture();
        Menu::create(['store_id' => $store->id, 'name' => 'Water', 'description' => '', 'price' => 2, 'active' => '1']);
        $this->fakeEcho();

        new TranslateMenuJob($store->id, 1)->handle(app(MenuTranslationService::class));

        $p = Cache::get(TranslateMenuJob::progressKey($store->id));
        $this->assertSame('done', $p['status']);
        $this->assertArrayHasKey('result', $p);
        $this->assertSame(1, $p['result']['items']);
    }

    public function test_progress_endpoint_returns_status_and_is_scoped(): void
    {
        [$user, $store] = $this->storeFixture();
        Cache::put(TranslateMenuJob::progressKey($store->id), ['status' => 'running', 'done' => 1, 'total' => 2], 60);

        $this->actingAs($user)
            ->getJson("/stores/{$store->id}/menu/ai-translate/progress")
            ->assertOk()->assertJsonPath('status', 'running')->assertJsonPath('done', 1);

        [$other] = $this->storeFixture();
        $this->actingAs($other)
            ->getJson("/stores/{$store->id}/menu/ai-translate/progress")
            ->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)
    }
}
