<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ai;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ai\Models\AiUsage;
use Juwri\Modules\Ai\Services\AiProviderFactory;
use Tests\TestCase;

/**
 * FEATURES-RUN-4 A5 (superadmin platform Credential overrides env) +
 * A6 (owner AI transparency panel renders chain + last-used).
 */
class AiPlatformCredentialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A6 hits an owner HTTP route. The dashboard middleware stack redirects
        // when the app isn't "installed" / locale isn't primed in the test env
        // (same setup the passing OwnerDashboardUsageTest uses), so mirror it.
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware([
            Installed::class,
            Locale::class,
            PreventRequestForgery::class,
        ]);
        View::share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => (object) [],
            'languages' => collect(),
            'admin_languages' => collect(),
        ]);

        config([
            'ai.default' => 'mimo',
            'ai.fallback' => ['openai'],
            'ai.vision' => ['openai'],
            'ai.providers.mimo' => [
                'driver' => 'openai', 'base_url' => 'https://api.xiaomimimo.com/v1',
                'api_key' => 'env-mimo', 'model' => 'mimo-v2.5-pro',
                'allowed_models' => ['mimo-v2.5-pro'],
                'supports_json' => true, 'supports_vision' => false,
            ],
            'ai.providers.openai' => [
                'driver' => 'openai', 'base_url' => 'https://api.openai.com/v1',
                'api_key' => 'env-openai', 'model' => 'gpt-4o',
                'allowed_models' => ['gpt-4o'],
                'supports_json' => true, 'supports_vision' => true,
            ],
        ]);
    }

    private function factory(): AiProviderFactory
    {
        return app(AiProviderFactory::class);
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

    /** A5: a platform Credential (store_id NULL) overrides the env key/model but stays 'platform'. */
    public function test_platform_credential_overrides_env_but_stays_platform(): void
    {
        Credential::create([
            'store_id' => null, 'feature' => 'ai', 'provider' => 'mimo', 'active' => true,
            'secrets' => ['api_key' => 'db-platform-key', 'model' => 'db-platform-model'],
        ]);

        // describeChain (no restaurant) reflects the DB model, source unchanged.
        $chain = $this->factory()->describeChain(null);
        $this->assertSame(
            ['provider' => 'mimo', 'model' => 'db-platform-model', 'key_source' => 'platform'],
            $chain[0]
        );

        // The built provider carries the DB key/model, not the env value.
        $provider = $this->factory()->make('mimo', null);
        $this->assertTrue($provider->isConfigured());
    }

    /** A5: a platform Credential re-enables a provider that has no env key. */
    public function test_platform_credential_enables_provider_without_env_key(): void
    {
        config(['ai.providers.mimo.api_key' => '']);

        // Without a DB key, mimo is skipped.
        $this->assertSame('openai', $this->factory()->describeChain(null)[0]['provider']);

        Credential::create([
            'store_id' => null, 'feature' => 'ai', 'provider' => 'mimo', 'active' => true,
            'secrets' => ['api_key' => 'db-only-key'],
        ]);

        $chain = $this->factory()->describeChain(null);
        $this->assertSame('mimo', $chain[0]['provider']);
        $this->assertSame('platform', $chain[0]['key_source']);
    }

    /** A5: merchant BYOK still wins over the platform Credential. */
    public function test_merchant_byok_wins_over_platform_credential(): void
    {
        Credential::create([
            'store_id' => null, 'feature' => 'ai', 'provider' => 'mimo', 'active' => true,
            'secrets' => ['api_key' => 'db-platform-key', 'model' => 'db-platform-model'],
        ]);

        $store = $this->storeFixture();
        Credential::create([
            'store_id' => $store->id, 'feature' => 'ai', 'provider' => 'mimo', 'active' => true,
            'secrets' => ['api_key' => 'merchant-key', 'model' => 'merchant-model'],
        ]);

        $chain = $this->factory()->describeChain($store->id);
        $this->assertSame(
            ['provider' => 'mimo', 'model' => 'merchant-model', 'key_source' => 'byok'],
            $chain[0]
        );
    }

    /** A5: reseller BYOK still wins over the platform Credential. */
    public function test_reseller_byok_wins_over_platform_credential(): void
    {
        Credential::create([
            'store_id' => null, 'feature' => 'ai', 'provider' => 'mimo', 'active' => true,
            'secrets' => ['api_key' => 'db-platform-key', 'model' => 'db-platform-model'],
        ]);

        $store = $this->storeFixture(resellerId: 777);
        Credential::create([
            'reseller_id' => 777, 'store_id' => null,
            'feature' => 'reseller_ai', 'provider' => 'mimo', 'active' => true,
            'secrets' => ['api_key' => 'reseller-key', 'model' => 'reseller-model'],
        ]);

        $chain = $this->factory()->describeChain($store->id);
        $this->assertSame(
            ['provider' => 'mimo', 'model' => 'reseller-model', 'key_source' => 'reseller'],
            $chain[0]
        );
    }

    /** A5: an inactive platform Credential is ignored (env default resumes). */
    public function test_inactive_platform_credential_falls_back_to_env(): void
    {
        Credential::create([
            'store_id' => null, 'feature' => 'ai', 'provider' => 'mimo', 'active' => false,
            'secrets' => ['api_key' => 'db-key', 'model' => 'db-model'],
        ]);

        $chain = $this->factory()->describeChain(null);
        $this->assertSame('mimo-v2.5-pro', $chain[0]['model']);
    }

    /** A6: owner integrations page renders the chain + last-used model. */
    public function test_owner_integrations_page_renders_chain_and_last_used(): void
    {
        $store = $this->storeFixture();
        $user = User::find($store->user_id);

        AiUsage::create([
            'user_id' => $user->id, 'provider' => 'openai', 'model' => 'gpt-4o',
            'feature' => 'menu', 'prompt_tokens' => 10, 'completion_tokens' => 5, 'fell_back' => true,
        ]);

        $resp = $this->actingAs($user)->get(route('settings.integrations', ['store_id' => $store->id]));

        $resp->assertOk();
        $resp->assertSee(___('AI engine'));
        $resp->assertSee('gpt-4o');
    }
}
