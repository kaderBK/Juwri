<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Juwri\Modules\Content\Models\StudioSession;
use Juwri\Modules\Content\Services\Studio\StudioTemplateService;
use Tests\TestCase;

/**
 * P1-3: template package discovery, manifest validation, slug whitelist, render.
 */
class StudioTemplateServiceTest extends TestCase
{
    use RefreshDatabase;

    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->dir = sys_get_temp_dir().'/studio-tpl-'.Str::random(8);
        mkdir($this->dir, 0777, true);
        config(['studio.templates_path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    protected function makeTemplate(string $slug, ?array $manifest = null, string $blade = 'OK {{ $currency }}'): void
    {
        mkdir("{$this->dir}/{$slug}", 0777, true);
        file_put_contents("{$this->dir}/{$slug}/template.blade.php", $blade);
        file_put_contents("{$this->dir}/{$slug}/manifest.json", json_encode($manifest ?? [
            'name' => ucfirst($slug),
            'aspects' => ['a4', 'letter'],
            'colors' => ['accent' => '#8b2635', 'bg' => '#f6f1e7'],
            'density' => ['items_per_page' => 28, 'columns' => 2],
            'slots' => ['store_name'],
        ]));
    }

    public function test_discovers_valid_packages_and_keys_by_slug(): void
    {
        $this->makeTemplate('classic-bistro');
        $this->makeTemplate('modern-minimal');

        $all = app(StudioTemplateService::class)->all();

        $this->assertSame(['classic-bistro', 'modern-minimal'], array_keys($all));
        $this->assertSame('Classic-bistro', $all['classic-bistro']['name']);
        $this->assertSame('classic-bistro', $all['classic-bistro']['slug']);
    }

    public function test_bad_packages_are_skipped_not_fatal(): void
    {
        $this->makeTemplate('good');
        // Broken JSON.
        mkdir("{$this->dir}/broken-json");
        file_put_contents("{$this->dir}/broken-json/template.blade.php", 'x');
        file_put_contents("{$this->dir}/broken-json/manifest.json", '{nope');
        // Missing blade.
        mkdir("{$this->dir}/no-blade");
        file_put_contents("{$this->dir}/no-blade/manifest.json", '{"name":"x"}');
        // Missing required manifest key (no colors/density).
        $this->makeTemplate('missing-keys', ['name' => 'X', 'aspects' => ['a4']]);
        // Unknown aspects only.
        $this->makeTemplate('bad-aspects', [
            'name' => 'X', 'aspects' => ['a3'], 'colors' => ['a' => '#fff'], 'density' => ['columns' => 1],
        ]);
        // Bad slug chars are never even scanned.
        mkdir("{$this->dir}/Bad_Slug!");

        $this->assertSame(['good'], array_keys(app(StudioTemplateService::class)->all()));
    }

    public function test_unknown_slug_rejected(): void
    {
        $this->makeTemplate('real');
        $svc = app(StudioTemplateService::class);

        $this->assertFalse($svc->exists('../../etc/passwd'));
        $this->expectException(\InvalidArgumentException::class);
        $svc->manifest('../../etc/passwd');
    }

    public function test_render_binds_draft_and_validates_options(): void
    {
        $this->makeTemplate('bistro', null,
            '<html dir="{{ $rtl ? "rtl" : "ltr" }}">{{ $slots["store_name"] }}|{{ $aspect }}|{{ $colors["accent"] }}'
            .'|@foreach($draft["categories"] as $c){{ $c["name"] }}:@foreach($c["items"] as $i){{ $i["name"] }}={{ $i["price"] }}{{ $currency }};@endforeach @endforeach</html>');

        $s = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);
        Storage::disk('local')->put($s->storagePath('draft.json'), json_encode([
            'categories' => [['name' => 'Starters', 'items' => [['name' => 'Hummus', 'price' => 19.8]]]],
        ]));

        $html = app(StudioTemplateService::class)->render($s, 'bistro', [
            'aspect' => 'story',                       // not in manifest -> falls back to a4
            'colors' => ['accent' => '#123abc', 'bg' => 'javascript:alert(1)', 'nope' => '#fff'],
            'slots' => ['store_name' => '<b>Casa</b>', 'unlisted' => 'x'],
        ]);

        $this->assertStringContainsString('&lt;b&gt;Casa&lt;/b&gt;|a4|#123abc', $html); // escaped slot, aspect fallback, valid override
        $this->assertStringContainsString('Starters:Hummus=19.8SAR;', $html);
        $this->assertStringNotContainsString('javascript:', $html);                     // malformed hex rejected
    }

    public function test_render_without_draft_throws(): void
    {
        $this->makeTemplate('bistro');
        $s = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);

        $this->expectException(\RuntimeException::class);
        app(StudioTemplateService::class)->render($s, 'bistro');
    }
}
