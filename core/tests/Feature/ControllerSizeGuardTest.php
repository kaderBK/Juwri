<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Controllers\User\StoreController;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * WI-M15 — the regrowth guard for the god controllers.
 *
 * Two jobs: prove the extraction happened (the moved groups now live in traits)
 * and stop the NEXT 1,200-line method from shipping (a ceiling that only goes
 * DOWN). A 1,214-line submitOrder() (H15) and a 3,170-line backup engine (C3) both
 * grew inside these files unnoticed because nobody reads a 4,000-line file.
 */
class ControllerSizeGuardTest extends TestCase
{
    /**
     * M15 — a ceiling, never a target. These numbers may only ever go DOWN.
     *
     * Raising one is not a fix: if a controller needs more room, extract a
     * Concerns\ trait (the H11/M15 precedent) and lower the number. Headroom is
     * deliberate — four lanes push to main concurrently and several legitimately
     * add a handful of lines; the ceiling catches a NEW METHOD GROUP, not a
     * three-line fix.
     */
    private const CEILINGS = [
        'app/Http/Controllers/User/StoreController.php' => 4300,   // measured 4261 after the HandlesStorefrontQrScan extraction
        'app/Http/Controllers/Admin/UserController.php' => 4700,  // measured 4606 (C3 rewrote ~3,170 lines); drop once it shrinks
        'app/Http/Helpers/Helper.php' => 3380,                    // measured 3355 after the store-scoped money accessors (store_currency/store_tz/store_currency_pos/store_price_*) moved to StoreMoneyHelper.php (WI-M15-style split); composer autoload.files
    ];

    /** The eight routes whose method bodies M15 moved into traits. */
    private const MOVED_ROUTES = [
        ['name' => 'stores.qrDesigner', 'method' => 'qrBuilder'],
        ['name' => 'stores.qrSvg', 'method' => 'qrSvg'],
        ['name' => 'stores.menuExportCsv', 'method' => 'menuExportCsv'],
        ['name' => 'stores.menuImportCsv', 'method' => 'menuImportCsv'],
        ['name' => 'stores.tables', 'method' => 'tables'],
        ['name' => 'stores.updateTable', 'method' => 'updateTable'],
        ['name' => 'stores.deleteTable', 'method' => 'deleteTable'],
    ];

    public function test_the_god_controllers_stay_under_their_ceilings(): void
    {
        foreach (self::CEILINGS as $relative => $ceiling) {
            $path = base_path($relative);
            $this->assertFileExists($path);
            $lines = count(file($path));
            $this->assertLessThanOrEqual(
                $ceiling,
                $lines,
                "$relative is $lines lines (ceiling $ceiling). Do NOT raise this number — extract a "
                .'Concerns\\ trait (the H11/M15 precedent) and lower the ceiling instead.'
            );
        }
    }

    public function test_the_extracted_groups_live_in_traits(): void
    {
        foreach ([
            'app/Modules/Tables/Http/Controllers/User/Concerns/BuildsQrCodes.php',
            'app/Modules/Tables/Http/Controllers/User/Concerns/ManagesStoreTables.php',
            'app/Modules/Menu/Http/Controllers/User/Concerns/ImportsMenuCsv.php',
        ] as $trait) {
            $this->assertFileExists(base_path($trait), "$trait must exist after the M15 extraction.");
        }

        $controller = file_get_contents(base_path('app/Http/Controllers/User/StoreController.php'));
        foreach (['function qrSvg(', 'function updateTable(', 'function menuImportCsv('] as $moved) {
            $this->assertStringNotContainsString(
                $moved,
                $controller,
                "StoreController must no longer define [$moved] — it moved to a Concerns\\ trait."
            );
        }
    }

    public function test_every_moved_route_still_resolves_to_the_controller(): void
    {
        foreach (self::MOVED_ROUTES as $r) {
            $route = Route::getRoutes()->getByName($r['name']);
            $this->assertNotNull($route, "Route [{$r['name']}] must still be registered.");
            $this->assertSame(
                StoreController::class.'@'.$r['method'],
                $route->getActionName(),
                "Route [{$r['name']}] must resolve to StoreController@{$r['method']}."
            );
            $this->assertTrue(
                method_exists(StoreController::class, $r['method']),
                "StoreController must expose [{$r['method']}] (via its Concerns\\ trait)."
            );
        }

        // The unnamed POST stores/{store}/qr-designer (qrBuilderSave).
        $save = collect(Route::getRoutes()->getRoutes())->first(
            fn ($route) => $route->uri() === 'stores/{store}/qr-designer'
                && in_array('POST', $route->methods(), true)
        );
        $this->assertNotNull($save, 'The POST qr-designer route must still be registered.');
        $this->assertSame(StoreController::class.'@qrBuilderSave', $save->getActionName());
        $this->assertTrue(method_exists(StoreController::class, 'qrBuilderSave'));
    }
}
