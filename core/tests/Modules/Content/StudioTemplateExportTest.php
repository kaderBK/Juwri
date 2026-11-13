<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Models\StudioSession;
use Juwri\Modules\Content\Services\Studio\StudioTemplateExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P2-2: template print export — Browsershot faked (CI has no Chrome). The real
 * render path is covered up to the exporter boundary.
 */
class StudioTemplateExportTest extends TestCase
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

    protected function sessionWithDraft(int $credits = 20): StudioSession
    {
        $s = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);
        Storage::disk('local')->put($s->storagePath('draft.json'), json_encode([
            'categories' => [['name' => 'Starters', 'items' => [
                ['name' => 'Hummus', 'description' => '', 'price' => 19.8, 'variants' => [], 'extras' => []],
            ]]],
        ]));
        if ($credits > 0) {
            app(\Juwri\Modules\Content\Services\Studio\StudioCreditService::class)->credit($s, $credits, 'topup');
        }

        return $s;
    }

    protected function balance(StudioSession $s): int
    {
        return app(\Juwri\Modules\Content\Services\Studio\StudioCreditService::class)->balance($s);
    }

    protected function fakeExporter(): \Mockery\MockInterface
    {
        $mock = \Mockery::mock(StudioTemplateExportService::class);
        $this->app->instance(StudioTemplateExportService::class, $mock);

        return $mock;
    }

    public function test_export_returns_pdf_and_stores_copy(): void
    {
        $s = $this->sessionWithDraft();
        $mock = $this->fakeExporter();
        $mock->shouldReceive('available')->andReturnTrue();
        $mock->shouldReceive('toPdf')->once()
            ->withArgs(fn ($html, $aspect) => str_contains($html, 'Hummus') && $aspect === 'a4')
            ->andReturn('%PDF-fake');

        $res = $this->call('POST', '/studio/template/export',
            ['slug' => 'classic-bistro', 'aspect' => 'a4'], ['studio_guest' => $s->guest_token]);

        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('menu-classic-bistro-a4.pdf', (string) $res->headers->get('Content-Disposition'));
        $this->assertSame('%PDF-fake', $res->getContent());
        $this->assertTrue(Storage::disk('local')->exists($s->storagePath('template-export.pdf')));
        // P2-4: debited exactly once (20 - pdf_design cost).
        $this->assertSame(20 - (int) config('studio.credit_cost.pdf_design', 5), $this->balance($s));
    }

    public function test_insufficient_credits_402_and_no_render(): void
    {
        $s = $this->sessionWithDraft(0);
        $mock = $this->fakeExporter();
        $mock->shouldReceive('available')->andReturnTrue();
        $mock->shouldNotReceive('toPdf');

        $this->call('POST', '/studio/template/export', ['slug' => 'classic-bistro'],
            ['studio_guest' => $s->guest_token])
            ->assertStatus(402)->assertJsonPath('need_credits', true);
        $this->assertSame(0, $this->balance($s));
    }

    public function test_failed_render_refunds_credits(): void
    {
        $s = $this->sessionWithDraft(20);
        $mock = $this->fakeExporter();
        $mock->shouldReceive('available')->andReturnTrue();
        $mock->shouldReceive('toPdf')->once()->andThrow(new \RuntimeException('chrome died'));

        $this->call('POST', '/studio/template/export', ['slug' => 'classic-bistro'],
            ['studio_guest' => $s->guest_token])->assertStatus(500);

        $this->assertSame(20, $this->balance($s)); // debit refunded
        $this->assertFalse(Storage::disk('local')->exists($s->storagePath('template-export.pdf')));
    }

    public function test_export_uses_stored_options_when_no_params(): void
    {
        $s = $this->sessionWithDraft();
        $this->call('POST', '/studio/template/options',
            ['slug' => 'classic-bistro', 'aspect' => 'letter'], ['studio_guest' => $s->guest_token])->assertOk();

        $mock = $this->fakeExporter();
        $mock->shouldReceive('available')->andReturnTrue();
        $mock->shouldReceive('toPdf')->once()
            ->withArgs(fn ($html, $aspect) => $aspect === 'letter')
            ->andReturn('%PDF-fake');

        $this->call('POST', '/studio/template/export', [], ['studio_guest' => $s->guest_token])->assertOk();
    }

    public function test_story_aspect_exports_png(): void
    {
        $s = $this->sessionWithDraft();
        // modern-minimal supports story; persist that choice.
        $this->call('POST', '/studio/template/options',
            ['slug' => 'modern-minimal', 'aspect' => 'story'], ['studio_guest' => $s->guest_token])->assertOk();

        $mock = $this->fakeExporter();
        $mock->shouldReceive('available')->andReturnTrue();
        $mock->shouldReceive('toPng')->once()
            ->withArgs(fn ($html, $aspect) => $aspect === 'story' && str_contains($html, 'aspect-story'))
            ->andReturn('PNG-fake');

        $res = $this->call('POST', '/studio/template/export', [], ['studio_guest' => $s->guest_token]);

        $res->assertOk();
        $this->assertSame('image/png', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('menu-modern-minimal-story.png', (string) $res->headers->get('Content-Disposition'));
        $this->assertTrue(Storage::disk('local')->exists($s->storagePath('template-export.png')));
    }

    public function test_export_501_when_toolchain_missing(): void
    {
        $s = $this->sessionWithDraft();
        $mock = $this->fakeExporter();
        $mock->shouldReceive('available')->andReturnFalse();

        $this->call('POST', '/studio/template/export',
            ['slug' => 'classic-bistro'], ['studio_guest' => $s->guest_token])
            ->assertStatus(501)->assertJsonPath('error', 'export_unavailable');
    }

    public function test_export_guards_slug_draft_and_failure(): void
    {
        $s = $this->sessionWithDraft();

        // Unknown slug -> 404 (before toolchain check).
        $this->call('POST', '/studio/template/export', ['slug' => 'nope'],
            ['studio_guest' => $s->guest_token])->assertNotFound();

        // No draft -> 409.
        $empty = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);
        $mock = $this->fakeExporter();
        $mock->shouldReceive('available')->andReturnTrue();
        $this->call('POST', '/studio/template/export', ['slug' => 'classic-bistro'],
            ['studio_guest' => $empty->guest_token])->assertStatus(409);

        // Renderer blow-up -> 500, no stored file.
        $mock->shouldReceive('toPdf')->andThrow(new \RuntimeException('chrome died'));
        $this->call('POST', '/studio/template/export', ['slug' => 'classic-bistro'],
            ['studio_guest' => $s->guest_token])->assertStatus(500);
        $this->assertFalse(Storage::disk('local')->exists($s->storagePath('template-export.pdf')));
    }
}
