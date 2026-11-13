<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Tests\TestCase;

/**
 * bio-t15 gap-fix pass (autonomous review): pins the fixes for the gaps a
 * 4-lane parallel build left — a stored-XSS scheme hole, a gallery block that
 * could never persist a photo, "Show X" toggles that could never be turned off,
 * a seasonal end-date that truncated its own last day, and the unwired UTM
 * auto-tagging. Each test fails on the pre-fix code.
 */
class BioLinkGapFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        FeatureRegistry::clearCache(); // dark keys must read the fully-migrated table
    }

    private function store(): Store
    {
        $store = Store::factory()->create();
        config(['settings.admin_allow_biolink' => true]);
        EntitlementWriter::grant($store->user, 'biolink');
        StoreOption::updateStoreOption($store->id, 'bio_settings', json_encode([
            'enabled' => true, 'preset' => 'classic_flat', 'mode' => 'menu_first',
        ]));
        StoreOption::flushOptionMemo();

        return $store;
    }

    private function postBlock(Store $store, array $payload): TestResponse
    {
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);

        return $this->actingAs($store->user)->postJson(route('biolink.blocks.store'), $payload);
    }

    /** S1: a `javascript:` URL must never survive to a public href. */
    public function test_javascript_scheme_url_is_stripped_on_write(): void
    {
        $store = $this->store();

        $this->postBlock($store, ['type' => 'link', 'label' => 'Evil', 'url' => "javascript:fetch('//evil')"])
            ->assertOk();

        $block = BioBlock::where('store_id', $store->id)->latest('id')->first();
        $this->assertNotNull($block);
        $this->assertNull($block->url, 'a javascript: url is dropped, not stored');

        $html = $this->get('/'.$store->slug.'/bio')->assertOk()->getContent();
        $this->assertStringNotContainsString('javascript:', $html);
    }

    /** S1: a `javascript:` pair (delivery) is dropped; a real https one survives. */
    public function test_javascript_scheme_in_a_pair_is_dropped(): void
    {
        $store = $this->store();

        $this->postBlock($store, [
            'type' => 'delivery', 'label' => 'Order',
            'config_pairs' => "Evil | javascript:alert(1)\nUber Eats | https://ubereats.example/x",
        ])->assertOk();

        $block = BioBlock::where('store_id', $store->id)->latest('id')->first();
        $apps = $block->config['apps'] ?? [];
        $this->assertCount(1, $apps, 'only the safe https pair survives');
        $this->assertSame('https://ubereats.example/x', $apps[0]['url']);
    }

    /** F1: a gallery block persists its photos from the pairs textarea. */
    public function test_gallery_block_persists_its_photos(): void
    {
        $store = $this->store();

        $this->postBlock($store, [
            'type' => 'gallery', 'label' => 'Our food',
            'config_pairs' => "Patio | https://cdn.example/patio.jpg\nPlate | https://cdn.example/plate.jpg",
        ])->assertOk();

        $block = BioBlock::where('store_id', $store->id)->latest('id')->first();
        $images = $block->config['images'] ?? [];
        $this->assertCount(2, $images, 'gallery images persisted (was silently dropped pre-fix)');
        $this->assertSame('https://cdn.example/patio.jpg', $images[0]['image']);
        $this->assertSame('Patio', $images[0]['caption']);

        $html = $this->get('/'.$store->slug.'/bio')->assertOk()->getContent();
        $this->assertStringContainsString('patio.jpg', $html);
    }

    /** F2: an unchecked "Show call button" (submits 0) actually hides the button. */
    public function test_a_call_channel_toggled_off_is_hidden_on_the_page(): void
    {
        $store = $this->store();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'call', 'active' => true,
            'config' => ['phone' => '111', 'whatsapp' => '222', 'show' => ['call' => '0', 'whatsapp' => '1', 'sms' => '0']],
        ]);

        $html = $this->get('/'.$store->slug.'/bio')->assertOk()->getContent();

        $this->assertStringNotContainsString('bio-call-btn--call', $html, 'call button hidden when show.call=0');
        $this->assertStringNotContainsString('bio-call-btn--sms', $html, 'sms button hidden when show.sms=0');
        $this->assertStringContainsString('bio-call-btn--whatsapp', $html, 'whatsapp still shows when show.whatsapp=1');
    }

    /** F3: a bare `end_at` date keeps the block visible through the END of that day. */
    public function test_seasonal_end_date_stays_visible_through_its_last_day(): void
    {
        $store = $this->store();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'link', 'label' => 'Summer', 'url' => 'https://x.example',
            'active' => true, 'config' => ['schedule' => ['end_at' => '2026-08-30']],
        ]);
        $svc = app(BioLinkService::class);

        $this->travelTo('2026-08-30 23:59:00', function () use ($svc, $store) {
            $rows = $svc->blocksFor($store->id);
            $this->assertNotEmpty(array_filter($rows, fn ($r) => $r['label'] === 'Summer'), 'visible at 23:59 on the last day');
        });
        $this->travelTo('2026-08-31 00:00:01', function () use ($svc, $store) {
            $rows = $svc->blocksFor($store->id);
            $this->assertEmpty(array_filter($rows, fn ($r) => $r['label'] === 'Summer'), 'hidden the day after');
        });
    }

    /** D3: an outbound link gets UTM; a tel: link and same-store link do not. */
    public function test_outbound_links_are_utm_tagged_on_the_public_render(): void
    {
        $store = $this->store();
        BioBlock::factory()->create(['store_id' => $store->id, 'type' => 'link', 'label' => 'IG', 'url' => 'https://instagram.example/acme', 'active' => true]);
        $svc = app(BioLinkService::class);

        $rows = $svc->blocksFor($store->id, null, false, 'social');
        $ig = collect($rows)->firstWhere('label', 'IG');
        $this->assertStringContainsString('utm_source=bio', (string) $ig['url']);
        $this->assertStringContainsString('utm_medium=social', (string) $ig['url']);

        // No source (builder/read path) → never tagged.
        $editor = $svc->blocksFor($store->id);
        $this->assertStringNotContainsString('utm_source', (string) collect($editor)->firstWhere('label', 'IG')['url']);
    }

    /** D3: a real tel: link is never UTM-tagged (withUtm skips it). */
    public function test_utm_skips_tel_scheme(): void
    {
        $store = $this->store();
        BioBlock::factory()->create(['store_id' => $store->id, 'type' => 'link', 'label' => 'Call', 'url' => 'tel:+15551234567', 'active' => true]);
        $svc = app(BioLinkService::class);

        $rows = $svc->blocksFor($store->id, null, false, 'direct');
        $this->assertSame('tel:+15551234567', collect($rows)->firstWhere('label', 'Call')['url']);
    }

    /** F0 wire + §8: PRO appearance is stripped at the real save route for a non-entitled store. */
    public function test_pro_appearance_is_stripped_at_the_save_route(): void
    {
        $store = $this->store(); // biolink enabled, bio_pro NOT enabled
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);

        $this->actingAs($store->user)->post(route('biolink.settings.save'), [
            'enabled' => '1',
            'appearance' => [
                'accent' => '#123456',            // free
                'bg_type' => 'image', 'bg_image' => 'evil.jpg', // PRO
            ],
        ])->assertRedirect();

        $ap = app(BioLinkService::class)->resolveSettings($store->id)['appearance'] ?? [];
        $this->assertSame('#123456', $ap['accent'] ?? null, 'a free appearance key persists');
        $this->assertNull($ap['bg_image'] ?? null, 'image background is PRO → stripped server-side at save');
        $this->assertNotSame('image', $ap['bg_type'] ?? null, 'the PRO bg_type is not persisted either');
    }

    /** Pass-2: giftcard/loyalty/event/tipjar read the shared editor field's top-level `url` column. */
    public function test_giftcard_custom_link_renders_from_the_url_column(): void
    {
        $store = $this->store();
        BioBlock::factory()->create(['store_id' => $store->id, 'type' => 'giftcard', 'active' => true,
            'url' => 'https://external.example/giftcards', 'config' => ['mode' => 'url']]);

        $html = $this->get('/'.$store->slug.'/bio')->assertOk()->getContent();
        $this->assertStringContainsString('external.example/giftcards', $html, 'giftcard custom link renders (was dead — read only config.url)');
    }

    /** Pass-2: an entitled tipjar block renders when its link sits on the top-level `url` column. */
    public function test_tipjar_renders_from_the_url_column_when_entitled(): void
    {
        config(['settings.admin_allow_bio_pro' => true]);
        $store = $this->store();
        BioBlock::factory()->create(['store_id' => $store->id, 'type' => 'tipjar', 'active' => true,
            'url' => 'https://pay.example/tip', 'config' => ['mode' => 'url']]);

        $html = $this->get('/'.$store->slug.'/bio')->assertOk()->getContent();
        $this->assertStringContainsString('pay.example/tip', $html, 'tipjar renders (whole block was dead when url was on the column)');
    }

    /** Pass-2: a socials block renders its icons from config.socials {name,url}. */
    public function test_socials_block_renders_from_config_socials(): void
    {
        $store = $this->store();
        BioBlock::factory()->create(['store_id' => $store->id, 'type' => 'socials', 'active' => true,
            'config' => ['socials' => [['name' => 'Instagram', 'url' => 'https://instagram.example/acme']]]]);

        $html = $this->get('/'.$store->slug.'/bio')->assertOk()->getContent();
        $this->assertStringContainsString('bio-soc--instagram', $html, 'socials renders from config.socials (was reading config.links → always empty)');
        $this->assertStringContainsString('instagram.example/acme', $html);
    }
}
