<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Models\StoreOption;
use App\Support\BioLinkOutbound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Tables\Services\Qr\QrRenderService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * BIOLINK-TPL lane-d (T2-1): the QR designer's new `bio` target.
 *
 * Regression guard first: a store whose bio page is dark/dead must see the QR
 * designer EXACTLY as today — no Bio-page-QR card, and a forged `target=bio`
 * download falls through to the menu QR byte-for-byte. Only a store with a live
 * bio page gets a distinct bio QR (pointing at `{slug}/bio?src=qr`).
 */
class BioLinkQrTargetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        config(['settings.admin_allow_qr_designer' => true]);

        // Canonical layout context (stdClass via ViewServiceProvider's idiom) — not a
        // hand-rolled double, so ViewComposerContractTest does not count this file.
        $this->shareLayoutGlobals();
    }

    /** @return array{0: User, 1: Store} */
    private function ownerRestaurant(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $store];
    }

    private function goLive(Store $store): void
    {
        config(['settings.admin_allow_biolink' => true]);
        EntitlementWriter::grant($store->user, 'biolink');
        StoreOption::updateStoreOption($store->id, 'bio_settings', json_encode([
            'enabled' => true, 'preset' => 'classic_flat', 'mode' => 'menu_first',
        ]));
        StoreOption::flushOptionMemo();
    }

    /* ------------------------------------------------------------- dark path */

    public function test_dark_bio_hides_the_bio_qr_card(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $this->withoutMiddleware();

        $this->actingAs($owner)->get(route('qr.designer').'?store_id='.$store->id)
            ->assertOk()->assertDontSee('Bio page QR code')->assertDontSeeHtml('target=bio');
    }

    public function test_dark_bio_target_falls_through_to_the_menu_qr(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $this->withoutMiddleware();

        // Forged `target=bio` on a dark store must render the SAME svg as the menu
        // QR — today's exact behavior, never a link to an unpublished bio page.
        $bio = $this->actingAs($owner)
            ->get(route('qr.designer.download').'?store_id='.$store->id.'&target=bio&format=svg')
            ->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->getContent();

        $menu = $this->actingAs($owner)
            ->get(route('qr.designer.download').'?store_id='.$store->id.'&format=svg')
            ->assertOk()->getContent();

        $this->assertSame($menu, $bio, 'A dark bio must fall back to the menu QR byte-for-byte.');
    }

    /* ------------------------------------------------------------- live path */

    public function test_live_bio_shows_the_bio_qr_card_and_download_links(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $this->goLive($store);
        $this->withoutMiddleware();

        $this->actingAs($owner)->get(route('qr.designer').'?store_id='.$store->id)
            ->assertOk()->assertSee('Bio page QR code')->assertSeeHtml('target=bio');
    }

    public function test_live_bio_target_renders_a_distinct_qr_from_the_menu(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $this->goLive($store);
        $this->withoutMiddleware();

        $bio = $this->actingAs($owner)
            ->get(route('qr.designer.download').'?store_id='.$store->id.'&target=bio&format=svg')
            ->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->getContent();

        $menu = $this->actingAs($owner)
            ->get(route('qr.designer.download').'?store_id='.$store->id.'&format=svg')
            ->assertOk()->getContent();

        // Distinct URLs ({slug}/bio?src=qr vs the menu) → distinct QR modules.
        $this->assertNotSame($menu, $bio, 'A live bio must produce its own QR, not the menu QR.');

        // And it encodes exactly the outbound bio URL the helper builds.
        $expected = BioLinkOutbound::url($store, 'qr');
        $this->assertSame(
            app(QrRenderService::class)->svg($expected, store_qr_settings($store->id)),
            $bio,
            'The bio QR must encode {slug}/bio?src=qr.'
        );
    }

    public function test_bio_qr_download_is_tenant_scoped(): void
    {
        [$owner] = $this->ownerRestaurant();
        [, $foreign] = $this->ownerRestaurant();
        $this->goLive($foreign);
        $this->withoutMiddleware();

        // Cannot render another owner's store QR at all — bio target included.
        $this->actingAs($owner)
            ->get(route('qr.designer.download').'?store_id='.$foreign->id.'&target=bio&format=svg')
            ->assertForbidden();
    }
}
