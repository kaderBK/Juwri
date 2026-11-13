<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Models\StudioSession;
use Juwri\Modules\Content\Services\Studio\StudioFitService;
use Juwri\Modules\Content\Services\Studio\StudioTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P3-2: trims are reversible display overrides — they survive the autosave
 * round-trip, templates prefer them, originals never change.
 */
class StudioTemplateTrimsTest extends TestCase
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

    public function test_apply_trims_sets_overrides_without_touching_originals(): void
    {
        $draft = ['categories' => [['name' => 'Mains', 'items' => [
            ['name' => 'Kofta', 'description' => 'A very long original description', 'price' => 52],
        ]]]];

        $out = app(StudioFitService::class)->applyTrims($draft, [
            'trims' => [['path' => '0.0', 'display' => 'Short version'], ['path' => '4.4', 'display' => 'bogus']],
            'featured' => ['0.0', '9.9'],
        ]);

        $item = $out['categories'][0]['items'][0];
        $this->assertSame('A very long original description', $item['description']);
        $this->assertSame('Short version', $item['display_description']);
        $this->assertTrue($item['featured']);
    }

    public function test_autosave_round_trip_preserves_overrides_and_tags(): void
    {
        $s = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);

        $res = $this->call('POST', '/studio/save-draft', ['categories' => [
            ['name' => 'Mains', 'items' => [[
                'name' => 'Kofta', 'description' => 'Original long text', 'price' => 52,
                'display_description' => 'Trimmed text', 'tags' => ['vegan', 'not_a_real_tag'],
            ]]],
        ]], ['studio_guest' => $s->guest_token]);

        $res->assertOk();
        $item = $res->json('data.categories.0.items.0');
        $this->assertSame('Original long text', $item['description']);
        $this->assertSame('Trimmed text', $item['display_description']);
        $this->assertSame(['vegan'], $item['tags']); // unknown tag dropped

        // Dropping the override client-side removes it server-side too.
        $res2 = $this->call('POST', '/studio/save-draft', ['categories' => [
            ['name' => 'Mains', 'items' => [['name' => 'Kofta', 'description' => 'Original long text', 'price' => 52]]],
        ]], ['studio_guest' => $s->guest_token]);
        $this->assertArrayNotHasKey('display_description', $res2->json('data.categories.0.items.0'));
    }

    public function test_background_art_layers_into_every_template(): void
    {
        $s = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);
        Storage::disk('local')->put($s->storagePath('draft.json'), json_encode(['categories' => [
            ['name' => 'Mains', 'items' => [['name' => 'Kofta', 'description' => '', 'price' => 52, 'variants' => [], 'extras' => []]]],
        ]]));
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        Storage::disk('local')->put($s->storagePath('template-bg.png'), $png);

        config(['studio.templates_path' => null]);
        foreach (array_keys(app(StudioTemplateService::class)->all()) as $slug) {
            $html = app(StudioTemplateService::class)->render($s, $slug);
            $this->assertStringContainsString('data:image/png;base64,', $html, $slug);
            $this->assertStringContainsString('.sheet::before', $html, "$slug art layer");
            $this->assertStringContainsString('.sheet::after', $html, "$slug contrast scrim");
        }

        // No bg file -> no layering block.
        Storage::disk('local')->delete($s->storagePath('template-bg.png'));
        $this->assertStringNotContainsString('.sheet::before',
            app(StudioTemplateService::class)->render($s, 'classic-bistro'));
    }

    public function test_share_page_embeds_watermarked_template_without_guest_token(): void
    {
        $s = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);
        Storage::disk('local')->put($s->storagePath('draft.json'), json_encode(['categories' => [
            ['name' => 'Mains', 'items' => [['name' => 'Signature Kofta', 'description' => '', 'price' => 52, 'variants' => [], 'extras' => []]]],
        ]]));
        $this->call('POST', '/studio/template/options', ['slug' => 'classic-bistro'],
            ['studio_guest' => $s->guest_token])->assertOk();

        $html = $this->get('/studio/s/'.$s->ensureShareToken())->assertOk()->getContent();

        $this->assertStringContainsString('tplshare', $html);          // designed embed present
        $this->assertStringContainsString('Signature Kofta', $html);   // menu content in srcdoc
        $this->assertStringContainsString('position:fixed', $html);    // watermark overlay injected
        $this->assertStringNotContainsString($s->guest_token, $html);  // credential never leaks
    }

    public function test_share_page_without_template_choice_falls_back_to_plain_list(): void
    {
        $s = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);
        Storage::disk('local')->put($s->storagePath('draft.json'), json_encode(['categories' => [
            ['name' => 'Mains', 'items' => [['name' => 'Plain Dish', 'description' => '', 'price' => 10, 'variants' => [], 'extras' => []]]],
        ]]));

        $html = $this->get('/studio/s/'.$s->ensureShareToken())->assertOk()->getContent();

        $this->assertStringNotContainsString('tplshare', $html);
        $this->assertStringContainsString('Plain Dish', $html);
    }

    public function test_templates_render_override_when_present(): void
    {
        $s = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);
        Storage::disk('local')->put($s->storagePath('draft.json'), json_encode(['categories' => [
            ['name' => 'Mains', 'items' => [[
                'name' => 'Kofta', 'description' => 'ORIGINAL-TEXT', 'price' => 52,
                'display_description' => 'TRIMMED-TEXT', 'variants' => [], 'extras' => [],
            ]]],
        ]]));

        config(['studio.templates_path' => null]);
        foreach (array_keys(app(StudioTemplateService::class)->all()) as $slug) {
            $html = app(StudioTemplateService::class)->render($s, $slug);
            $this->assertStringContainsString('TRIMMED-TEXT', $html, $slug);
            $this->assertStringNotContainsString('ORIGINAL-TEXT', $html, $slug);
        }
    }
}
