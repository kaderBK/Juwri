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
 * BIOLINK-TPL T1-2 (A3): the starter-template apply endpoint + BioLinkService::
 * applyStarter, through the REAL service (no stub) so the seed, the tenant/entitlement
 * guards, the replace path and the hidden-when-missing rule are all proven end to end.
 */
class BioLinkTemplateApplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        // Feature ships DARK; simulate the superadmin kill-switch ON so the
        // feature:biolink dashboard gate is reachable (BioLinkDashboardTest idiom).
        config(['settings.admin_allow_biolink' => true]);
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
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

    private function svc(): BioLinkService
    {
        return app(BioLinkService::class);
    }

    public function test_owner_applies_a_starter_template_seeding_blocks_and_settings(): void
    {
        [$owner, $store] = $this->store();

        $res = $this->actingAs($owner)->postJson(route('biolink.template.apply'), [
            'preset' => 'classic_flat',
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertGreaterThan(0, $res->json('created'));

        // Real main-page blocks were seeded, all branch_id = NULL.
        $blocks = BioBlock::where('store_id', $store->id)->get();
        $this->assertGreaterThan(0, $blocks->count());
        $this->assertTrue($blocks->every(fn ($b) => $b->branch_id === null));

        // Preset + the restaurant_essential sections map persisted; enabled/mode untouched.
        $settings = $this->svc()->resolveSettings($store->id);
        $this->assertSame('classic_flat', $settings['preset']);
        $this->assertTrue($settings['sections']['whatsapp']);   // from restaurant_essential
        $this->assertFalse($settings['enabled']);               // never auto-published
        $this->assertSame('menu_first', $settings['mode']);
    }

    public function test_starter_blocks_are_seeded_active_and_visible(): void
    {
        [$owner, $store] = $this->store();

        $this->actingAs($owner)->postJson(route('biolink.template.apply'), ['preset' => 'classic_flat'])->assertOk();

        $byType = BioBlock::where('store_id', $store->id)->get()->keyBy('type');

        // Choosing a design shows its FULL layout: every seeded block is active so the
        // owner sees the whole page in the builder, ready to edit or turn off — none
        // are silently hidden even when the store has no reservations/reviews yet.
        $this->assertTrue((bool) $byType['menu']->active);
        $this->assertTrue((bool) $byType['reservation']->active);
        $this->assertTrue((bool) $byType['reviews']->active);
        $this->assertSame(0, BioBlock::where('store_id', $store->id)->where('active', false)->count());

        // Pre-fill: the menu block carries the storefront URL (src=bio added at render).
        $this->assertStringContainsString($store->slug, (string) $byType['menu']->url);

        // The seeded gift-card link never persists the internal prefill marker.
        $gift = BioBlock::where('store_id', $store->id)->where('type', 'link')->first();
        $this->assertNotNull($gift);
        $this->assertArrayNotHasKey('prefill', (array) $gift->config);
    }

    public function test_set_preset_changes_only_the_skin_and_keeps_blocks(): void
    {
        [$owner, $store] = $this->store();
        BioBlock::factory()->create(['store_id' => $store->id, 'label' => 'Keep me']);
        $before = BioBlock::where('store_id', $store->id)->count();

        // The live design switcher applies a new skin without reseeding or hiding blocks.
        $this->actingAs($owner)->postJson(route('biolink.preset.set'), ['preset' => 'midnight_glass'])
            ->assertOk()->assertJson(['success' => true, 'preset' => 'midnight_glass']);

        $this->assertSame('midnight_glass', $this->svc()->resolveSettings($store->id)['preset']);
        $this->assertSame($before, BioBlock::where('store_id', $store->id)->count(), 'a skin change never touches blocks');
    }

    public function test_apply_over_existing_blocks_without_replace_is_422(): void
    {
        [$owner, $store] = $this->store();
        BioBlock::factory()->create(['store_id' => $store->id, 'label' => 'Keep me']);

        $this->actingAs($owner)->postJson(route('biolink.template.apply'), ['preset' => 'classic_flat'])
            ->assertStatus(422);

        // Nothing new was seeded; the original block survives.
        $this->assertSame(1, BioBlock::where('store_id', $store->id)->count());
        $this->assertNotNull(BioBlock::where('store_id', $store->id)->where('label', 'Keep me')->first());
    }

    public function test_apply_with_replace_clears_and_reseeds(): void
    {
        [$owner, $store] = $this->store();
        BioBlock::factory()->create(['store_id' => $store->id, 'label' => 'Old one']);

        $this->actingAs($owner)->postJson(route('biolink.template.apply'), [
            'preset' => 'classic_flat', 'replace' => '1',
        ])->assertOk();

        $this->assertNull(BioBlock::where('store_id', $store->id)->where('label', 'Old one')->first());
        $this->assertGreaterThan(0, BioBlock::where('store_id', $store->id)->count());
    }

    public function test_a_plan_locked_pro_preset_is_403(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$owner, $store] = $this->store();

        // A bare store is basic-tier → a pro preset is not entitled.
        $this->actingAs($owner)->postJson(route('biolink.template.apply'), ['preset' => 'neon_arcade'])
            ->assertStatus(403);

        $this->assertSame(0, BioBlock::where('store_id', $store->id)->count());
    }

    public function test_idor_cannot_apply_to_another_owners_store(): void
    {
        [$ownerA] = $this->store();
        [, $postB] = $this->store();

        // Owner A points store_id at owner B's store → 403 before any seed.
        $this->actingAs($ownerA)->postJson(route('biolink.template.apply', ['store_id' => $postB->id]), [
            'preset' => 'classic_flat',
        ])->assertStatus(403);

        $this->assertSame(0, BioBlock::where('store_id', $postB->id)->count());
    }

    public function test_apply_never_publishes_or_changes_mode(): void
    {
        [$owner, $store] = $this->store();
        $this->svc()->saveSettings($store->id, ['enabled' => true, 'mode' => 'bio_first']);

        $this->actingAs($owner)->postJson(route('biolink.template.apply'), [
            'preset' => 'classic_flat', 'replace' => '1',
        ])->assertOk();

        $settings = $this->svc()->resolveSettings($store->id);
        $this->assertTrue($settings['enabled']);        // still published as the owner left it
        $this->assertSame('bio_first', $settings['mode']);
    }

    public function test_unknown_preset_falls_back_to_restaurant_essential_archetype(): void
    {
        [$owner, $store] = $this->store();

        // A basic-tier stored slug that has no json still resolves an archetype.
        $created = $this->svc()->applyStarter($store->id, 'classic_flat', false);
        $this->assertGreaterThan(0, $created);

        // Direct: an entirely unknown slug maps to restaurant_essential.
        $this->assertSame('restaurant_essential', $this->svc()->archetypeForPreset('does_not_exist'));
    }
}
