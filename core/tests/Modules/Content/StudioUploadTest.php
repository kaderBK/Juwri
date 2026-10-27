<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Models\StudioSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * S4-P2: Menu Studio guest upload + extraction (free, no auth). Reuses the shared
 * MenuGeneratorService; the AI HTTP is faked.
 */
class StudioUploadTest extends TestCase
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
            'ai.default' => 'mimo', 'ai.fallback' => [], 'ai.vision' => [], 'ai.retries' => 0,
            'ai.providers.mimo' => [
                'driver' => 'openai', 'base_url' => 'https://api.xiaomimimo.com/v1',
                'api_key' => 'k', 'model' => 'm', 'supports_json' => true, 'supports_vision' => false,
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

    public function test_guest_upload_text_returns_draft(): void
    {
        $this->fakeAi(['categories' => [['name' => 'Drinks', 'items' => [['name' => 'Tea', 'description' => '', 'price' => 2]]]]]);

        $res = $this->post('/studio/upload', ['text' => 'Tea 2']);

        $res->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('data.categories.0.name', 'Drinks');
        $this->assertSame('extracted', StudioSession::first()->status);
    }

    public function test_guest_upload_image_returns_draft_and_stores_it(): void
    {
        config(['ai.vision' => ['mimo'], 'ai.providers.mimo.supports_vision' => true]);
        $this->fakeAi(['categories' => [['name' => 'Grill', 'items' => [['name' => 'Kebab', 'description' => '', 'price' => 7]]]]]);

        $res = $this->post('/studio/upload', ['image' => UploadedFile::fake()->image('menu.jpg', 800, 600)]);

        $res->assertOk()->assertJsonPath('data.categories.0.items.0.name', 'Kebab');
        $session = StudioSession::first();
        Storage::disk('local')->assertExists($session->storagePath('draft.json'));
    }

    public function test_empty_upload_rejected(): void
    {
        $this->post('/studio/upload', [])->assertStatus(400);
    }
}
