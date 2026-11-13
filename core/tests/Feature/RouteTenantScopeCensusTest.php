<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Juwri\Modules\Menu\Http\Controllers\User\TranslationReviewController;
use Tests\Support\CensusProbeController;
use Tests\Support\EditionTestHooks;
use Tests\Support\RouteTenantScopeCensus;
use Tests\TestCase;

/**
 * WI-R2: the route-scoping census (the IDOR-exposure claim was REFUTED). This makes
 * a NEW model-bound authenticated dashboard route that is not in the manifest fail
 * CI, so scoping route #96 is a conscious decision instead of an accident. No DB.
 */
class RouteTenantScopeCensusTest extends TestCase
{
    /** @return array<string,string> */
    private function manifest(): array
    {
        // Community's rows + the installed edition packages' own (their routes only exist with them).
        return array_merge(require base_path('tests/Support/route-tenant-scope-manifest.php'), EditionTestHooks::routeScopeManifest());
    }

    private function population(): array
    {
        // Exclude the anti-vacuity probe route if another test registered it.
        return array_filter(
            RouteTenantScopeCensus::population(),
            fn ($k) => ! str_starts_with($k, '__census.'),
            ARRAY_FILTER_USE_KEY
        );
    }

    public function test_every_model_bound_dashboard_route_is_in_the_manifest(): void
    {
        $missing = array_keys(array_diff_key($this->population(), $this->manifest()));

        $this->assertSame([], $missing, implode("\n", array_merge(
            ['A model-bound Eloquent parameter on an authenticated dashboard route is NOT in'],
            ['tests/Support/route-tenant-scope-manifest.php. Confirm the controller scopes the bound'],
            ['model to the acting owner/staff/reseller, then add the route with its mechanism. If it'],
            ['does NOT scope, that is a cross-tenant IDOR — fix the controller, do not add the row.'],
            $missing
        )));
    }

    public function test_the_manifest_has_no_stale_entries(): void
    {
        $stale = array_keys(array_diff_key($this->manifest(), $this->population()));

        $this->assertSame([], $stale, 'The manifest names routes that no longer exist: '.implode(', ', $stale));
    }

    public function test_every_mechanism_is_from_the_closed_set(): void
    {
        $allowed = ['controller-scope', 'staff-guard', 'reseller-tenant', 'owner-of-record', 'tenant.owns', 'UNSCOPED-CONFIRMED'];
        foreach ($this->manifest() as $route => $mechanism) {
            $this->assertContains($mechanism, $allowed, "Route [{$route}] has an unknown mechanism [{$mechanism}].");
        }
    }

    public function test_controller_scope_entries_still_show_scoping(): void
    {
        $population = $this->population();
        $regressed = [];
        foreach ($this->manifest() as $route => $mechanism) {
            if ($mechanism !== 'controller-scope' || ! isset($population[$route])) {
                continue;
            }
            [$class, $method] = explode('@', $population[$route]['action']);
            if (RouteTenantScopeCensus::scopeEvidence($class, $method) === null) {
                $regressed[] = $route.' ('.$population[$route]['action'].')';
            }
        }

        $this->assertSame([], $regressed, "A controller-scope route lost its scoping marker (a scope was deleted):\n".implode("\n", $regressed));
    }

    public function test_scope_evidence_follows_one_hop_into_a_private_helper(): void
    {
        // TranslationReviewController::index() scopes via a private owned() helper
        // one call away — proves the one-hop resolution works so the ~16 indirectly
        // scoped routes are not false-flagged.
        $this->assertNotNull(
            RouteTenantScopeCensus::scopeEvidence(TranslationReviewController::class, 'index')
        );
    }

    public function test_the_census_reports_a_deliberately_unscoped_route(): void
    {
        Route::middleware(['auth', 'staff.dashboard'])
            ->post('__census_probe/{store}', [CensusProbeController::class, 'update'])
            ->name('__census.probe');

        $population = RouteTenantScopeCensus::population();

        $this->assertArrayHasKey('__census.probe', $population);
        $this->assertNull(RouteTenantScopeCensus::scopeEvidence(CensusProbeController::class, 'update'));
    }
}
