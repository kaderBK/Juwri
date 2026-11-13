<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Tests\TestCase;

/**
 * BIOLINK-RUN A2: feature gating — registry entry ships dark, preset tier logic,
 * bio_only availability, and the bio_blocks limit all resolve off the store's plan
 * entitlements (EntitlementWriter grants, Entitlements resolves).
 */
class BioLinkGatingTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): BioLinkService
    {
        return app(BioLinkService::class);
    }

    public function test_registry_entry_ships_dark(): void
    {
        $keyed = collect(FeatureRegistry::all())->keyBy('key');

        $this->assertTrue($keyed->has('biolink'), 'biolink registry entry present');
        $this->assertFalse($keyed['biolink']['default'], 'kill-switch OFF at merge (dark)');
        $this->assertTrue($keyed['biolink']['shipped'], 'shipped=true at F0 (Lane B gate merged + audit case added) so superadmin can flip it');

        // dark: no admin toggle set → isEnabled false → storeAllows false everywhere
        $this->assertFalse(FeatureRegistry::isEnabled('biolink'));

        // shipped=true, so FeatureGateAuditTest carries its gate case (added at F0)
        $shipped = array_map(fn ($e) => $e['key'], FeatureRegistry::shipped());
        $this->assertContains('biolink', $shipped);
    }

    public function test_preset_tier_basic_by_default_all_when_plan_grants(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        $store = Store::factory()->create();

        // dark / basic tier: pro presets locked, basics always open
        $this->assertTrue($this->svc()->presetAllowed($store->id, 'classic_flat'));
        $this->assertFalse($this->svc()->presetAllowed($store->id, 'neon_arcade'));

        EntitlementWriter::grant($store->user, 'bio_presets_all');

        $this->assertTrue($this->svc()->presetAllowed($store->id, 'neon_arcade'), 'pro preset unlocks with plan grant');
        // basics still allowed
        $this->assertTrue($this->svc()->presetAllowed($store->id, 'majlis_gold'));
    }

    public function test_bio_only_allowed_reflects_allow_onlybio_entitlement(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        $store = Store::factory()->create();

        $this->assertFalse($this->svc()->bioOnlyAllowed($store->id));

        EntitlementWriter::grant($store->user, 'allow_onlybio');

        $this->assertTrue($this->svc()->bioOnlyAllowed($store->id));
    }

    public function test_block_limit_delegates_to_effective_limit(): void
    {
        $store = Store::factory()->create();

        $this->assertSame(
            effective_limit('bio_blocks', $store->user),
            $this->svc()->blockLimit($store->id),
        );
    }
}
