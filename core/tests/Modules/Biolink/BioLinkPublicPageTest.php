<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Models\BioClick;
use Tests\TestCase;

/**
 * BIOLINK-RUN A4: the public `GET {slug}/bio` controller contract (HTTP through
 * the real route/middleware stack). Covers the 302-to-menu dark path, the render
 * path, G3 preset fallback, G1 page-view recording, G5 source classification and
 * the signed-preview bypass.
 */
class BioLinkPublicPageTest extends TestCase
{
    use RefreshDatabase;

    private function enableBio(Store $store, array $overrides = []): void
    {
        config(['settings.admin_allow_biolink' => true]);
        EntitlementWriter::grant($store->user, 'biolink');
        StoreOption::updateStoreOption($store->id, 'bio_settings', json_encode(array_merge([
            'enabled' => true, 'preset' => 'classic_flat', 'mode' => 'menu_first',
        ], $overrides)));
        StoreOption::flushOptionMemo();
    }

    public function test_dark_store_302s_to_menu_never_404(): void
    {
        $store = Store::factory()->create();

        $res = $this->get('/'.$store->slug.'/bio');

        $res->assertStatus(302);
        $this->assertStringContainsString($store->slug, $res->headers->get('Location'));
        $this->assertStringNotContainsString('/bio', $res->headers->get('Location'));
        // dark: no view recorded
        $this->assertSame(0, BioClick::where('store_id', $store->id)->count());
    }

    public function test_enabled_feature_but_settings_off_302s_to_menu(): void
    {
        $store = Store::factory()->create();
        config(['settings.admin_allow_biolink' => true]);
        EntitlementWriter::grant($store->user, 'biolink');
        StoreOption::updateStoreOption($store->id, 'bio_settings', json_encode(['enabled' => false]));
        StoreOption::flushOptionMemo();

        $this->get('/'.$store->slug.'/bio')->assertStatus(302);
    }

    public function test_unknown_slug_404s(): void
    {
        $this->get('/no-such-store/bio')->assertStatus(404);
    }

    public function test_enabled_store_renders_and_records_a_view(): void
    {
        $store = Store::factory()->create();
        $this->enableBio($store);
        BioBlock::factory()->create(['store_id' => $store->id, 'label' => 'Order now', 'url' => 'https://x.example']);

        $res = $this->get('/'.$store->slug.'/bio');

        $res->assertStatus(200);
        $res->assertSeeHtml('data-bio-preset="classic_flat"');
        $res->assertSeeHtml('data-bio-blocks="1"');
        $res->assertSee('Order now');

        // G1: exactly one page-view row at the sentinel block_id=0
        $view = BioClick::where('store_id', $store->id)->where('block_id', BioClick::VIEW_SENTINEL)->first();
        $this->assertNotNull($view);
        $this->assertSame(1, $view->count);
    }

    public function test_text_image_and_divider_blocks_render(): void
    {
        $store = Store::factory()->create();
        $this->enableBio($store);
        BioBlock::factory()->create(['store_id' => $store->id, 'type' => 'text', 'label' => 'About', 'config' => ['title' => 'Our story', 'text' => 'Family run since 2019.']]);
        BioBlock::factory()->create(['store_id' => $store->id, 'type' => 'image', 'label' => 'Our patio', 'config' => ['image' => 'https://cdn.example.test/patio.jpg']]);
        BioBlock::factory()->create(['store_id' => $store->id, 'type' => 'divider', 'label' => 'Menu']);

        $html = $this->get('/'.$store->slug.'/bio')->assertOk()->getContent();

        $this->assertStringContainsString('Our story', $html);
        $this->assertStringContainsString('Family run since 2019.', $html);
        $this->assertStringContainsString('bio-image', $html);
        $this->assertStringContainsString('patio.jpg', $html);
        $this->assertStringContainsString('bio-divider', $html);
    }

    public function test_owner_can_add_a_new_text_block_type(): void
    {
        $store = Store::factory()->create();
        config(['settings.admin_allow_biolink' => true]);
        EntitlementWriter::grant($store->user, 'biolink');
        session(['dashboard_store_id' => $store->id]);

        $this->actingAs($store->user)
            ->post(route('biolink.blocks.store'), ['type' => 'text', 'label' => 'Hello', 'config' => ['text' => 'Hi there']])
            ->assertRedirect();

        $this->assertDatabaseHas('bio_blocks', ['store_id' => $store->id, 'type' => 'text']);
    }

    public function test_header_shows_the_store_name_and_logo_not_a_placeholder(): void
    {
        // Regression: the header profile is built in the controller and passed as
        // view data. It used to be built in the layout's @php, which Blade runs AFTER
        // rendering the family @section — so the header partials saw an undefined
        // $profile and always showed the bare "◈" glyph with no name. A store with a
        // name + logo (and only the 'default.png' bio-image sentinel) must show them.
        $store = Store::factory()->create([
            'title' => 'Nadia Bakes',
            'main_image' => 'nadia-logo.png',
            'bio_main_image' => 'default.png',
        ]);
        $this->enableBio($store);
        BioBlock::factory()->create(['store_id' => $store->id, 'label' => 'Order now', 'url' => 'https://x.example']);

        $html = $this->get('/'.$store->slug.'/bio')->assertOk()->getContent();

        $this->assertStringContainsString('Nadia Bakes', $html, 'store name renders in the header');
        $this->assertStringContainsString('nadia-logo.png', $html, 'avatar falls back to the store logo (main_image), skipping default.png');
        $this->assertStringNotContainsString('<span aria-hidden="true">◈</span>', $html, 'no bare placeholder glyph when a logo exists');
    }

    public function test_g3_locked_pro_preset_falls_back_to_classic_flat(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        $store = Store::factory()->create();
        // stored a pro preset, but plan is basic (no bio_presets_all grant)
        $this->enableBio($store, ['preset' => 'neon_arcade']);

        $res = $this->get('/'.$store->slug.'/bio');

        $res->assertStatus(200);
        $res->assertSeeHtml('data-bio-preset="classic_flat"');
    }

    public function test_g5_qr_id_classifies_source_as_qr(): void
    {
        $store = Store::factory()->create();
        $this->enableBio($store);

        $res = $this->get('/'.$store->slug.'/bio?qr-id=ABC123');

        $res->assertStatus(200);
        $res->assertSeeHtml('data-bio-source="qr"');
        $view = BioClick::where('store_id', $store->id)->where('block_id', BioClick::VIEW_SENTINEL)->first();
        $this->assertSame('qr', $view->source);
    }

    public function test_signed_preview_bypasses_disabled_and_does_not_record(): void
    {
        $store = Store::factory()->create();
        // feature dark + settings off — only a signed preview may render it
        StoreOption::updateStoreOption($store->id, 'bio_settings', json_encode(['enabled' => false]));
        StoreOption::flushOptionMemo();

        $url = URL::signedRoute('bio.public', ['slug' => $store->slug, 'preview' => 1]);
        $res = $this->get($url);

        $res->assertStatus(200);
        $res->assertSeeHtml('data-bio-preset=');
        // preview must NOT inflate insights
        $this->assertSame(0, BioClick::where('store_id', $store->id)->count());
    }

    public function test_owner_viewing_own_bio_is_not_recorded(): void
    {
        $store = Store::factory()->create();
        $this->enableBio($store);

        $res = $this->actingAs($store->user)->get('/'.$store->slug.'/bio');

        $res->assertStatus(200);
        $this->assertSame(0, BioClick::where('store_id', $store->id)->count());
    }
}
