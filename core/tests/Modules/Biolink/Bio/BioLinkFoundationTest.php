<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink\Bio;

use App\Support\Csp\StorefrontPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Lane C · C1 foundation: sprite, base CSS, the 14 block partials, the master
 * document (one nonced token <style> + one nonced click beacon), and the CSP
 * font-host addition. Proven through a harness route (no Lane A controller yet).
 */
class BioLinkFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    /** The child template a family layout would be — profile + block loop. */
    private const CHILD = "@extends('bio.layout')\n@section('page')\n@include('bio._profile')\n@include('bio._blocks')\n@endsection";

    private function contract(array $overrides = []): array
    {
        $store = (object) [
            'id' => 4321, 'slug' => 'demo-diner', 'title' => 'Demo Diner',
            'bio_title' => 'Demo Diner', 'bio_description' => 'Late-night smash burgers',
            'bio_main_image' => null, 'never_show_title' => 0,
        ];
        $branch = (object) [
            'name' => 'Olaya', 'address' => '12 Olaya St', 'phone' => '+966500000000',
            'whatsapp' => '+966500000000', 'hours' => json_encode(['Sun–Thu' => '12:00 – 23:00']),
            'latitude' => 24.7, 'longitude' => 46.6,
        ];
        $tokens = ['--bg' => '#9cd6c8', '--card' => '#ffffff', '--ink' => '#123c32',
            '--muted' => '#2c5a4e', '--accent' => '#0f7d5a', '--radius' => '999px'];

        return array_merge([
            'store' => $store,
            'branch' => $branch,
            'preset' => [
                'slug' => 'classic_flat', 'name' => 'Classic Flat', 'family' => 'list',
                'tier' => 'basic', 'rtl_native' => false,
                'fonts' => [['family' => 'Poppins', 'weights' => [400, 600, 700]]],
                'tokens' => $tokens,
            ],
            'tokens' => $tokens,
            'bioSettings' => ['title' => 'Demo Diner', 'status_label' => 'OPEN NOW'],
            'poweredBy' => 'Powered by MyMenu',
            'rtl' => false,
            'source' => 'qr',
            'blocks' => $this->sampleBlocks(),
        ], $overrides);
    }

    private function sampleBlocks(): array
    {
        return [
            ['id' => 1, 'type' => 'menu', 'label' => 'Full Menu', 'url' => 'https://x.test/m', 'config' => ['sub' => 'Burgers & shakes']],
            ['id' => 2, 'type' => 'link', 'label' => 'Order Delivery', 'url' => 'https://x.test/o', 'config' => ['sub' => '30 min', 'icon' => 'i-scooter']],
            ['id' => 3, 'type' => 'menu_item', 'label' => 'Truffle Double', 'url' => '', 'config' => ['price_label' => '38 SAR', 'sub' => 'Black bun']],
            ['id' => 4, 'type' => 'featured_dish', 'label' => '', 'config' => ['title' => 'Noir Double', 'subtitle' => 'This week', 'price_label' => '38 SAR', 'schedule_label' => 'Weekly']],
            ['id' => 5, 'type' => 'delivery_apps', 'label' => 'Order on', 'config' => ['apps' => [['name' => 'HungerStation', 'url' => 'https://h.test'], ['name' => 'Jahez', 'url' => 'https://j.test']]]],
            ['id' => 6, 'type' => 'hours', 'label' => 'Hours', 'config' => ['rows' => [['label' => 'Thu', 'value' => '12:00 – 00:30', 'now' => true]]]],
            ['id' => 7, 'type' => 'map', 'label' => 'Find Us', 'config' => ['address' => '12 Olaya St']],
            ['id' => 8, 'type' => 'whatsapp', 'label' => 'WhatsApp', 'config' => ['phone' => '+966500000000', 'text' => 'Order please']],
            ['id' => 9, 'type' => 'reservation', 'label' => 'Book', 'url' => 'https://r.test', 'config' => ['note' => 'Tonight']],
            ['id' => 10, 'type' => 'reviews', 'label' => 'Reviews', 'config' => ['rating' => '4.8', 'count' => '1,204']],
            ['id' => 11, 'type' => 'announcement', 'config' => ['text' => 'Closed Eid Monday']],
            ['id' => 12, 'type' => 'socials', 'config' => ['links' => [['platform' => 'instagram', 'url' => 'https://i.test'], ['platform' => 'tiktok', 'url' => 'https://t.test']]]],
            ['id' => 13, 'type' => 'capture', 'label' => 'Join', 'config' => ['text' => 'Weekly drops', 'consent' => 'No spam']],
            ['id' => 14, 'type' => 'video', 'label' => 'Watch', 'config' => ['media' => 'https://cdn.test/clip.mp4']],
        ];
    }

    private function renderPage(array $contract): string
    {
        // Two-segment path so the single-segment {slug} storefront catch-all can't shadow it.
        Route::get('/__bio_c1/render', fn () => Blade::render(self::CHILD, $contract))->middleware('web');

        return $this->get('/__bio_c1/render')->assertOk()->getContent();
    }

    public function test_bio_master_document_renders_with_one_nonced_style_and_one_nonced_script(): void
    {
        $html = $this->renderPage($this->contract());

        // preset marker + family class on the root element
        $this->assertStringContainsString('bio--classic_flat', $html);
        $this->assertStringContainsString('fam-list', $html);

        // tokens emitted as CSS custom properties in the page
        $this->assertStringContainsString('--bg:#9cd6c8', $html);
        $this->assertStringContainsString('--accent:#0f7d5a', $html);

        // exactly one <style> (nonced token stylesheet) and one <script> (beacon)
        $this->assertSame(1, substr_count($html, '<style'), 'exactly one style tag');
        $this->assertSame(1, substr_count($html, '<script'), 'exactly one script tag');

        // both carry a nonce; nothing un-nonced slips through (CspEnforce-shaped check)
        $this->assertSame(0, preg_match_all('/<style(?![^>]*\bnonce\b)[ >]/i', $html), 'no un-nonced style');
        $this->assertSame(0, preg_match_all('/<script(?![^>]*\bsrc=)(?![^>]*\bnonce\b)[ >]/i', $html), 'no un-nonced script');

        // Google Fonts link built from preset json
        $this->assertStringContainsString('fonts.googleapis.com/css2?family=Poppins', $html);

        // sprite + source threading (G5) + beacon
        $this->assertStringContainsString('id="i-menu"', $html);
        $this->assertStringContainsString('data-bio-source="qr"', $html);
        $this->assertStringContainsString("/c/' + encodeURIComponent(id)", $html);
    }

    public function test_every_block_type_renders_on_the_page(): void
    {
        $html = $this->renderPage($this->contract());

        $this->assertStringContainsString('bio-link', $html);
        $this->assertStringContainsString('bio-feat', $html);
        $this->assertStringContainsString('bio-apps', $html);
        $this->assertStringContainsString('bio-hours', $html);
        $this->assertStringContainsString('bio-map', $html);
        $this->assertStringContainsString('wa.me/966500000000', $html);
        $this->assertStringContainsString('bio-reviews', $html);
        $this->assertStringContainsString('bio-ann', $html);
        $this->assertStringContainsString('bio-socials', $html);
        $this->assertStringContainsString('bio-capture', $html);
        $this->assertStringContainsString('<video', $html);
        $this->assertStringContainsString('https://cdn.test/clip.mp4', $html);
        // G6: self-hosted video only — no external iframe.
        $this->assertStringNotContainsString('<iframe', $html);

        // every clickable block carries its id for the beacon
        $this->assertStringContainsString('data-bio-block="2"', $html);
    }

    public function test_rtl_native_forces_rtl_even_when_contract_rtl_is_false(): void
    {
        $contract = $this->contract();
        $contract['preset']['slug'] = 'majlis_gold';
        $contract['preset']['rtl_native'] = true;
        $contract['rtl'] = false;

        $html = $this->renderPage($contract);

        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('bio--majlis_gold', $html);
    }

    public function test_blocks_render_sane_empty_state_when_data_is_missing(): void
    {
        $empty = array_map(
            static fn (string $t) => ['id' => 0, 'type' => $t, 'config' => []],
            ['link', 'menu', 'menu_item', 'hours', 'map', 'whatsapp', 'delivery_apps',
                'reservation', 'reviews', 'announcement', 'socials', 'capture', 'video', 'featured_dish']
        );
        // also a block missing its config key entirely
        $empty[] = ['id' => 0, 'type' => 'link'];

        $html = $this->renderPage($this->contract(['blocks' => $empty, 'bioSettings' => []]));

        // renders without error; empty content-less blocks emit nothing loud
        $this->assertStringNotContainsString('Undefined', $html);
        $this->assertStringNotContainsString('data-bio-block="14"', $html); // video with no media -> absent
    }

    public function test_csp_storefront_policy_allows_google_font_hosts(): void
    {
        $response = new Response('');
        (new StorefrontPolicy)->applyTo($response);
        $header = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('https://fonts.googleapis.com', $header);
        $this->assertStringContainsString('https://fonts.gstatic.com', $header);
        // untouched invariants the wider CSP tests pin
        $this->assertStringContainsString("script-src 'self' 'nonce-", $header);
        $this->assertStringContainsString("style-src 'self'", $header);
    }

    public function test_no_un_nonced_inline_style_or_script_in_bio_views(): void
    {
        $dir = resource_path('views/bio');
        $offenders = [];

        foreach (File::allFiles($dir) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $body = $file->getContents();
            $rel = str_replace($dir.'/', '', $file->getPathname());

            if (preg_match_all('/<script(?![^>]*\bsrc=)(?![^>]*\bnonce\b)[ >]/i', $body, $m)) {
                $offenders[] = $rel.' ('.count($m[0]).' <script>)';
            }
            if (preg_match_all('/<style(?![^>]*\bnonce\b)[ >]/i', $body, $m)) {
                $offenders[] = $rel.' ('.count($m[0]).' <style>)';
            }
        }

        $this->assertSame([], $offenders, "un-nonced inline blocks in bio views:\n".implode("\n", $offenders));
    }
}
