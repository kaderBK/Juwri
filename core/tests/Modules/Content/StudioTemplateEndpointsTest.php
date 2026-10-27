<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Juwri\Modules\Content\Models\StudioSession;
use Tests\TestCase;

/**
 * P1-5: template gallery + preview endpoints — session gating, slug whitelist,
 * draft binding. Uses the real classic-bistro package (no path override).
 */
class StudioTemplateEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            EncryptCookies::class,
        ]);
        Storage::fake('local');
    }

    protected function sessionWithDraft(string $itemName = 'Hummus Royale'): StudioSession
    {
        $s = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);
        Storage::disk('local')->put($s->storagePath('draft.json'), json_encode([
            'categories' => [['name' => 'Starters', 'items' => [
                ['name' => $itemName, 'description' => '', 'price' => 19.8, 'variants' => [], 'extras' => []],
            ]]],
        ]));

        return $s;
    }

    public function test_gallery_lists_real_packages(): void
    {
        $res = $this->get('/studio/templates');

        $res->assertOk()->assertJsonPath('success', true);
        $slugs = collect($res->json('data'))->pluck('slug');
        $this->assertTrue($slugs->contains('classic-bistro'));
        $row = collect($res->json('data'))->firstWhere('slug', 'classic-bistro');
        $this->assertSame('Classic Bistro', $row['name']);
        $this->assertContains('a4', $row['aspects']);
        $this->assertArrayHasKey('accent', $row['colors']);
    }

    public function test_preview_renders_own_draft_with_csp(): void
    {
        $s = $this->sessionWithDraft();

        $res = $this->call('GET', '/studio/template/preview',
            ['slug' => 'classic-bistro', 'aspect' => 'a4'], ['studio_guest' => $s->guest_token]);

        $res->assertOk();
        $this->assertStringContainsString('Hummus Royale', $res->getContent());
        $this->assertStringContainsString('aspect-a4', $res->getContent());
        $this->assertStringContainsString("default-src 'none'", (string) $res->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
    }

    public function test_preview_is_cookie_scoped_not_cross_session(): void
    {
        $this->sessionWithDraft('SECRET-DISH');

        // No cookie -> a fresh session with no draft; the other session's menu
        // must be unreachable.
        $res = $this->get('/studio/template/preview?slug=classic-bistro');

        $res->assertStatus(409);
        $this->assertStringNotContainsString('SECRET-DISH', $res->getContent());
    }

    public function test_preview_unknown_and_traversal_slugs_404(): void
    {
        $s = $this->sessionWithDraft();

        $this->call('GET', '/studio/template/preview', ['slug' => 'nope'],
            ['studio_guest' => $s->guest_token])->assertNotFound();
        $this->call('GET', '/studio/template/preview', ['slug' => '../../secrets'],
            ['studio_guest' => $s->guest_token])->assertNotFound();
    }

    public function test_preview_rtl_flag_flips_direction(): void
    {
        $s = $this->sessionWithDraft();

        $ltr = $this->call('GET', '/studio/template/preview',
            ['slug' => 'classic-bistro', 'rtl' => '0'], ['studio_guest' => $s->guest_token]);
        $rtl = $this->call('GET', '/studio/template/preview',
            ['slug' => 'classic-bistro', 'rtl' => '1'], ['studio_guest' => $s->guest_token]);

        $this->assertStringContainsString('dir="ltr"', $ltr->getContent());
        $this->assertStringContainsString('dir="rtl"', $rtl->getContent());
    }

    public function test_thumbs_serve_when_generated_and_unknown_slug_404s(): void
    {
        // P5-2 generated real thumbs into the packages.
        $res = $this->get('/studio/template/thumb/classic-bistro');
        $res->assertOk();
        $this->assertSame('image/png', $res->headers->get('Content-Type'));

        $row = collect($this->get('/studio/templates')->json('data'))->firstWhere('slug', 'classic-bistro');
        $this->assertStringContainsString('template/thumb', (string) $row['thumb']);

        $this->get('/studio/template/thumb/nope')->assertNotFound();
    }

    public function test_options_persist_and_feed_the_preview(): void
    {
        $s = $this->sessionWithDraft();

        $this->call('POST', '/studio/template/options', [
            'slug' => 'classic-bistro',
            'aspect' => 'letter',
            'colors' => ['accent' => '#123abc'],
            'slots' => ['store_name' => 'Casa Persisted'],
            'rtl' => false,
        ], ['studio_guest' => $s->guest_token])
            ->assertOk()->assertJsonPath('success', true);

        // Preview with NO query params picks up the stored options.
        $html = $this->call('GET', '/studio/template/preview',
            ['slug' => 'classic-bistro'], ['studio_guest' => $s->guest_token])->getContent();

        $this->assertStringContainsString('aspect-letter', $html);
        $this->assertStringContainsString('#123abc', $html);
        $this->assertStringContainsString('Casa Persisted', $html);
    }

    public function test_options_reject_unknown_template(): void
    {
        $s = $this->sessionWithDraft();

        $this->call('POST', '/studio/template/options', ['slug' => '../../etc'],
            ['studio_guest' => $s->guest_token])->assertNotFound();
    }

    public function test_purge_removes_template_options_file(): void
    {
        $s = $this->sessionWithDraft();
        $this->call('POST', '/studio/template/options', ['slug' => 'classic-bistro'],
            ['studio_guest' => $s->guest_token])->assertOk();
        $this->assertTrue(Storage::disk('local')->exists($s->storagePath('template.json')));

        $s->purgeFiles();

        $this->assertFalse(Storage::disk('local')->exists($s->storagePath('template.json')));
    }
}
