<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\CateringRequest;
use App\Models\StoreOption;
use App\Services\Catering\CateringService;
use App\Services\Entitlements;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Feature;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * FIX-2 F2-6 (feature-platform gap audit): `catering` is enforced everywhere.
 *
 * The audit found `CateringService::createRequest()` guarded by the global
 * kill-switch plus the per-store `catering_enabled` toggle — the $1.99/mo SKU
 * bought nothing. The named SIDE DOOR is the public POST
 * `publicView.catering.request`: anonymous shoppers reach it, so `feature:`
 * middleware there would 403 every visitor (dashboard_owner() is null on a
 * signed-out request — the FIX-1 dead end). The subject is therefore the STORE
 * OWNER, checked inside the service and in the storefront partials.
 *
 * Entry points covered here:
 *   1. owner dashboard group  (`feature:catering`, routes/web.php)
 *   2. public POST /{slug}/catering → CateringService::createRequest
 *   3. CateringService::accept (mints an Order + optional deposit)
 *   4. storefront partials (shared `_partials/catering-form`, crave's own)
 *
 * States: unentitled → refused · entitled → 200 · grandfathered → 200 ·
 * killed → refused even when entitled · not plan_gated → old kill-only behavior.
 */
class FeaturePlatformFix2CateringTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'catering';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);

        $this->killSwitch(true);
        $this->planGate(true);
    }

    // ------------------------------------------------------------- fixtures

    private function killSwitch(bool $on): void
    {
        config(['settings.admin_allow_'.self::KEY => $on ? 1 : 0]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
    }

    private function planGate(bool $on): void
    {
        Feature::updateOrCreate(
            ['key' => self::KEY],
            ['label' => 'Catering Orders', 'plan_gated' => $on]
        );
        app()->forgetInstance('features.plan_gated');
        FeatureAccess::flush();
    }

    /** @return array{0:Store,1:User} a store with catering switched ON, and its owner. */
    private function store(bool $entitled = false, string $source = 'admin'): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(6), 'description' => 'T', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
        ]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'catering_enabled', '1');

        if ($entitled) {
            EntitlementWriter::grant($owner, self::KEY, $source, null, '1', null, 'test-fixture');
        }

        FeatureAccess::flush();

        return [$store, $owner];
    }

    private function payload(): array
    {
        return [
            'name' => 'Sam',
            'phone' => '123',
            'event_at' => now()->addDays(14)->toDateTimeString(),
            'headcount' => 40,
        ];
    }

    /** Render the shared storefront partial for a store. */
    private function renderPartial(Store $store, string $view): string
    {
        $options = (object) ['catering_enabled' => 1, 'catering_min_lead_days' => 0];

        // crave's partial dereferences $errors unguarded — the web middleware
        // group normally shares it; supply it so the render reaches the gate.
        return view($view, [
            'store' => $store,
            'postOptions' => $options,
            'errors' => new ViewErrorBag,
        ])->render();
    }

    // ----------------------------------------------------------- unentitled

    public function test_the_public_post_refuses_an_unentitled_store(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$store, $owner] = $this->store();

        $this->assertFalse(Entitlements::allows($owner->fresh(), self::KEY));

        $this->post('/'.$store->slug.'/catering', $this->payload());

        $this->assertSame(0, CateringRequest::count(), 'the public side door must not create a request');
    }

    public function test_the_service_refuses_an_unentitled_store(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$store] = $this->store();

        $this->expectException(ValidationException::class);
        new CateringService()->createRequest($store->id, $this->payload());
    }

    public function test_the_route_middleware_blocks_an_unentitled_owner(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [, $owner] = $this->store();
        Route::middleware(['web', 'feature:'.self::KEY])->get('/_t/catering', fn () => 'in');

        $res = $this->actingAs($owner)->get('/_t/catering');
        $this->assertContains(
            $res->getStatusCode(),
            [302, 403],
            'unentitled owner must be 403ed or redirected, got '.$res->getStatusCode()
        );
    }

    public function test_accept_refuses_an_unentitled_store(): void
    {
        [$store] = $this->store(entitled: true);
        $req = new CateringService()->createRequest($store->id, $this->payload());
        $req->update(['quote_total' => 500]);

        EntitlementOverride::query()->delete();
        FeatureAccess::flush();

        $this->expectException(ValidationException::class);
        new CateringService()->accept($req->fresh(), [['id' => 1, 'quantity' => 1]]);
    }

    public function test_the_storefront_form_is_hidden_for_an_unentitled_store(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$store] = $this->store();

        // crave used to carry its own drifted copy of this form; it now includes the
        // shared partial like the other 16, so there is one view left to assert.
        $view = 'store_templates._partials.catering-form';
        $this->assertStringNotContainsString(
            'id="catering"',
            $this->renderPartial($store, $view),
            $view.' must not advertise catering for an unentitled store'
        );
    }

    // ------------------------------------------------------------- entitled

    public function test_the_public_post_works_for_an_entitled_store(): void
    {
        [$store] = $this->store(entitled: true);

        $this->post('/'.$store->slug.'/catering', $this->payload())->assertRedirect();

        $this->assertSame(1, CateringRequest::where('store_id', $store->id)->count());
    }

    public function test_the_route_middleware_lets_an_entitled_owner_through(): void
    {
        [, $owner] = $this->store(entitled: true);
        Route::middleware(['web', 'feature:'.self::KEY])->get('/_t/catering-ok', fn () => 'in');

        $this->actingAs($owner)->get('/_t/catering-ok')->assertOk()->assertSee('in');
    }

    public function test_the_storefront_form_renders_for_an_entitled_store(): void
    {
        [$store] = $this->store(entitled: true);

        $view = 'store_templates._partials.catering-form';
        $this->assertStringContainsString(
            'id="catering"',
            $this->renderPartial($store, $view),
            $view.' must render for an entitled store'
        );
    }

    public function test_every_modern_theme_includes_the_shared_catering_form(): void
    {
        // The theme-local copy crave used to include had drifted behind the shared
        // one and, having no placement script, rendered under the fixed panes where
        // no diner could reach it. Keep the single source the only source.
        foreach (KeptTemplates::MODERN as $theme) {
            $index = resource_path('views/store_templates/'.$theme.'/index.blade.php');
            if (! is_file($index)) {
                continue;
            }
            $markup = (string) file_get_contents($index);
            if (! str_contains($markup, 'catering')) {
                continue;
            }
            $this->assertStringContainsString(
                '_partials.catering-form',
                $markup,
                $theme.' must include the shared catering form, not a theme-local copy'
            );
        }
    }

    // -------------------------------------------------------- grandfathered

    public function test_a_grandfathered_store_keeps_catering(): void
    {
        [$store, $owner] = $this->store(entitled: true, source: 'grandfather');

        $this->assertDatabaseHas('entitlement_overrides', [
            'user_id' => $owner->id, 'feature_key' => self::KEY, 'source_type' => 'grandfather',
        ]);

        $this->post('/'.$store->slug.'/catering', $this->payload())->assertRedirect();
        $this->assertSame(1, CateringRequest::where('store_id', $store->id)->count());
    }

    // --------------------------------------------------------------- killed

    public function test_the_kill_switch_beats_entitlement_on_the_public_post(): void
    {
        [$store] = $this->store(entitled: true);
        $this->killSwitch(false);

        $this->post('/'.$store->slug.'/catering', $this->payload());

        $this->assertSame(0, CateringRequest::count(), 'kill veto must win over a paid entitlement');
    }

    public function test_the_kill_switch_beats_entitlement_on_the_storefront_form(): void
    {
        [$store] = $this->store(entitled: true);
        $this->killSwitch(false);

        $this->assertStringNotContainsString(
            'id="catering"',
            $this->renderPartial($store, 'store_templates._partials.catering-form')
        );
    }

    public function test_the_route_middleware_403s_an_entitled_owner_while_killed(): void
    {
        [, $owner] = $this->store(entitled: true);
        Route::middleware(['web', 'feature:'.self::KEY])->get('/_t/catering-killed', fn () => 'in');
        $this->killSwitch(false);

        $this->actingAs($owner)->get('/_t/catering-killed')->assertForbidden();
    }

    // -------------------------------------------------------------- ungated

    public function test_an_ungated_key_keeps_kill_only_behavior(): void
    {
        $this->planGate(false);
        [$store] = $this->store();

        $this->post('/'.$store->slug.'/catering', $this->payload())->assertRedirect();
        $this->assertSame(1, CateringRequest::where('store_id', $store->id)->count());
        $this->assertStringContainsString(
            'id="catering"',
            $this->renderPartial($store, 'store_templates._partials.catering-form')
        );
    }
}
