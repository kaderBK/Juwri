<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Tests\Modules\Platform\PlatformE2eTestCase;

/**
 * Platform E2E · B10 — the bio builder seam for owner creation and customization:
 * settings persistence, media uploads, block CRUD, preset application, and
 * public page rendering.
 *
 * The `platform/b-bio.js` journey:
 *   1. Owner logs in to dashboard
 *   2. Accesses bio settings page
 *   3. Sets display name and tagline
 *   4. Uploads avatar and background images via media library
 *   5. Navigates to bio builder
 *   6. Adds 3 blocks (link, menu, social)
 *   7. Selects and applies a preset
 *   8. Publishes the bio page
 *   9. Views the public bio page (/{slug}/bio)
 *
 * Discriminating tests:
 *   - Bio settings creation and persistence (enabled, preset, appearance JSON)
 *   - Media upload attachment to bio appearance (avatar, background paths)
 *   - Block CRUD: create, retrieve, reorder, toggle, destroy
 *   - Preset application and persistence in appearance
 *   - Public bio page accessible when enabled (no auth required)
 *   - Public bio page 404 when disabled
 *
 * LIVE CONTRACT (see Juwri\Modules\Biolink\Http\Controllers\User\BioLinkController,
 * Juwri\Modules\Biolink\Services\BioLinkService, Juwri\Modules\Biolink\Http\Controllers\User\BioPublicController):
 *   - `bio_settings` is a `store_options` JSON row, never a `posts` column
 *     (`Juwri\Modules\Biolink\Services\BioLinkService::resolveSettings()`/`saveSettings()`).
 *   - The dashboard routes (`biolink.*`) carry `feature:biolink`, a superadmin
 *     kill-switch that SHIPS DARK (`FeatureRegistry::entry('biolink', ..., false, true)`).
 *     Tests must flip `config(['settings.admin_allow_biolink' => true])` (the
 *     same idiom `BioLinkPublicPageTest`/`BioLinkGapFixesTest` use) or every
 *     request 403s regardless of the schema-drift fix.
 *   - Block writes/toggles respond 200 JSON only for an `Accept: application/json`
 *     request (`postJson`/`deleteJson`) — a plain form POST gets a 302 `back()`.
 *   - A block's visibility flag is `active` (boolean), not `visible`.
 *   - The public `{slug}/bio` page NEVER 404s a live slug: a disabled/dark store
 *     302-redirects to the storefront menu instead (SPEC A4). Only an unknown
 *     slug 404s.
 */
