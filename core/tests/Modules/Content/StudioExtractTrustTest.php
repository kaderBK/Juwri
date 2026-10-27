<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * SI2: extraction trust — multi-image stitch (#9) + confidence/price-sanity flags (#6,#17).
 */
class StudioExtractTrustTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\EncryptCookies::class,
        ]);
        Storage::fake('local');
        config([
            'ai.default' => 'mimo', 'ai.fallback' => [], 'ai.vision' => ['mimo'], 'ai.retries' => 0,
            'ai.providers.mimo' => [
                'driver' => 'openai', 'base_url' => 'https://api.xiaomimimo.com/v1',
                'api_key' => 'k', 'model' => 'm', 'supports_json' => true, 'supports_vision' => true,
            ],
        ]);
    }

    private function fakeAi(array $json): void
    {
        Http::fake(['api.xiaomimimo.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode($json)]]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5],
        ], 200)]);
    }

    public function test_multi_image_upload_merges(): void
    {
        // Both pages extract the same category -> merged + deduped to one.
        $this->fakeAi(['categories' => [['name' => 'Mains', 'items' => [['name' => 'Burger', 'description' => '', 'price' => 9]]]]]);

        $res = $this->post('/studio/upload', ['images' => [
            UploadedFile::fake()->image('p1.jpg', 700, 500),
            UploadedFile::fake()->image('p2.jpg', 700, 500),
        ]]);

        $res->assertOk()->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.categories')
            ->assertJsonCount(1, 'data.categories.0.items');
    }

    public function test_multi_image_skips_a_failed_page(): void
    {
        // SI13 #88: page 1 fails at the model, page 2 succeeds -> partial, not fatal.
        Http::fake(['api.xiaomimimo.com/*' => Http::sequence()
            ->push('upstream error', 500)
            ->push([
                'choices' => [['message' => ['content' => json_encode(['categories' => [['name' => 'Grill', 'items' => [['name' => 'Kebab', 'price' => 7]]]]])]]],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5],
            ], 200),
        ]);

        $res = $this->post('/studio/upload', ['images' => [
            UploadedFile::fake()->image('p1.jpg', 700, 500),
            UploadedFile::fake()->image('p2.jpg', 700, 500),
        ]]);

        $res->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('data.categories.0.name', 'Grill'); // survived on the good page
    }

    public function test_flags_missing_and_outlier_prices(): void
    {
        $this->fakeAi(['categories' => [['name' => 'Menu', 'items' => [
            ['name' => 'No Price', 'description' => '', 'price' => null],
            ['name' => 'Gold Plate', 'description' => '', 'price' => 99999],
        ]]]]);

        $res = $this->post('/studio/upload', ['text' => 'anything']);

        $res->assertOk()
            ->assertJsonPath('flags.missing_price', 1);
        $this->assertNotEmpty($res->json('flags.outliers'));
    }
}
