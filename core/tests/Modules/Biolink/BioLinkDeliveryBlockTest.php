<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Tests\TestCase;

/**
 * bio-t15 Lane A / A5: the `delivery` block (SPEC §2 A5) — a NEW type distinct
 * from the legacy `delivery_apps` (LEDGER "Contract changes"). Config is
 * edited via the same "Name | URL" config_pairs textarea idiom; a known
 * aggregator name gets a built-in tinted glyph, an unknown one a generic
 * bag glyph. Supports list/grid layout.
 */
class BioLinkDeliveryBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    private function svc(): BioLinkService
    {
        return app(BioLinkService::class);
    }

    private function render(Store $store): string
    {
        $row = $this->svc()->blocksFor($store->id)[0];

        return (string) view('bio._blocks.delivery', ['block' => $row])->render();
    }

    /** @return array{0: User, 1: Store} */
    private function store(): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now(), 'user_type' => 'user']);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);

        return [$owner, $store];
    }

    public function test_type_delivery_config_pairs_persist_through_the_real_controller(): void
    {
        [$owner, $store] = $this->store();
        config(['settings.admin_allow_biolink' => true]);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);

        $resp = $this->actingAs($owner)->postJson(route('biolink.blocks.store'), [
            'type' => 'delivery',
            'label' => 'Order online',
            'config' => ['layout' => 'grid'],
            'config_pairs' => "Uber Eats | https://ubereats.example/store\nMy Local App | https://local.example/order",
        ]);

        $resp->assertOk();
        $saved = BioBlock::where('store_id', $store->id)->where('type', 'delivery')->first();
        $this->assertNotNull($saved);
        $this->assertSame('grid', $saved->config['layout']);
        $this->assertCount(2, $saved->config['apps']);
        $this->assertSame('Uber Eats', $saved->config['apps'][0]['name']);
    }

    public function test_known_app_gets_a_tinted_glyph_and_unknown_gets_the_generic_bag(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'delivery',
            'config' => ['apps' => [
                ['name' => 'Uber Eats', 'url' => 'https://ubereats.example/x'],
                ['name' => 'My Local App', 'url' => 'https://local.example/x'],
            ]],
        ]);

        $html = $this->render($store);

        $this->assertStringContainsString('Uber Eats', $html);
        // gap-fix: the brand tint is darkened via color-mix so the white monogram meets
        // WCAG-AA contrast (flat white-on-#06C167 failed).
        $this->assertStringContainsString('color-mix(in srgb, #06C167 68%, #000)', $html); // known glyph tint (darkened)
        $this->assertStringContainsString('My Local App', $html);
        $this->assertStringContainsString('<svg', $html); // generic bag glyph for the unknown one
    }

    public function test_grid_layout_class_is_applied(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'delivery',
            'config' => ['layout' => 'grid', 'apps' => [['name' => 'Talabat', 'url' => 'https://t.example']]],
        ]);

        $html = $this->render($store);
        $this->assertStringContainsString('bio-delivery--grid', $html);
    }

    public function test_no_apps_degrades_to_nothing(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create(['store_id' => $store->id, 'type' => 'delivery', 'config' => []]);

        $this->assertSame('', trim($this->render($store)));
    }
}
