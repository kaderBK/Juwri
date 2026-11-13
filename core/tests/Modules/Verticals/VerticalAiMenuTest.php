<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Services\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Services\Ai\MenuGeneratorService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Vertical AI catalog generation — bringing the AI menu generator (scan
 * photo / paste text / describe) to the retail (goods) and service (laundry)
 * editors, which previously only rendered on the food menu editor.
 *
 * Two things fenced AI creation to food before this: (1) tools.blade.php was
 * only @include'd from the food menu editor, and (2) MenuGeneratorService's
 * system prompts were restaurant-flavoured. Neither the AI generation
 * backend (GeneratesMenuWithAi::aiSaveMenu writes plain Menu/MenuCategory
 * rows — vertical-agnostic already) nor the ai-generate/ai-save routes are
 * vertical-gated, so this is prompt selection + a UI include, not a new
 * write path.
 *
 * A restaurant must see the EXACT pre-existing food prompt (byte-identical) —
 * that is asserted directly against the MenuGeneratorService::SYSTEM
 * constant, not a re-typed copy that could drift out of sync and mask a
 * regression.
 */
class VerticalAiMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        // Installed/CSRF: bare test env. EnsureStaffDashboardPermission: only
        // matters for the staff guard, but AiMenuFlowTest (the food-editor
        // equivalent of this suite) disables it too — match that so an owner
        // login never redirects instead of reaching the controller.
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
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

    /** @return array{0: User, 1: Store} */
    private function ownerWithStore(string $storeType): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'category_limit' => 999],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        // Vertical-manage surfaces (retail/service editors) require multi_vertical
        // (rev/multi-vertical Lane V4) — grant it for every owner here so the
        // editor-render assertion isn't blocked by an unrelated entitlement gate.
        EntitlementOverride::create(['user_id' => $owner->id, 'feature_key' => 'multi_vertical', 'value' => '1', 'source_type' => 'admin']);
        FeatureAccess::flush();

        $store = Store::factory()->create(['user_id' => $owner->id, 'store_type' => $storeType]);

        return [$owner, $store];
    }

    private function fakeAi(): void
    {
        $json = ['categories' => [
            ['name' => 'Cat', 'items' => [['name' => 'Thing', 'description' => '', 'price' => 9]]],
        ]];
        Http::fake(['api.xiaomimimo.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode($json)]]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5],
        ], 200)]);
    }

    // ── MenuGeneratorService prompt selection (via the real ai-generate route) ──

    public function test_a_restaurant_store_gets_the_exact_existing_food_system_prompt(): void
    {
        [$owner, $store] = $this->ownerWithStore('restaurant');
        $this->fakeAi();

        $this->actingAs($owner)
            ->postJson("/stores/{$store->id}/menu/ai-generate", ['text' => 'Burger 9.50'])
            ->assertOk()->assertJsonPath('success', true);

        // Byte-identical requirement: compare against the live constant, not a
        // re-typed literal that could silently drift and mask a regression.
        $expectedFoodPrompt = new \ReflectionClass(MenuGeneratorService::class)->getConstant('SYSTEM');
        $this->assertIsString($expectedFoodPrompt);

        Http::assertSent(function ($request) use ($expectedFoodPrompt) {
            $system = $request->data()['messages'][0] ?? [];

            return ($system['role'] ?? null) === 'system'
                && ($system['content'] ?? null) === $expectedFoodPrompt;
        });
    }

    public function test_a_goods_store_gets_the_product_catalog_system_prompt(): void
    {
        [$owner, $store] = $this->ownerWithStore('retail');
        $this->fakeAi();

        $this->actingAs($owner)
            ->postJson("/stores/{$store->id}/menu/ai-generate", ['text' => 'Candle 9.50'])
            ->assertOk()->assertJsonPath('success', true);

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, 'product catalog')
                && str_contains($content, 'products')
                && ! str_contains($content, 'restaurant');
        });
    }

    public function test_a_services_store_gets_the_service_list_system_prompt(): void
    {
        [$owner, $store] = $this->ownerWithStore('laundry');
        $this->fakeAi();

        $this->actingAs($owner)
            ->postJson("/stores/{$store->id}/menu/ai-generate", ['text' => 'Wash and fold 9.50'])
            ->assertOk()->assertJsonPath('success', true);

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, 'service list')
                && str_contains($content, 'services')
                && ! str_contains($content, 'restaurant');
        });
    }

    public function test_the_describe_tab_also_picks_the_goods_generative_prompt(): void
    {
        [$owner, $store] = $this->ownerWithStore('retail');
        $this->fakeAi();

        $this->actingAs($owner)
            ->postJson("/stores/{$store->id}/menu/ai-generate", [
                'text' => 'A cozy candle and skincare boutique', 'mode' => 'describe',
            ])
            ->assertOk()->assertJsonPath('success', true);

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][0]['content'] ?? '';

            return str_contains($content, 'product catalog')
                && ! str_contains($content, 'restaurant menu');
        });
    }

    // ── The retail editor now renders the AI tools trigger (previously food-only) ──

    public function test_a_goods_store_editor_renders_the_ai_tools_trigger(): void
    {
        [$owner, $store] = $this->ownerWithStore('retail');

        $res = $this->actingAs($owner)->get(route('retail.products.index', $store->id));

        $res->assertOk();
        $res->assertSee('AI generate');
        // The shared Alpine trigger/dialog wiring from tools.blade.php.
        $res->assertSeeHtml("dispatch('menu-ai')");
        $res->assertSeeHtml('aiDialog');
        // The food-only CSV-import dialog must NOT leak into the goods editor
        // (a different CSV column format — RetailItemController::importCsv
        // already covers CSV for this store).
        $res->assertDontSeeHtml('spice_level');
    }

    public function test_a_services_store_editor_renders_the_ai_tools_trigger(): void
    {
        [$owner, $store] = $this->ownerWithStore('laundry');

        $res = $this->actingAs($owner)->get(route('laundry.services.index', $store->id));

        $res->assertOk();
        $res->assertSee('AI generate');
        $res->assertSeeHtml("dispatch('menu-ai')");
        $res->assertSeeHtml('aiDialog');
        $res->assertDontSeeHtml('spice_level');
    }

    public function test_a_restaurant_editor_still_renders_the_csv_import_dialog(): void
    {
        [$owner, $store] = $this->ownerWithStore('restaurant');

        $res = $this->actingAs($owner)->get(route('stores.menu', $store->id));

        $res->assertOk();
        $res->assertSeeHtml('spice_level'); // food-only CSV help text, unchanged
    }
}
