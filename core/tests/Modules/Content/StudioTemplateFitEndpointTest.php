<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Models\StudioSession;
use Juwri\Modules\Ai\Services\AiProviderException;
use Juwri\Modules\Ai\Services\AiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P3-3: POST /studio/template/fit — applies the plan to the stored draft,
 * fills the accent slot only when untouched, never fails when providers are down.
 */
class StudioTemplateFitEndpointTest extends TestCase
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
    }

    protected function sessionWithDraft(): StudioSession
    {
        $s = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);
        Storage::disk('local')->put($s->storagePath('draft.json'), json_encode(['categories' => [
            ['name' => 'Mains', 'items' => [
                ['name' => 'Kofta', 'description' => str_repeat('long text ', 30), 'price' => 52, 'variants' => [], 'extras' => []],
            ]],
        ]]));
        $this->call('POST', '/studio/template/options', ['slug' => 'classic-bistro'],
            ['studio_guest' => $s->guest_token])->assertOk();

        return $s;
    }

    protected function mockAi(): \Mockery\MockInterface
    {
        $mock = \Mockery::mock(AiService::class);
        $this->app->instance(AiService::class, $mock);

        return $mock;
    }

    public function test_fit_applies_plan_and_persists(): void
    {
        $this->mockAi()->shouldReceive('json')->once()->andReturn([
            'trims' => [['path' => '0.0', 'display' => 'Short and sweet']],
            'featured' => ['0.0'],
            'accent' => '#123456',
        ]);
        $s = $this->sessionWithDraft();

        $res = $this->call('POST', '/studio/template/fit', [], ['studio_guest' => $s->guest_token]);

        $res->assertOk()
            ->assertJsonPath('plan.trims', 1)
            ->assertJsonPath('plan.accent', '#123456')
            ->assertJsonPath('data.categories.0.items.0.display_description', 'Short and sweet');
        // Persisted to draft.json + accent stored on template options.
        $stored = json_decode(Storage::disk('local')->get($s->storagePath('draft.json')), true);
        $this->assertSame('Short and sweet', $stored['categories'][0]['items'][0]['display_description']);
        $options = json_decode(Storage::disk('local')->get($s->storagePath('template.json')), true);
        $this->assertSame('#123456', $options['colors']['accent']);
    }

    public function test_fit_never_overwrites_user_accent(): void
    {
        $this->mockAi()->shouldReceive('json')->once()->andReturn([
            'trims' => [], 'featured' => [], 'accent' => '#123456',
        ]);
        $s = $this->sessionWithDraft();
        $this->call('POST', '/studio/template/options',
            ['slug' => 'classic-bistro', 'colors' => ['accent' => '#abcdef']],
            ['studio_guest' => $s->guest_token])->assertOk();

        $this->call('POST', '/studio/template/fit', [], ['studio_guest' => $s->guest_token])->assertOk();

        $options = json_decode(Storage::disk('local')->get($s->storagePath('template.json')), true);
        $this->assertSame('#abcdef', $options['colors']['accent']);
    }

    public function test_fit_succeeds_when_providers_down(): void
    {
        $this->mockAi()->shouldReceive('json')->once()
            ->andThrow(new AiProviderException('down', 'mimo', false));
        $s = $this->sessionWithDraft();

        $res = $this->call('POST', '/studio/template/fit', [], ['studio_guest' => $s->guest_token]);

        $res->assertOk()->assertJsonPath('plan.source', 'fallback');
        $this->assertSame(1, $res->json('plan.trims')); // deterministic clamp still trims
    }

    public function test_fit_guards(): void
    {
        // No template chosen -> 404; no draft -> 409.
        $empty = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);
        $this->call('POST', '/studio/template/fit', [], ['studio_guest' => $empty->guest_token])->assertNotFound();

        $this->call('POST', '/studio/template/options', ['slug' => 'classic-bistro'],
            ['studio_guest' => $empty->guest_token])->assertOk();
        $this->call('POST', '/studio/template/fit', [], ['studio_guest' => $empty->guest_token])->assertStatus(409);
    }
}