class PlatformE2eB10BioBuilderTest extends PlatformE2eTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $this->shareLayoutGlobals();

        // The `biolink` feature ships DARK (registry default OFF) — flip the
        // superadmin kill-switch so the `feature:biolink` dashboard gate (and
        // the public page's FeatureAccess::storeAllows check) are reachable.
        config(['settings.admin_allow_biolink' => true]);

        // Mock storage for media uploads
        Storage::fake('public');
    }

    /**
     * On top of the canonical fixture (PlatformE2eTestCase::ownerWithStore()),
     * grant `biolink` explicitly. Belt-and-suspenders (mirrors
     * BioLinkPublicPageTest): `biolink` currently ships plan_gated=false so the
     * kill-switch alone is enough, but granting the override keeps this test
     * correct even if the owner later flips per-plan selling on.
     *
     * @return array{0: User, 1: Store}
     */
    protected function ownerWithStore(string $storeType = 'restaurant', ?string $template = 'crave'): array
    {
        [$owner, $store] = parent::ownerWithStore($storeType, $template);

        EntitlementWriter::grant($owner, 'biolink');

        return [$owner, $store];
    }

    /** Resolved bio_settings straight from the live engine (never a posts column). */
    private function bioSettings(Store $store): array
    {
        StoreOption::flushOptionMemo();

        return app(BioLinkService::class)->resolveSettings($store->id);
    }

    /**
     * Test 1: Bio settings creation and retrieval.
     * Owner can create bio settings with display name, tagline, and preset.
     */
    public function test_bio_settings_creation_and_persistence(): void
    {
        [$owner, $store] = $this->ownerWithStore();

        // Act: POST to /biolink/settings with display name, tagline, preset
        $response = $this->actingAs($owner)
            ->post(route('biolink.settings.save', ['store_id' => $store->id]), [
                'enabled' => true,
                'preset' => 'classic_flat',
                'appearance' => [
                    'display_name' => 'Test Bio Name',
                    'tagline' => 'Bio tagline for testing',
                ],
            ]);

        $response->assertRedirect();

        // Assert: bio_settings is a store_options JSON row, resolved through the
        // live BioLinkService engine — never a `posts` column/attribute.
        $bioData = $this->bioSettings($store);

        $this->assertTrue($bioData['enabled'] ?? false, 'Bio should be enabled');
        $this->assertSame('classic_flat', $bioData['preset'] ?? null);
        $this->assertSame('Test Bio Name', $bioData['appearance']['display_name'] ?? null);
        $this->assertSame('Bio tagline for testing', $bioData['appearance']['tagline'] ?? null);
    }

    /**
     * Test 2: Media upload and attachment to bio appearance.
     * Owner can attach avatar/background image references (already-uploaded
     * media-library filenames) to the bio appearance sub-tree. `bg_image` is a
     * PRO-gated appearance key (App\Support\BioAppearance::sanitize) so this
     * also exercises the `bio_pro` kill-switch grant.
     */
    public function test_media_upload_attachment_to_bio_appearance(): void
    {
        [$owner, $store] = $this->ownerWithStore();

        // bg_image is stripped by BioAppearance::sanitize() unless the store's
        // plan/kill-switch grants `bio_pro` — flip it the same way `biolink` was
        // flipped above so the PRO appearance key actually persists.
        config(['settings.admin_allow_bio_pro' => true]);

        // Bare library filenames — the biolink settings endpoint only stores a
        // REFERENCE to an already-uploaded media-library file (BioAppearance::
        // filename() rejects any value containing a path separator); the actual
        // upload happens through the separate media-library endpoint.
        $avatarFile = 'avatar-test.png';
        $bgFile = 'bg-test.png';

        // Act: POST to /biolink/settings with media filenames
        $response = $this->actingAs($owner)
            ->post(route('biolink.settings.save', ['store_id' => $store->id]), [
                'enabled' => true,
                'appearance' => [
                    'avatar_image' => $avatarFile,
                    'bg_type' => 'image',
                    'bg_image' => $bgFile,
                ],
            ]);

        $response->assertRedirect();

        // Assert: bio appearance has the media filenames
        $bioData = $this->bioSettings($store);

        $this->assertSame($avatarFile, $bioData['appearance']['avatar_image'] ?? null);
        $this->assertSame($bgFile, $bioData['appearance']['bg_image'] ?? null);
        $this->assertSame('image', $bioData['appearance']['bg_type'] ?? null);
    }

    /**
     * Test 3: Block CRUD — create, retrieve, toggle, destroy.
     * Owner can create blocks (link, menu, social), toggle visibility (the
     * `active` flag), and delete them.
     */
    public function test_block_crud_operations(): void
    {
        [$owner, $store] = $this->ownerWithStore();

        // Act 1: Create a link block. The builder posts via fetch (JSON), which
        // is the branch that returns the created block payload as JSON rather
        // than a 302 `back()`.
        $response = $this->actingAs($owner)
            ->postJson(route('biolink.blocks.store', ['store_id' => $store->id]), [
                'type' => 'link',
                'label' => 'Test Link',
                'url' => 'https://example.com',
            ]);

        $response->assertOk();
        $blockId = (int) $response->json('block.id');
        $this->assertGreaterThan(0, $blockId);

        // Assert: block exists in DB
        $block = BioBlock::find($blockId);
        $this->assertNotNull($block);
        $this->assertSame('link', $block->type);
        $this->assertTrue($block->active, 'A new block defaults to active');

        // Act 2: Toggle block visibility (the `active` boolean, not `visible`).
        $response = $this->actingAs($owner)
            ->postJson(route('biolink.blocks.toggle', ['id' => $blockId, 'store_id' => $store->id]));

        $response->assertOk();

        // Assert: toggle flips active off
        $this->assertFalse($block->fresh()->active);

        // Act 3: Destroy block
        $response = $this->actingAs($owner)
            ->deleteJson(route('biolink.blocks.destroy', ['id' => $blockId, 'store_id' => $store->id]));

        $response->assertOk();

        // Assert: block is deleted
        $this->assertNull(BioBlock::find($blockId));
    }

    /**
     * Test 4: Preset application and persistence.
     * Owner can select a preset, and it persists in bio_settings. `majlis_gold`
     * is one of the 6 BASIC_PRESETS (BioLinkService::BASIC_PRESETS) so it needs
     * no PRO entitlement.
     */
    public function test_preset_application_and_persistence(): void
    {
        [$owner, $store] = $this->ownerWithStore();

        // Act: POST to /biolink/preset to apply preset
        $response = $this->actingAs($owner)
            ->postJson(route('biolink.preset.set', ['store_id' => $store->id]), [
                'preset' => 'majlis_gold',
            ]);

        $response->assertOk();

        // Assert: preset is persisted
        $bioData = $this->bioSettings($store);

        $this->assertSame('majlis_gold', $bioData['preset'] ?? null);
    }

    /**
     * Test 5: Public bio page access control — enabled vs disabled.
     * SPEC A4: the public bio route NEVER 404s a live slug — a disabled/dark
     * store 302-redirects to the storefront menu instead. Only an unknown slug
     * 404s (Juwri\Modules\Biolink\Http\Controllers\User\BioPublicController::show).
     */
    public function test_public_bio_page_access_control(): void
    {
        [$owner, $store] = $this->ownerWithStore();

        // Act 1: Enable and publish bio page
        $this->actingAs($owner)
            ->post(route('biolink.settings.save', ['store_id' => $store->id]), [
                'enabled' => true,
            ]);

        // Act 2: Access public bio page (guest)
        $response = $this->get(route('bio.public', ['slug' => $store->slug]));

        $response->assertOk();

        // Act 3: Disable bio page
        $this->actingAs($owner)
            ->post(route('biolink.settings.save', ['store_id' => $store->id]), [
                'enabled' => false,
            ]);

        // Act 4: Access public bio page again — 302 to the menu, never a 404.
        $response = $this->get(route('bio.public', ['slug' => $store->slug]));

        $response->assertRedirect();
        $this->assertStringContainsString($store->slug, (string) $response->headers->get('Location'));
        $this->assertStringNotContainsString('/bio', (string) $response->headers->get('Location'));
    }

    /**
     * Test 6: Bio page contains expected content when rendered.
     * Public bio page renders display name, tagline and blocks via the
     * `bio.layouts.list` family view (resources/views/bio/_profile.blade.php).
     */
    public function test_public_bio_page_renders_expected_content(): void
    {
        [$owner, $store] = $this->ownerWithStore();

        // Act: Enable bio and set appearance
        $this->actingAs($owner)
            ->post(route('biolink.settings.save', ['store_id' => $store->id]), [
                'enabled' => true,
                'preset' => 'classic_flat',
                'appearance' => [
                    'display_name' => 'Bio Test Name',
                    'tagline' => 'Test Tagline',
                ],
            ]);

        // Act: Add a block
        $this->actingAs($owner)
            ->postJson(route('biolink.blocks.store', ['store_id' => $store->id]), [
                'type' => 'link',
                'label' => 'Test Link Block',
                'url' => 'https://example.com',
            ]);

        // Act: Get public bio page. The rendered content asserted below does not
        // depend on whether the viewer is recognised as the store owner (that
        // only skips analytics recording), so no explicit logout is needed.
        $response = $this->get(route('bio.public', ['slug' => $store->slug]));

        $response->assertOk();

        // Assert: bio page contains expected content
        $response->assertSeeText('Bio Test Name');
        $response->assertSeeText('Test Tagline');
        $response->assertSeeText('Test Link Block');
    }
}
