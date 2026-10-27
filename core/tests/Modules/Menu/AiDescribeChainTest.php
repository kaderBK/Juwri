<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ai\Services\AiProviderFactory;
use Tests\TestCase;

/**
 * FEATURES-RUN-4 A1 (describeChain) + A4 (BYOK model-picker plumbing).
 */
class AiDescribeChainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic two-provider chain: mimo default -> openai fallback.
        config([
            'ai.default' => 'mimo',
            'ai.fallback' => ['openai'],
            'ai.vision' => ['openai'],
            'ai.providers.mimo' => [
                'driver' => 'openai', 'base_url' => 'https://api.xiaomimimo.com/v1',
                'api_key' => 'platform-mimo', 'model' => 'mimo-v2.5-pro',
                'allowed_models' => ['mimo-v2.5-pro', 'mimo-v2'],
                'supports_json' => true, 'supports_vision' => false,
            ],
            'ai.providers.openai' => [
                'driver' => 'openai', 'base_url' => 'https://api.openai.com/v1',
                'api_key' => 'platform-openai', 'model' => 'gpt-4o',
                'allowed_models' => ['gpt-4o', 'gpt-4o-mini'],
                'supports_json' => true, 'supports_vision' => true,
            ],
        ]);
    }

    private function storeFixture(?int $resellerId = null): Store
    {
        $user = User::factory()->create([
            'status' => 1, 'email_verified_at' => now(), 'reseller_id' => $resellerId,
        ]);

        return Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function factory(): AiProviderFactory
    {
        return app(AiProviderFactory::class);
    }

    /** No restaurant -> every active provider reports the platform key. */
    public function test_describe_chain_reports_platform_source_by_default(): void
    {
        $chain = $this->factory()->describeChain(null);

        $this->assertSame([
            ['provider' => 'mimo',   'model' => 'mimo-v2.5-pro', 'key_source' => 'platform'],
            ['provider' => 'openai', 'model' => 'gpt-4o',        'key_source' => 'platform'],
        ], $chain);
    }

    /** Providers with no usable key are omitted, mirroring chain(). */
    public function test_describe_chain_skips_unconfigured_providers(): void
    {
        config(['ai.providers.mimo.api_key' => '']);

        $chain = $this->factory()->describeChain(null);

        $this->assertSame([
            ['provider' => 'openai', 'model' => 'gpt-4o', 'key_source' => 'platform'],
        ], $chain);
    }

    /** A merchant BYOK row flips that provider's key_source to 'byok' + model. */
    public function test_describe_chain_reports_merchant_byok_source(): void
    {
        $store = $this->storeFixture();

        Credential::create([
            'store_id' => $store->id, 'feature' => 'ai', 'provider' => 'mimo', 'active' => true,
            'secrets' => ['api_key' => 'byok-key', 'model' => 'byok-mimo-model'],
        ]);

        $chain = $this->factory()->describeChain($store->id);

        $this->assertSame([
            ['provider' => 'mimo',   'model' => 'byok-mimo-model', 'key_source' => 'byok'],
            ['provider' => 'openai', 'model' => 'gpt-4o',          'key_source' => 'platform'],
        ], $chain);
    }

    /** Merchant has no key of its own -> owning reseller's key_source = 'reseller'. */
    public function test_describe_chain_reports_reseller_source(): void
    {
        $store = $this->storeFixture(resellerId: 4242);

        Credential::create([
            'reseller_id' => 4242, 'store_id' => null,
            'feature' => 'reseller_ai', 'provider' => 'mimo', 'active' => true,
            'secrets' => ['api_key' => 'reseller-key', 'model' => 'reseller-mimo'],
        ]);

        $chain = $this->factory()->describeChain($store->id);

        $this->assertSame([
            ['provider' => 'mimo',   'model' => 'reseller-mimo', 'key_source' => 'reseller'],
            ['provider' => 'openai', 'model' => 'gpt-4o',        'key_source' => 'platform'],
        ], $chain);
    }

    /** A row overriding only the model (no api_key) keeps the platform source. */
    public function test_describe_chain_model_only_override_stays_platform(): void
    {
        $store = $this->storeFixture();

        Credential::create([
            'store_id' => $store->id, 'feature' => 'ai', 'provider' => 'mimo', 'active' => true,
            'secrets' => ['model' => 'owner-picked-model'],
        ]);

        $chain = $this->factory()->describeChain($store->id);

        $this->assertSame(
            ['provider' => 'mimo', 'model' => 'owner-picked-model', 'key_source' => 'platform'],
            $chain[0]
        );
    }

    /** needsVision filters to vision-capable providers, keeping source logic. */
    public function test_describe_chain_vision_filter(): void
    {
        $chain = $this->factory()->describeChain(null, needsVision: true);

        $this->assertSame([
            ['provider' => 'openai', 'model' => 'gpt-4o', 'key_source' => 'platform'],
        ], $chain);
    }

    // ---- A4: BYOK model-picker plumbing ----

    public function test_allowed_models_returns_curated_list(): void
    {
        $this->assertSame(['mimo-v2.5-pro', 'mimo-v2'], $this->factory()->allowedModels('mimo'));
        $this->assertSame([], $this->factory()->allowedModels('does-not-exist'));
    }

    public function test_model_picker_options_shape(): void
    {
        $opts = $this->factory()->modelPickerOptions();

        $this->assertSame(
            ['default' => 'mimo-v2.5-pro', 'allowed_models' => ['mimo-v2.5-pro', 'mimo-v2']],
            $opts['mimo']
        );
        $this->assertArrayHasKey('openai', $opts);
    }

    public function test_set_and_get_byok_model_free_text(): void
    {
        $store = $this->storeFixture();

        // Free-text value NOT in the curated list is accepted.
        $this->factory()->setByokModel($store->id, 'mimo', 'custom-fine-tune-42');
        $this->assertSame('custom-fine-tune-42', $this->factory()->getByokModel($store->id, 'mimo'));

        $this->assertDatabaseHas('credentials', [
            'store_id' => $store->id, 'feature' => 'ai', 'provider' => 'mimo',
        ]);
    }

    public function test_set_byok_model_preserves_existing_api_key(): void
    {
        $store = $this->storeFixture();

        Credential::create([
            'store_id' => $store->id, 'feature' => 'ai', 'provider' => 'mimo', 'active' => true,
            'secrets' => ['api_key' => 'keep-me', 'model' => 'old'],
        ]);

        $this->factory()->setByokModel($store->id, 'mimo', 'new-model');

        $cred = Credential::where('store_id', $store->id)->where('provider', 'mimo')->first();
        $this->assertSame('keep-me', $cred->secrets['api_key']);
        $this->assertSame('new-model', $cred->secrets['model']);
    }

    public function test_clear_byok_model_removes_override(): void
    {
        $store = $this->storeFixture();

        Credential::create([
            'store_id' => $store->id, 'feature' => 'ai', 'provider' => 'mimo', 'active' => true,
            'secrets' => ['api_key' => 'keep-me', 'model' => 'old'],
        ]);

        $this->factory()->setByokModel($store->id, 'mimo', '');

        $this->assertNull($this->factory()->getByokModel($store->id, 'mimo'));
        $cred = Credential::where('store_id', $store->id)->where('provider', 'mimo')->first();
        $this->assertArrayNotHasKey('model', $cred->secrets);
        $this->assertSame('keep-me', $cred->secrets['api_key']); // key untouched
    }
}
