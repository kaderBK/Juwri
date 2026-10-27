<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Models\BioClick;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Tests\TestCase;

/**
 * BIOLINK F0-2 — end-to-end integration across ALL THREE lanes on the REAL merged
 * stack (no service stub): Lane B's dashboard controller writes through Lane A's real
 * BioLinkService into the DB, Lane C's real views render it, the public click beacon
 * records into bio_clicks, bio:aggregate reconciles, and insights reads it back.
 *
 * The full §5 interstitial bypass matrix is exhaustively proven on the real publicView
 * hook by {@see BioLinkInterstitialTest}; here we smoke the bio_first redirect only.
 */
class BioLinkIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $this->shareLayoutGlobals(['currency_sign' => '$', 'currency_pos' => 1]);

        // Feature ships DARK; simulate the superadmin kill-switch ON so the gate is reachable.
        config(['settings.admin_allow_biolink' => true]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    /** @return array{0:User,1:Store} */
    private function makeOwnerPost(): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now(), 'user_type' => 'user']);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);
        EntitlementWriter::grant($owner, 'biolink');

        return [$owner, $store];
    }

    public function test_owner_builds_a_bio_and_a_guest_click_reaches_insights(): void
    {
        [$owner, $store] = $this->makeOwnerPost();

        // 1) Owner enables + picks a preset through the REAL dashboard controller+service.
        $this->actingAs($owner)->post(route('biolink.settings.save'), [
            'enabled' => '1', 'preset' => 'classic_flat', 'mode' => 'menu_first',
        ])->assertRedirect();

        // 2) Owner adds a block through the builder endpoint (B controller -> A service -> DB).
        $this->actingAs($owner)->postJson(route('biolink.blocks.store'), [
            'type' => 'link', 'label' => 'Order now', 'url' => 'https://x.example', 'active' => '1',
        ])->assertOk()->assertJson(['success' => true]);

        $block = BioBlock::where('store_id', $store->id)->firstOrFail();
        $this->assertSame('Order now', $block->label);

        // Drop the owner session — the rest of the flow is a real anonymous guest
        // (recordView deliberately skips a logged-in owner/preview, SPEC §4).
        auth('web')->logout();
        $this->app['auth']->forgetGuards();

        // 3) A guest hits the public page rendered by the REAL Lane C views.
        $res = $this->get('/'.$store->slug.'/bio');
        $res->assertStatus(200)->assertSeeHtml('data-bio-preset="classic_flat"')->assertSeeHtml('data-bio-blocks="1"')
            ->assertSee('Order now');

        // G1: the page load recorded exactly one page-view row at the sentinel block_id.
        $this->assertSame(1, (int) BioClick::where('store_id', $store->id)
            ->where('block_id', BioClick::VIEW_SENTINEL)->sum('count'));

        // 4) The guest taps the block — the beacon records a click (204, never 404).
        $this->post('/'.$store->slug.'/bio/c/'.$block->id, ['source' => 'social'])->assertNoContent();
        $this->assertGreaterThanOrEqual(1, (int) BioClick::where('store_id', $store->id)
            ->where('block_id', $block->id)->sum('count'));

        // 5) Aggregate reconciles the denormalised clicks_cached (G8).
        $this->artisan('bio:aggregate')->assertExitCode(0);
        $this->assertGreaterThanOrEqual(1, (int) $block->fresh()->clicks_cached);

        // 6) Insights reads the real stats back and the page renders.
        $this->actingAs($owner)->get(route('biolink.insights'))->assertStatus(200);
        $stats = app(BioLinkService::class)->statsFor($store->id, '30d');
        $this->assertGreaterThanOrEqual(1, (int) $stats['clicks']);
        $this->assertGreaterThanOrEqual(1, (int) $stats['views']);
    }

    public function test_guest_capture_lands_a_consented_subscriber(): void
    {
        [$owner, $store] = $this->makeOwnerPost();
        StoreOption::updateStoreOption($store->id, 'bio_settings', json_encode(['enabled' => true, 'preset' => 'classic_flat']));
        StoreOption::flushOptionMemo();

        // Honeypot field left empty; a real email is submitted.
        $this->postJson("/{$store->slug}/bio/subscribe", ['email' => 'diner@example.com', 'name' => 'Diner', 'company' => ''])
            ->assertOk();

        $c = Customer::where('store_id', $store->id)->where('email', 'diner@example.com')->first();
        $this->assertNotNull($c, 'capture lands a Customer on the store rail');
        $this->assertTrue((bool) $c->marketing_consent);
    }

    public function test_locale_prefixed_bio_renders_translated_block(): void
    {
        [$owner, $store] = $this->makeOwnerPost();
        StoreOption::updateStoreOption($store->id, 'bio_settings', json_encode(['enabled' => true, 'preset' => 'classic_flat']));
        StoreOption::flushOptionMemo();

        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'announcement', 'label' => 'Open now',
            'config' => ['text' => 'We are open', '_i18n' => ['ar' => ['text' => 'نحن مفتوحون']]],
        ]);

        // Arabic locale resolves the ar override + renders RTL.
        $ar = $this->withSession(['locale' => 'ar'])->get("/{$store->slug}/bio");
        $ar->assertStatus(200)->assertSee('نحن مفتوحون')->assertSeeHtml('dir="rtl"');

        // Default locale falls back to the base string.
        $this->withSession(['locale' => 'en'])->get('/'.$store->slug.'/bio')
            ->assertStatus(200)->assertSee('We are open');
    }

    public function test_bio_first_redirects_the_first_guest_hit(): void
    {
        [$owner, $store] = $this->makeOwnerPost();
        StoreOption::updateStoreOption($store->id, 'bio_settings', json_encode(['enabled' => true, 'preset' => 'classic_flat', 'mode' => 'bio_first']));
        StoreOption::flushOptionMemo();

        // First plain hit is intercepted to the bio page (full bypass matrix: BioLinkInterstitialTest).
        $res = $this->get('/'.$store->slug);
        $res->assertRedirect();
        $this->assertStringContainsString('/bio', $res->headers->get('Location'));
    }
}
