<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Models\StudioSession;
use Juwri\Modules\Content\Services\Studio\StudioCreditService;
use Juwri\Modules\Content\Services\Studio\StudioDesignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SI5 (#31/#35): style preset + aspect ratio seed the Nano Banana prompt.
 */
class StudioDesignPresetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class]);
        Storage::fake('local');
        config(['studio.gemini.api_key' => 'k', 'studio.credit_cost.pdf_design' => 5]);
    }

    public function test_preset_and_aspect_seed_the_prompt(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['inline_data' => ['data' => base64_encode('PNG')]]]]]],
        ], 200)]);

        $s = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);
        Storage::disk('local')->put($s->storagePath('draft.json'),
            json_encode(['categories' => [['name' => 'M', 'items' => [['name' => 'X', 'price' => 5]]]]]));
        app(StudioCreditService::class)->credit($s, 20, 'topup');

        app(StudioDesignService::class)->generate($s, '', 'cafe', 'a4');

        Http::assertSent(function ($r) {
            $text = data_get($r->data(), 'contents.0.parts.0.text', '');
            return str_contains($text, 'cozy cafe') && str_contains($text, 'aspect ratio: a4');
        });
    }
}
